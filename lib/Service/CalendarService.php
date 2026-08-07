<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

class CalendarService {
    // NC DAV sharing access levels (oc_dav_shares.access), see OCA\DAV\DAV\Sharing\Backend
    private const ACCESS_READ_ONLY = 3;
    private const ACCESS_UNSHARED = 5;

    private const GROUP_PRINCIPAL_PREFIX = 'principals/groups/';
    private const USER_PRINCIPAL_PREFIX = 'principals/users/';

    public function __construct(
        private readonly CalDavBackend $calDavBackend,
        private readonly IDBConnection $db,
        private readonly IGroupManager $groupManager,
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
        // Drop shares first so a failed delete cannot leave the calendar shared.
        $this->removeAllShares($calendarId);
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

        // Index existing objects by UID (NC bulk list often omits calendardata — hydrate per row).
        $existing = [];
        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $obj) {
            $full = $this->hydrateCalendarObject($calendarId, $obj);
            if ($full === null) {
                continue;
            }
            $uid = $this->resolveObjectUid($full);
            if ($uid !== null && $uid !== '') {
                $existing[$uid] = $full;
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
                    $existing[$uid] = ['uri' => $uri, 'calendardata' => $icsData];
                } catch (\Exception $e) {
                    if (!$this->isLikelyDuplicateCalendarObjectException($e)) {
                        $this->logger->warning('CalendarBridge: could not create calendar object', [
                            'calendarId' => $calendarId,
                            'uid'        => $uid,
                            'error'      => $e->getMessage(),
                        ]);
                        continue;
                    }
                    // Object already exists (same URI, different URI from older sync, or concurrent insert).
                    $found = $this->calDavBackend->getCalendarObject($calendarId, $uri)
                        ?? $this->findObjectByUid($calendarId, $uid);
                    if ($found !== null) {
                        $this->logger->info('CalendarBridge: resolved duplicate calendar object on sync', [
                            'calendarId' => $calendarId,
                            'uid'        => $uid,
                            'uri'        => $found['uri'] ?? $uri,
                        ]);
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
     *
     * Uses direct DB access because CalDavBackend::updateShares() requires an
     * IShareable object that is not easily constructable externally. The column
     * set written here mirrors OCA\DAV\DAV\Sharing\SharingMapper::share().
     *
     * This runs on every sync, so only actual differences are written.
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
            $desired = array_values(array_unique(array_filter(
                array_map('strval', $groupIds),
                static fn (string $gid): bool => $gid !== '',
            )));
            $current = $this->getSharedGroupIds($calendarId);

            $toAdd    = array_values(array_diff($desired, $current));
            $toRemove = array_values(array_diff($current, $desired));

            if ($toAdd === [] && $toRemove === []) {
                return;
            }

            foreach ($toRemove as $gid) {
                $this->deleteShare($calendarId, self::GROUP_PRINCIPAL_PREFIX . $gid);
            }

            foreach ($toAdd as $gid) {
                $this->upsertShare($calendarId, self::GROUP_PRINCIPAL_PREFIX . $gid);
                // "Remove from my calendars" leaves an ACCESS_UNSHARED row behind which
                // would keep the calendar hidden even after re-sharing. Clear it only for
                // members of a group that is being (re-)added — doing it unconditionally
                // would undo the user's own choice on every sync.
                $this->clearUnsharesForGroup($calendarId, $gid);
            }

            $this->logger->info('CalendarBridge: calendar sharing updated', [
                'calendarId' => $calendarId,
                'added'      => $toAdd,
                'removed'    => $toRemove,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CalendarBridge: failed to update calendar shares', [
                'calendarId' => $calendarId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Drop every share row for a calendar, including the per-user ACCESS_UNSHARED
     * markers, so nothing survives to affect a later calendar with the same id.
     */
    public function removeAllShares(int $calendarId): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('dav_shares')
                ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')));
            $qb->executeStatement();
        } catch (\Exception $e) {
            $this->logger->error('CalendarBridge: failed to remove calendar shares', [
                'calendarId' => $calendarId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Group IDs this calendar is currently shared with, ignoring unshare markers.
     *
     * @return string[]
     */
    private function getSharedGroupIds(int $calendarId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('principaluri')
            ->from('dav_shares')
            ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->neq('access', $qb->createNamedParameter(self::ACCESS_UNSHARED, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('principaluri', $qb->createNamedParameter(self::GROUP_PRINCIPAL_PREFIX . '%')));

        $result   = $qb->executeQuery();
        $groupIds = [];
        while ($row = $result->fetch()) {
            $gid = substr((string)$row['principaluri'], strlen(self::GROUP_PRINCIPAL_PREFIX));
            if ($gid !== '') {
                $groupIds[] = $gid;
            }
        }
        $result->closeCursor();

        return array_values(array_unique($groupIds));
    }

    private function deleteShare(int $calendarId, string $principalUri): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('dav_shares')
            ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)));
        $qb->executeStatement();
    }

    /**
     * Remove the ACCESS_UNSHARED markers of a group's members for this calendar.
     */
    private function clearUnsharesForGroup(int $calendarId, string $groupId): void {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return;
        }

        $principals = [];
        foreach ($group->getUsers() as $user) {
            $principals[] = self::USER_PRINCIPAL_PREFIX . $user->getUID();
        }
        if ($principals === []) {
            return;
        }

        // Chunked to stay clear of database placeholder limits on large groups.
        foreach (array_chunk($principals, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('dav_shares')
                ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('access', $qb->createNamedParameter(self::ACCESS_UNSHARED, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('principaluri', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
            $qb->executeStatement();
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

    /**
     * UID from serialized calendar data (folding, property params — Sabre; regex fallback).
     */
    private function extractUid(string $icsData): ?string {
        if ($icsData === '') {
            return null;
        }
        try {
            $doc = Reader::read($icsData);
            try {
                foreach ($doc->VEVENT ?? [] as $vevent) {
                    $u = isset($vevent->UID) ? trim((string)$vevent->UID) : '';
                    if ($u !== '') {
                        return $u;
                    }
                }
            } finally {
                $doc->destroy();
            }
        } catch (\Throwable) {
            // Invalid or partial ICS
        }
        if (preg_match('/^UID[^:]*:(.+)$/m', $icsData, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function resolveObjectUid(array $obj): ?string {
        $fromIcs = $this->extractUid($obj['calendardata'] ?? '');
        if ($fromIcs !== null && $fromIcs !== '') {
            return $fromIcs;
        }
        $fromRow = isset($obj['uid']) ? trim((string)$obj['uid']) : '';
        return $fromRow !== '' ? $fromRow : null;
    }

    /**
     * Bulk rows from getCalendarObjects often omit calendardata; load full row for UID / diff.
     */
    private function hydrateCalendarObject(int $calendarId, array $obj): ?array {
        if (($obj['calendardata'] ?? '') !== '') {
            return $obj;
        }
        return $this->calDavBackend->getCalendarObject($calendarId, $obj['uri']);
    }

    private function isLikelyDuplicateCalendarObjectException(\Throwable $e): bool {
        $m = $e->getMessage();
        return str_contains($m, 'Duplicate')
            || str_contains($m, '1062')
            || str_contains($m, 'UNIQUE')
            || str_contains($m, 'already exists');
    }

    /**
     * Find a calendar object by UID when the URI is unknown (conflict resolution fallback).
     */
    private function findObjectByUid(int $calendarId, string $uid): ?array {
        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $obj) {
            $full = $this->hydrateCalendarObject($calendarId, $obj);
            if ($full === null) {
                continue;
            }
            $objUid = $this->resolveObjectUid($full);
            if ($objUid === $uid) {
                return $full;
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
