<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

class CalendarService {
    public function __construct(
        private readonly CalDavBackend $calDavBackend,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Calendar lifecycle
    // -------------------------------------------------------------------------

    /**
     * Ensure a relay calendar exists for the given service user.
     * Creates it if absent, returns the existing calendar ID otherwise.
     */
    public function ensureCalendarExists(
        string $serviceUser,
        string $calendarName,
        string $color,
    ): int {
        $principal = "principals/users/{$serviceUser}";

        // Check existing calendars
        foreach ($this->calDavBackend->getCalendarsForUser($principal) as $cal) {
            if (($cal['{DAV:}displayname'] ?? '') === $calendarName) {
                return (int)$cal['id'];
            }
        }

        // Create new relay calendar
        $calendarId = $this->calDavBackend->createCalendar($principal, $this->generateUri($calendarName), [
            '{DAV:}displayname'                         => $calendarName,
            '{http://apple.com/ns/ical/}calendar-color' => $color,
            '{http://calendarserver.org/ns/}getctag'    => '',
        ]);

        $this->logger->info('CalendarBridge: created relay calendar', [
            'serviceUser'  => $serviceUser,
            'calendarName' => $calendarName,
            'calendarId'   => $calendarId,
        ]);

        return (int)$calendarId;
    }

    /**
     * Delete a relay calendar and all its objects.
     */
    public function deleteCalendar(int $calendarId): void {
        try {
            $this->calDavBackend->deleteCalendar($calendarId, true);
        } catch (\Exception $e) {
            $this->logger->warning('CalendarBridge: failed to delete calendar', [
                'calendarId' => $calendarId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Event synchronisation (diff-based)
    // -------------------------------------------------------------------------

    /**
     * Sync VEVENTs from an external feed into the relay calendar.
     *
     * @return array{added: int, updated: int, deleted: int}
     */
    public function syncEvents(int $calendarId, VCalendar $calendar): array {
        $added   = 0;
        $updated = 0;
        $deleted = 0;

        // Index existing objects by UID
        $existing = [];
        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $obj) {
            $uid = $this->extractUid($obj['calendardata'] ?? '');
            if ($uid !== null) {
                $existing[$uid] = $obj;
            }
        }

        // Process incoming VEVENTs
        $seen = [];
        foreach ($calendar->VEVENT ?? [] as $event) {
            $uid = (string)($event->UID ?? '');
            if ($uid === '') {
                continue;
            }
            $seen[$uid] = true;

            // Wrap single VEVENT in a minimal VCALENDAR for storage
            $icsData = $this->wrapEvent($calendar, $event);

            if (!isset($existing[$uid])) {
                $uri = $uid . '.ics';
                $this->calDavBackend->createCalendarObject($calendarId, $uri, $icsData);
                $added++;
            } else {
                // Only update if LAST-MODIFIED or SEQUENCE changed
                if ($this->hasChanged($existing[$uid]['calendardata'], $icsData)) {
                    $this->calDavBackend->updateCalendarObject(
                        $calendarId,
                        $existing[$uid]['uri'],
                        $icsData
                    );
                    $updated++;
                }
            }
        }

        // Delete objects no longer present in the feed
        foreach ($existing as $uid => $obj) {
            if (!isset($seen[$uid])) {
                $this->calDavBackend->deleteCalendarObject($calendarId, $obj['uri']);
                $deleted++;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'deleted' => $deleted];
    }

    // -------------------------------------------------------------------------
    // Group sharing (read-only)
    // -------------------------------------------------------------------------

    /**
     * Share the relay calendar read-only with the specified groups.
     * Replaces the full share list on every call to stay in sync.
     *
     * @param string[] $groupIds
     */
    public function shareWithGroups(int $calendarId, array $groupIds): void {
        $calendar = $this->getCalendarById($calendarId);
        if ($calendar === null) {
            $this->logger->warning('CalendarBridge: cannot share — calendar not found', [
                'calendarId' => $calendarId,
            ]);
            return;
        }

        $add = [];
        foreach ($groupIds as $gid) {
            $add[] = [
                'href'     => 'principal:principals/groups/' . $gid,
                'readOnly' => true,
            ];
        }

        // updateShares($calendar, $add, $remove)
        // Passing the full $add list each time is idempotent
        $this->calDavBackend->updateShares($calendar, $add, []);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generateUri(string $name): string {
        $uri = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        return 'calbridge-' . trim($uri, '-') . '-' . substr(md5($name . time()), 0, 6);
    }

    /**
     * Wrap a VEVENT in a minimal VCALENDAR, preserving VTIMEZONE if present.
     */
    private function wrapEvent(VCalendar $source, VEvent $event): string {
        $cal  = new VCalendar();
        $cal->PRODID = '-//CalendarBridge//CalendarBridge//EN';
        $cal->VERSION = '2.0';

        // Include VTIMEZONE referenced by the event
        $tzId = (string)($event->DTSTART['TZID'] ?? '');
        if ($tzId !== '' && isset($source->VTIMEZONE)) {
            foreach ($source->VTIMEZONE as $tz) {
                if ((string)$tz->TZID === $tzId) {
                    $cal->add($tz);
                    break;
                }
            }
        }

        $cal->add($event);
        return $cal->serialize();
    }

    private function extractUid(string $icsData): ?string {
        if (preg_match('/^UID:(.+)$/m', $icsData, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Simple change detection — compare LAST-MODIFIED or fall back to full diff.
     */
    private function hasChanged(string $existing, string $incoming): bool {
        // Fast path: compare LAST-MODIFIED timestamps
        preg_match('/^LAST-MODIFIED:(.+)$/m', $existing, $em);
        preg_match('/^LAST-MODIFIED:(.+)$/m', $incoming, $im);

        if (isset($em[1], $im[1])) {
            return trim($em[1]) !== trim($im[1]);
        }

        // Fallback: compare SEQUENCE numbers
        preg_match('/^SEQUENCE:(\d+)/m', $existing, $es);
        preg_match('/^SEQUENCE:(\d+)/m', $incoming, $is);

        if (isset($es[1], $is[1])) {
            return (int)$es[1] !== (int)$is[1];
        }

        // Last resort: checksum comparison (excluding DTSTAMP which changes every request)
        $clean = fn(string $s) => preg_replace('/^DTSTAMP:.+$/m', '', $s);
        return md5($clean($existing)) !== md5($clean($incoming));
    }

    private function getCalendarById(int $calendarId): ?array {
        // CalDavBackend doesn't expose a direct getCalendarById —
        // use getCalendarObjectById as a workaround via internal method
        // We store principal in the DB so we can also call getCalendarsForUser.
        // For now, return a minimal array sufficient for updateShares.
        try {
            $row = \OC::$server->getDatabaseConnection()->getQueryBuilder();
            $row->select('*')
                ->from('calendars')
                ->where($row->expr()->eq('id', $row->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $result = $row->executeQuery();
            $data   = $result->fetch();
            $result->closeCursor();
            return $data ?: null;
        } catch (\Exception) {
            return null;
        }
    }
}
