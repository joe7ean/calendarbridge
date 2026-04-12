<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

class CalendarService {
    // NC DAV sharing access level constants (oc_dav_shares.access)
    private const ACCESS_READ_ONLY = 3;

    public function __construct(
        private readonly CalDavBackend $calDavBackend,
        private readonly IDBConnection $db,
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
        // Also remove all shares first
        $this->shareWithGroups($calendarId, []);
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
                try {
                    $this->calDavBackend->createCalendarObject($calendarId, $uri, $icsData);
                    $added++;
                } catch (\Exception $e) {
                    // Object already exists with a different URI (from a previous partial sync).
                    // Find it by UID and update instead.
                    $found = $this->findObjectByUid($calendarId, $uid);
                    if ($found !== null) {
                        if ($this->hasChanged($found['calendardata'] ?? '', $icsData)) {
                            $this->calDavBackend->updateCalendarObject($calendarId, $found['uri'], $icsData);
                            $updated++;
                        }
                        $existing[$uid] = $found;
                    } else {
                        $this->logger->warning('CalendarBridge: could not create or locate object', [
                            'calendarId' => $calendarId,
                            'uid'        => $uid,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                // Only update if LAST-MODIFIED or SEQUENCE changed
                if ($this->hasChanged($existing[$uid]['calendardata'] ?? '', $icsData)) {
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
     * Replaces the full group-share list on every call to stay in sync.
     *
     * Uses direct DB access because CalDavBackend::updateShares() in NC 32
     * requires an IShareable object that is not easily constructable externally.
     *
     * The serviceUser is NOT added as a read-only sharer here — they remain
     * the calendar owner and can manage it via CalDAV. Group members who want
     * to hide the calendar from their personal view can use "Remove from my
     * calendars" in the NC Calendar app, which only affects their own view.
     *
     * @param string[] $groupIds
     */
    public function shareWithGroups(int $calendarId, array $groupIds): void {
        try {
            $qb = $this->db->getQueryBuilder();

            // Remove all existing group shares for this calendar
            $qb->delete('dav_shares')
                ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->like('principaluri', $qb->createNamedParameter('principals/groups/%')));
            $qb->executeStatement();

            // Insert new group shares (read-only)
            foreach ($groupIds as $gid) {
                $this->upsertShare($calendarId, 'principals/groups/' . $gid);
            }

            $this->logger->info('CalendarBridge: calendar sharing updated', [
                'calendarId' => $calendarId,
                'groups'     => $groupIds,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CalendarBridge: failed to update calendar shares', [
                'calendarId' => $calendarId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function upsertShare(int $calendarId, string $principalUri): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('dav_shares')
            ->values([
                'principaluri' => $qb->createNamedParameter($principalUri),
                'type'         => $qb->createNamedParameter('calendar'),
                'access'       => $qb->createNamedParameter(self::ACCESS_READ_ONLY, IQueryBuilder::PARAM_INT),
                'resourceid'   => $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
        } catch (\Exception $e) {
            // Ignore duplicate — share already exists
            if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
        }
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
     * Find a calendar object by UID when the URI is unknown (conflict resolution fallback).
     */
    private function findObjectByUid(int $calendarId, string $uid): ?array {
        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $obj) {
            $objUid = $this->extractUid($obj['calendardata'] ?? '');
            if ($objUid === $uid) {
                return $obj;
            }
        }
        return null;
    }

    /**
     * Simple change detection — compare LAST-MODIFIED or fall back to full diff.
     */
    private function hasChanged(string $existing, string $incoming): bool {
        preg_match('/^LAST-MODIFIED:(.+)$/m', $existing, $em);
        preg_match('/^LAST-MODIFIED:(.+)$/m', $incoming, $im);

        if (isset($em[1], $im[1])) {
            return trim($em[1]) !== trim($im[1]);
        }

        preg_match('/^SEQUENCE:(\d+)/m', $existing, $es);
        preg_match('/^SEQUENCE:(\d+)/m', $incoming, $is);

        if (isset($es[1], $is[1])) {
            return (int)$es[1] !== (int)$is[1];
        }

        $clean = fn(string $s) => preg_replace('/^DTSTAMP:.+$/m', '', $s);
        return md5($clean($existing)) !== md5($clean($incoming));
    }
}
