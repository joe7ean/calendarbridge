<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\BackgroundJob\SyncJob;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Consistency and liveness checks shared by the admin setup check and
 * `occ calendarbridge:check`. Read-only: it reports, it does not repair.
 */
class HealthService {
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    // A feed counts as overdue after this many missed intervals (at least 2 h late).
    private const OVERDUE_FACTOR = 3;
    private const OVERDUE_MIN = 7200;

    public function __construct(
        private readonly FeedMapper $feedMapper,
        private readonly IJobList $jobList,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly CalDavBackend $calDavBackend,
        private readonly IAppConfig $appConfig,
        private readonly ITimeFactory $time,
    ) {
    }

    /**
     * @return list<array{level: string, feedId: ?int, feedName: ?string, message: string}>
     */
    public function check(): array {
        $findings = [];

        if ($this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') === 'ajax') {
            $findings[] = $this->finding(self::WARNING, null, 'Background jobs run in AJAX mode; feeds only sync while someone uses the web interface. Configure system cron.');
        }

        foreach ($this->feedMapper->findAll() as $feed) {
            foreach ($this->checkFeed($feed) as [$level, $message]) {
                $findings[] = $this->finding($level, $feed, $message);
            }
        }

        return $findings;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function checkFeed(Feed $feed): array {
        $out = [];
        $now = $this->time->getTime();

        if (!$this->userManager->userExists($feed->getServiceUser())) {
            $out[] = [self::ERROR, sprintf('Service user "%s" does not exist.', $feed->getServiceUser())];
        }
        if (!$this->jobList->has(SyncJob::class, ['feedId' => $feed->getId()])) {
            $out[] = [self::ERROR, 'No background job is registered; the feed is never synced.'];
        }
        foreach ($feed->getTargetGroupsArray() as $gid) {
            if (!$this->groupManager->groupExists($gid)) {
                $out[] = [self::WARNING, sprintf('Target group "%s" does not exist.', $gid)];
            }
        }
        if ($feed->getCalendarId() !== null && $this->calDavBackend->getCalendarById($feed->getCalendarId()) === null) {
            $out[] = [self::WARNING, 'The relay calendar is missing; it will be recreated on the next sync.'];
        }

        if (!$feed->isActive()) {
            $out[] = $feed->getConsecutiveErrors() >= 10
                ? [self::WARNING, 'Paused automatically after repeated errors: ' . ($feed->getLastError() ?? '')]
                : [self::INFO, 'Paused.'];
            return $out;
        }

        if ($feed->isInQuarantine()) {
            $out[] = [self::WARNING, sprintf(
                'In quarantine (%s) since %s: %d deletions held.',
                $feed->getQuarantineReason(),
                $feed->getQuarantineSince()?->format(\DateTime::ATOM) ?? '?',
                $feed->getQuarantineHeld(),
            )];
        }
        if ($feed->getLastStatus() === Feed::STATUS_ERROR) {
            $out[] = [self::WARNING, sprintf('Last sync failed (%d in a row): %s', $feed->getConsecutiveErrors(), $feed->getLastError() ?? '')];
        }

        $lastSynced = $feed->getLastSyncedAt();
        $nextAttempt = $feed->getNextAttemptAt();
        $backingOff = $nextAttempt !== null && $nextAttempt->getTimestamp() > $now;
        $overdueAfter = max(self::OVERDUE_FACTOR * $feed->getSyncInterval(), $feed->getSyncInterval() + self::OVERDUE_MIN);
        if ($lastSynced === null) {
            $out[] = [self::INFO, 'Not synced yet.'];
        } elseif (!$backingOff && $now - $lastSynced->getTimestamp() > $overdueAfter) {
            $out[] = [self::WARNING, sprintf('Overdue: last sync attempt %s. Is cron running?', $lastSynced->format(\DateTime::ATOM))];
        }

        return $out;
    }

    /** @return array{level: string, feedId: ?int, feedName: ?string, message: string} */
    private function finding(string $level, ?Feed $feed, string $message): array {
        return [
            'level'    => $level,
            'feedId'   => $feed?->getId(),
            'feedName' => $feed?->getFeedName(),
            'message'  => $message,
        ];
    }
}
