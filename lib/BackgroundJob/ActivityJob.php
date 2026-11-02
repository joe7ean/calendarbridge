<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\BackgroundJob;

use OCA\CalendarBridge\Activity\ActivityService;
use OCA\CalendarBridge\Db\FeedMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Flush independently of sync/backoff, including automatically paused feeds. */
class ActivityJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly FeedMapper $feeds,
        private readonly ActivityService $activities,
        private readonly ILockingProvider $locks,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(900);
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void {
        foreach ($this->feeds->findAll() as $candidate) {
            $id = $candidate->getId();
            $key = 'calendarbridge/feed/' . $id;
            try {
                $this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (LockedException) {
                continue;
            }
            try {
                $feed = $this->feeds->findById($id);
                $this->activities->flush($feed);
                $this->feeds->update($feed);
            } catch (\Throwable $e) {
                $this->logger->error('CalendarBridge: activity job failed', ['feedId' => $id, 'errorType' => $e::class]);
            } finally {
                $this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
            }
        }
    }
}
