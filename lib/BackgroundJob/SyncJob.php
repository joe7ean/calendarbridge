<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\BackgroundJob;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\SyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * One job instance per configured feed.
 * Registered via IJobList::add(SyncJob::class, ['feedId' => $id]) when a feed is created.
 *
 * TimedJob checks its interval before run() on a freshly built instance, so a
 * per-feed interval cannot be set from run(). The job wakes at the minimum
 * interval and decides itself whether the feed is due (ADR 0003).
 */
class SyncJob extends TimedJob {
    public const MIN_INTERVAL = 900;

    // Cron rarely hits the exact second; without slack a 15-minute feed would
    // be skipped on every other wake-up and effectively sync every 30 minutes.
    private const SLACK = 60;

    public function __construct(
        ITimeFactory $time,
        private readonly SyncService $syncService,
        private readonly FeedMapper $feedMapper,
        private readonly IJobList $jobList,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);

        $this->setInterval(self::MIN_INTERVAL);

        // Prevent two sync runs for the same feed running in parallel
        $this->setAllowParallelRuns(false);

        // Not time-critical: NC may delay execution on high-load servers
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $feedId = (int)($argument['feedId'] ?? 0);
        if ($feedId <= 0) {
            $this->logger->warning('CalendarBridge SyncJob: missing feedId in argument', [
                'argument' => $argument,
            ]);
            return;
        }

        try {
            $feed = $this->feedMapper->findById($feedId);
        } catch (DoesNotExistException) {
            $this->logger->info('CalendarBridge SyncJob: feed no longer exists, removing job', [
                'feedId' => $feedId,
            ]);
            $this->jobList->remove($this, $argument);
            return;
        }

        if (!$feed->isActive()) {
            return;
        }

        $minInterval = $this->appConfig->getValueInt(Application::APP_ID, 'min_sync_interval', self::MIN_INTERVAL);
        $interval = max(self::MIN_INTERVAL, $minInterval, $feed->getSyncInterval());
        $lastSynced = $feed->getLastSyncedAt();
        if ($lastSynced !== null
            && $this->time->getTime() - $lastSynced->getTimestamp() < $interval - self::SLACK) {
            return;
        }

        $this->syncService->syncFeed($feedId);
    }
}
