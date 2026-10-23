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
use Sabre\VObject\Property;
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
     * All VEVENTs sharing a UID (a recurring master plus its RECURRENCE-ID
     * overrides) form one calendar object, as CalDAV requires.
     *
     * @return array{added: int, updated: int, deleted: int}
     */
    public function syncEvents(int $calendarId, VCalendar $calendar): array {
        $added   = 0;
        $updated = 0;
        $deleted = 0;

        $this->purgeTrashedObjects($calendarId);
        $existing = $this->loadExistingObjects($calendarId);

        $seen = [];
        foreach ($this->groupEventsByUid($calendar) as $uid => $events) {
            $seen[$uid] = true;
            $icsData = $this->wrapEvents($calendar, $events);

            if (isset($existing[$uid])) {
                if ($this->hasChanged($existing[$uid]['calendardata'] ?? '', $icsData)) {
                    $this->calDavBackend->updateCalendarObject($calendarId, $existing[$uid]['uri'], $icsData);
                    $updated++;
                }
                continue;
            }

            $uri = $this->objectUri($uid);
            try {
                $this->calDavBackend->createCalendarObject($calendarId, $uri, $icsData);
                $added++;
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
                if ($found === null) {
                    $this->logger->warning('CalendarBridge: could not create or locate object', [
                        'calendarId' => $calendarId,
                        'uid'        => $uid,
                        'error'      => $e->getMessage(),
                    ]);
                    continue;
                }
                $this->logger->info('CalendarBridge: resolved duplicate calendar object on sync', [
                    'calendarId' => $calendarId,
                    'uid'        => $uid,
                    'uri'        => $found['uri'] ?? $uri,
                ]);
                if ($this->hasChanged($found['calendardata'] ?? '', $icsData)) {
                    $this->calDavBackend->updateCalendarObject($calendarId, $found['uri'], $icsData);
                    $updated++;
                }
            }
        }

        // Delete objects no longer present in the feed. Permanently: a trashed
        // object would block re-creating the same UID later (ADR 0009).
        foreach ($existing as $uid => $obj) {
            if (!isset($seen[$uid])) {
                $this->calDavBackend->deleteCalendarObject(
                    $calendarId,
                    $obj['uri'],
                    CalDavBackend::CALENDAR_TYPE_CALENDAR,
                    true,
                );
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
     * Calendar object URI for a new object. UIDs are opaque strings and may
     * contain characters that are unsafe in a URI, so those are hashed.
     */
    private function objectUri(string $uid): string {
        if (preg_match('/^[A-Za-z0-9._@-]{1,200}$/', $uid) === 1) {
            return $uid . '.ics';
        }
        return sha1($uid) . '.ics';
    }

    /**
     * Group the feed's VEVENTs by UID, master (no RECURRENCE-ID) first and
     * overrides in RECURRENCE-ID order, so the serialisation is stable.
     *
     * @return array<string, VEvent[]>
     */
    private function groupEventsByUid(VCalendar $calendar): array {
        $groups = [];
        foreach ($calendar->VEVENT ?? [] as $event) {
            $uid = trim((string)($event->UID ?? ''));
            if ($uid === '') {
                continue;
            }
            $groups[$uid][] = $event;
        }

        foreach ($groups as &$events) {
            usort($events, static function (VEvent $a, VEvent $b): int {
                $ra = isset($a->{'RECURRENCE-ID'}) ? (string)$a->{'RECURRENCE-ID'} : '';
                $rb = isset($b->{'RECURRENCE-ID'}) ? (string)$b->{'RECURRENCE-ID'} : '';
                return strcmp($ra, $rb);
            });
        }
        unset($events);

        return $groups;
    }

    /**
     * Wrap the VEVENTs of one UID in a minimal VCALENDAR, together with every
     * VTIMEZONE referenced by any of their properties (DTSTART, DTEND, DUE,
     * RECURRENCE-ID, EXDATE, RDATE, …).
     *
     * @param VEvent[] $events
     */
    private function wrapEvents(VCalendar $source, array $events): string {
        $cal = new VCalendar();
        $cal->PRODID = '-//CalendarBridge//CalendarBridge//EN';
        $cal->VERSION = '2.0';

        $tzIds = [];
        foreach ($events as $event) {
            foreach ($event->children() as $child) {
                if ($child instanceof Property && isset($child['TZID'])) {
                    $tzIds[(string)$child['TZID']] = true;
                }
            }
        }

        if ($tzIds !== [] && isset($source->VTIMEZONE)) {
            foreach ($source->VTIMEZONE as $tz) {
                if (isset($tzIds[(string)$tz->TZID])) {
                    $cal->add(clone $tz);
                }
            }
        }

        foreach ($events as $event) {
            $cal->add(clone $event);
        }

        return $cal->serialize();
    }

    /**
     * Existing objects of the relay calendar indexed by UID. Two queries in
     * total: the object list, then the data of all objects in one batch.
     *
     * @return array<string, array{uri: string, calendardata: string}>
     */
    private function loadExistingObjects(int $calendarId): array {
        $uris = array_map(
            static fn (array $row): string => (string)$row['uri'],
            $this->calDavBackend->getCalendarObjects($calendarId),
        );
        if ($uris === []) {
            return [];
        }

        $byUri = [];
        foreach ($this->calDavBackend->getMultipleCalendarObjects($calendarId, $uris) as $obj) {
            $byUri[(string)$obj['uri']] = $obj;
        }

        $existing = [];
        foreach ($uris as $uri) {
            $obj = $byUri[$uri] ?? $this->calDavBackend->getCalendarObject($calendarId, $uri);
            if ($obj === null) {
                continue;
            }
            $uid = $this->resolveObjectUid($obj);
            if ($uid !== null && $uid !== '') {
                $existing[$uid] = ['uri' => $uri, 'calendardata' => (string)($obj['calendardata'] ?? '')];
            }
        }

        return $existing;
    }

    /**
     * Permanently remove objects that earlier versions moved to the trash bin.
     * On NC 30 – early 33 a trashed object blocks re-creating its UID, and a
     * second soft delete of the same URI fails (ADR 0009).
     */
    private function purgeTrashedObjects(int $calendarId): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('uri')
                ->from('calendarobjects')
                ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('calendartype', $qb->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNotNull('deleted_at'));
            $result = $qb->executeQuery();
            $uris = [];
            while ($row = $result->fetch()) {
                $uris[] = (string)$row['uri'];
            }
            $result->closeCursor();

            foreach ($uris as $uri) {
                $this->calDavBackend->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
            }
            if ($uris !== []) {
                $this->logger->info('CalendarBridge: purged trashed relay objects', [
                    'calendarId' => $calendarId,
                    'count'      => count($uris),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('CalendarBridge: could not purge trashed relay objects', [
                'calendarId' => $calendarId,
                'error'      => $e->getMessage(),
            ]);
        }
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

    private function isLikelyDuplicateCalendarObjectException(\Throwable $e): bool {
        // NC >= 33.0.x throws OCA\DAV\CalDAV\UidConflict for an existing UID.
        if (str_ends_with($e::class, 'UidConflict')) {
            return true;
        }
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
        return $this->loadExistingObjects($calendarId)[$uid] ?? null;
    }

    /**
     * Content comparison, ignoring DTSTAMP (many feeds set it to the time of
     * the request) and line-ending differences. LAST-MODIFIED/SEQUENCE alone
     * miss changed overrides and a toggled attendee stripping.
     */
    private function hasChanged(string $existing, string $incoming): bool {
        $normalise = static fn (string $s): string => (string)preg_replace(
            '/^DTSTAMP[;:][^\n]*\n/m',
            '',
            str_replace("\r\n", "\n", $s),
        );
        return $normalise($existing) !== $normalise($incoming);
    }
}
