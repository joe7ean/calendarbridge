<?php

declare(strict_types=1);

/**
 * End-to-end harness: drives the real app services inside a Nextcloud instance.
 * Run as the web server user, e.g.  php harness.php sync 4
 *
 * Commands print one JSON document per call so run.sh can assert on them.
 */

$ncRoot = getenv('NC_ROOT') ?: '/var/www/nextcloud';
require $ncRoot . '/lib/base.php';

use OCA\CalendarBridge\BackgroundJob\SyncJob;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\CalendarService;
use OCA\CalendarBridge\Service\SyncService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Server;
use Sabre\VObject\Reader;

Server::get(\OCP\App\IAppManager::class)->loadApp('calendarbridge');

$mapper = Server::get(FeedMapper::class);
$calDav = Server::get(CalDavBackend::class);
$out = static function (mixed $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES), "\n";
};

switch ($argv[1] ?? '') {
    case 'create': // create <url> <name> <serviceUser> <group>
        $feed = new Feed();
        $feed->setFeedUrl($argv[2]);
        $feed->setFeedName($argv[3]);
        $feed->setServiceUser($argv[4]);
        $feed->setTargetGroupsArray([$argv[5]]);
        $feed->setSyncInterval(3600);
        $feed->setStripAttendees(1);
        $feed->setVerifySsl(1);
        $feed->setActive(1);
        $saved = $mapper->insert($feed);
        Server::get(IJobList::class)->add(SyncJob::class, ['feedId' => $saved->getId()]);
        $out(['id' => $saved->getId()]);
        break;

    case 'sync': // sync <feedId>
        $result = Server::get(SyncService::class)->syncFeed((int)$argv[2]);
        $feed = $mapper->findById((int)$argv[2]);
        $out($result + [
            'status'     => $feed->getLastStatus(),
            'errors'     => $feed->getConsecutiveErrors(),
            'lastError'  => $feed->getLastError(),
            'calendarId' => $feed->getCalendarId(),
        ]);
        break;

    case 'objects': // objects <feedId>  – live objects with a short summary
        $feed = $mapper->findById((int)$argv[2]);
        $objects = [];
        foreach ($calDav->getCalendarObjects($feed->getCalendarId()) as $row) {
            $vc = Reader::read($calDav->getCalendarObject($feed->getCalendarId(), $row['uri'])['calendardata']);
            $objects[$row['uri']] = [
                'vevents'   => count($vc->VEVENT),
                'overrides' => count(array_filter(iterator_to_array($vc->VEVENT), static fn ($e) => isset($e->{'RECURRENCE-ID'}))),
                'tz'        => array_map(static fn ($t) => (string)$t->TZID, iterator_to_array($vc->VTIMEZONE ?? [])),
                'attendees' => str_contains($vc->serialize(), 'ATTENDEE'),
            ];
        }
        $out($objects);
        break;

    case 'trashed': // trashed <feedId>  – number of objects in the trash bin
        $feed = $mapper->findById((int)$argv[2]);
        $qb = Server::get(IDBConnection::class)->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from('calendarobjects')
            ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($feed->getCalendarId())))
            ->andWhere($qb->expr()->isNotNull('deleted_at'));
        $out(['trashed' => (int)$qb->executeQuery()->fetchOne()]);
        break;

    case 'shares': // shares <feedId>
        $feed = $mapper->findById((int)$argv[2]);
        $qb = Server::get(IDBConnection::class)->getQueryBuilder();
        $qb->select('principaluri', 'access')
            ->from('dav_shares')
            ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($feed->getCalendarId())))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')));
        $out($qb->executeQuery()->fetchAll());
        break;

    case 'softdelete': // softdelete <feedId> <uri>  – reproduce the 0.2.0 trash-bin behaviour
        $feed = $mapper->findById((int)$argv[2]);
        $calDav->deleteCalendarObject($feed->getCalendarId(), $argv[3]);
        $out(['softDeleted' => $argv[3]]);
        break;

    case 'set': // set <feedId> <field> <value>
        $feed = $mapper->findById((int)$argv[2]);
        match ($argv[3]) {
            'feedUrl'             => $feed->setFeedUrl($argv[4]),
            'lastSyncedAgo'       => $feed->setLastSyncedAt(new \DateTime('@' . (time() - (int)$argv[4]))),
            'quarantineSinceAgo'  => $feed->setQuarantineSince(new \DateTime('@' . (time() - (int)$argv[4]))),
            'nextAttemptAt'       => $feed->setNextAttemptAt($argv[4] === 'null' ? null : new \DateTime('@' . (time() + (int)$argv[4]))),
            'active'              => $feed->setActive((int)$argv[4]),
        };
        $mapper->update($feed);
        $out(['ok' => true]);
        break;

    case 'state': // state <feedId>  – the feed as the API sees it
        $out($mapper->findById((int)$argv[2])->toApiArray());
        break;

    case 'rename': // rename <feedId> <name> <color>  – what FeedController::update() does
        $feed = $mapper->findById((int)$argv[2]);
        $feed->setFeedName($argv[3]);
        $feed->setCalendarColor($argv[4]);
        $mapper->update($feed);
        Server::get(CalendarService::class)->updateCalendarProperties($feed->getCalendarId(), $argv[3], $argv[4]);
        $out(['ok' => true]);
        break;

    case 'calendar': // calendar <feedId>  – relay calendar properties, or null if missing
        $feed = $mapper->findById((int)$argv[2]);
        $cal = $feed->getCalendarId() === null ? null : $calDav->getCalendarById($feed->getCalendarId());
        $out($cal === null ? null : [
            'id'    => (int)$cal['id'],
            'name'  => $cal['{DAV:}displayname'] ?? null,
            'color' => $cal['{http://apple.com/ns/ical/}calendar-color'] ?? null,
        ]);
        break;

    case 'dropcalendar': // dropcalendar <feedId>  – delete the relay calendar behind the app's back
        $feed = $mapper->findById((int)$argv[2]);
        $calDav->deleteCalendar($feed->getCalendarId(), true);
        $out(['dropped' => $feed->getCalendarId()]);
        break;

    case 'jobid': // jobid <feedId>
        foreach (Server::get(IJobList::class)->getJobsIterator(SyncJob::class, null, 0) as $job) {
            if ((int)($job->getArgument()['feedId'] ?? 0) === (int)$argv[2]) {
                $out(['jobId' => (string)$job->getId(), 'feedSyncedAt' => $mapper->findById((int)$argv[2])->getLastSyncedAt()?->getTimestamp()]);
                break 2;
            }
        }
        $out(['jobId' => null]);
        break;

    case 'lastsync': // lastsync <feedId>
        $out(['lastSyncedAt' => $mapper->findById((int)$argv[2])->getLastSyncedAt()?->getTimestamp()]);
        break;

    case 'cleanup': // cleanup <feedId>  – remove calendar, feed and job
        $id = (int)$argv[2];
        $feed = $mapper->findById($id);
        if ($feed->getCalendarId() !== null) {
            Server::get(CalendarService::class)->deleteCalendar($feed->getCalendarId());
        }
        $mapper->delete($feed);
        Server::get(IJobList::class)->remove(SyncJob::class, ['feedId' => $id]);
        $out(['removed' => $id]);
        break;

    default:
        fwrite(STDERR, "usage: harness.php create|sync|objects|trashed|shares|softdelete|set|jobid|lastsync|cleanup …\n");
        exit(1);
}
