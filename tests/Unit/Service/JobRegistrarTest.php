<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\BackgroundJob\SyncJob;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\JobRegistrar;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JobRegistrarTest extends TestCase {
    private FeedMapper&MockObject $feedMapper;
    private IJobList&MockObject $jobList;
    private IAppConfig&MockObject $appConfig;

    protected function setUp(): void {
        parent::setUp();
        $this->feedMapper = $this->createMock(FeedMapper::class);
        $this->jobList    = $this->createMock(IJobList::class);
        $this->appConfig  = $this->createMock(IAppConfig::class);

        $feeds = [];
        foreach ([1, 2] as $id) {
            $feed = new Feed();
            $feed->setId($id);
            $feeds[] = $feed;
        }
        $this->feedMapper->method('findAll')->willReturn($feeds);
    }

    private function registrar(): JobRegistrar {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(10_000);
        return new JobRegistrar($this->feedMapper, $this->jobList, $this->appConfig, $time, $this->createMock(LoggerInterface::class));
    }

    public function testMissingJobsAreRegisteredAgain(): void {
        $this->jobList->method('has')->willReturnCallback(
            static fn ($class, $argument = null) => $argument === null || $argument['feedId'] === 1,
        );
        $this->jobList->expects(self::once())->method('add')->with(SyncJob::class, ['feedId' => 2]);

        self::assertSame(1, $this->registrar()->ensureJobs());
    }

    public function testThrottledCheckRunsAtMostHourly(): void {
        $this->appConfig->method('getValueInt')->willReturn(10_000 - 60);
        $this->feedMapper->expects(self::never())->method('findAll');

        $this->registrar()->ensureJobsThrottled();
    }

    public function testThrottledCheckRunsWhenDue(): void {
        $this->appConfig->method('getValueInt')->willReturn(0);
        $this->appConfig->expects(self::once())->method('setValueInt')->with('calendarbridge', 'jobs_checked_at', 10_000);
        $this->jobList->method('has')->willReturn(false);
        $this->jobList->expects(self::exactly(3))->method('add');

        $this->registrar()->ensureJobsThrottled();
    }

    public function testSyncJobsAreRecreatedOnceAsTimeSensitive(): void {
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->jobList->method('has')->willReturnCallback(
            static fn ($class, $argument = null) => $argument['feedId'] === 1,
        );
        $this->jobList->expects(self::once())->method('remove')->with(SyncJob::class, ['feedId' => 1]);
        $added = [];
        $this->jobList->expects(self::exactly(2))->method('add')->willReturnCallback(
            static function ($class, $argument) use (&$added): void {
                $added[] = [$class, $argument];
            },
        );
        $this->appConfig->expects(self::once())->method('setValueBool')->with('calendarbridge', 'sync_jobs_time_sensitive', true);

        self::assertSame(2, $this->registrar()->makeSyncJobsTimeSensitive());
        self::assertSame([[SyncJob::class, ['feedId' => 1]], [SyncJob::class, ['feedId' => 2]]], $added);
    }

    public function testSyncJobsAreNotRecreatedAgain(): void {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->jobList->expects(self::never())->method('remove');
        $this->jobList->expects(self::never())->method('add');
        $this->appConfig->expects(self::never())->method('setValueBool');

        self::assertSame(0, $this->registrar()->makeSyncJobsTimeSensitive());
    }
}
