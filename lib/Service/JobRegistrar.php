<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\BackgroundJob\SyncJob;
use OCA\CalendarBridge\Db\FeedMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Makes sure every feed has its background job (ADR 0014).
 *
 * Nextcloud's cron deletes jobs whose class cannot be loaded, which is the case
 * while the app is disabled (e.g. during a Nextcloud major upgrade). Re-enabling
 * the app with an unchanged version neither re-adds jobs nor runs repair steps,
 * so without this the feeds silently stop syncing.
 */
class JobRegistrar {
    private const CHECK_INTERVAL = 3600;

    public function __construct(
        private readonly FeedMapper $feedMapper,
        private readonly IJobList $jobList,
        private readonly IAppConfig $appConfig,
        private readonly ITimeFactory $time,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Cheap enough for every request: one cached config read, the actual check
     * at most once per hour.
     */
    public function ensureJobsThrottled(): void {
        $now = $this->time->getTime();
        $last = $this->appConfig->getValueInt(Application::APP_ID, 'jobs_checked_at', 0);
        if ($now - $last < self::CHECK_INTERVAL) {
            return;
        }
        $this->appConfig->setValueInt(Application::APP_ID, 'jobs_checked_at', $now);
        $this->ensureJobs();
    }

    /**
     * @return int number of jobs that had to be registered again
     */
    public function ensureJobs(): int {
        $added = 0;
        foreach ($this->feedMapper->findAll() as $feed) {
            $argument = ['feedId' => $feed->getId()];
            if (!$this->jobList->has(SyncJob::class, $argument)) {
                $this->jobList->add(SyncJob::class, $argument);
                $added++;
            }
        }
        if ($added > 0) {
            $this->logger->warning('CalendarBridge: re-registered missing background jobs', ['count' => $added]);
        }
        return $added;
    }
}
