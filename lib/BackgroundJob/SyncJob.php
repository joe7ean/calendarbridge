<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\BackgroundJob;

use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * One job instance per configured feed.
 * Registered via IJobList::add(SyncJob::class, ['feedId' => $id]) when a feed is created.
 */
class SyncJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly SyncService $syncService,
        private readonly FeedMapper $feedMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Default interval — overridden dynamically per feed in run()
        $this->setInterval(3600);

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

        // Honour the per-feed sync interval
        try {
            $feed = $this->feedMapper->findById($feedId);
            $this->setInterval(max(900, $feed->getSyncInterval()));
        } catch (\Exception) {
            // Feed deleted — job will be cleaned up on next run
            $this->logger->info('CalendarBridge SyncJob: feed no longer exists, skipping', [
                'feedId' => $feedId,
            ]);
            return;
        }

        $this->syncService->syncFeed($feedId);
    }
}
