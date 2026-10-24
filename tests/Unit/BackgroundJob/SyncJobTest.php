<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\BackgroundJob;

use OCA\CalendarBridge\BackgroundJob\SyncJob;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\SyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SyncJobTest extends TestCase {
    private const NOW = 1_800_000_000;

    private SyncService&MockObject $syncService;
    private FeedMapper&MockObject $feedMapper;
    private IJobList&MockObject $jobList;

    protected function setUp(): void {
        parent::setUp();
        $this->syncService = $this->createMock(SyncService::class);
        $this->feedMapper  = $this->createMock(FeedMapper::class);
        $this->jobList     = $this->createMock(IJobList::class);
    }

    private function job(int $minInterval = 900): SyncJob {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn($minInterval);

        return new SyncJob(
            $time,
            $this->syncService,
            $this->feedMapper,
            $this->jobList,
            $appConfig,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function runJob(SyncJob $job, array $argument): void {
        (new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
    }

    private function feed(int $interval, ?int $secondsAgo): Feed {
        $feed = new Feed();
        $feed->setId(7);
        $feed->setSyncInterval($interval);
        if ($secondsAgo !== null) {
            $feed->setLastSyncedAt(new \DateTime('@' . (self::NOW - $secondsAgo)));
        }
        return $feed;
    }

    public function testJobWakesAtTheMinimumInterval(): void {
        self::assertSame(900, $this->job()->getInterval());
    }

    public function testFeedIsSkippedUntilItsIntervalHasPassed(): void {
        $this->feedMapper->method('findById')->willReturn($this->feed(86400, 3600));
        $this->syncService->expects(self::never())->method('syncFeed');

        $this->runJob($this->job(), ['feedId' => 7]);
    }

    public function testFeedIsSyncedWhenDue(): void {
        $this->feedMapper->method('findById')->willReturn($this->feed(3600, 3590));
        $this->syncService->expects(self::once())->method('syncFeed')->with(7);

        $this->runJob($this->job(), ['feedId' => 7]);
    }

    public function testNeverSyncedFeedIsSyncedImmediately(): void {
        $this->feedMapper->method('findById')->willReturn($this->feed(86400, null));
        $this->syncService->expects(self::once())->method('syncFeed')->with(7);

        $this->runJob($this->job(), ['feedId' => 7]);
    }

    public function testGlobalMinimumIntervalWins(): void {
        $this->feedMapper->method('findById')->willReturn($this->feed(900, 1800));
        $this->syncService->expects(self::never())->method('syncFeed');

        $this->runJob($this->job(7200), ['feedId' => 7]);
    }

    public function testBackoffDelaysTheNextAttempt(): void {
        $feed = $this->feed(900, 3600);
        $feed->setNextAttemptAt(new \DateTime('@' . (self::NOW + 1800)));
        $this->feedMapper->method('findById')->willReturn($feed);
        $this->syncService->expects(self::never())->method('syncFeed');

        $this->runJob($this->job(), ['feedId' => 7]);
    }

    public function testFeedIsRetriedOnceTheBackoffHasPassed(): void {
        $feed = $this->feed(900, 3600);
        $feed->setNextAttemptAt(new \DateTime('@' . (self::NOW - 10)));
        $this->feedMapper->method('findById')->willReturn($feed);
        $this->syncService->expects(self::once())->method('syncFeed');

        $this->runJob($this->job(), ['feedId' => 7]);
    }

    public function testJobOfDeletedFeedRemovesItself(): void {
        $this->feedMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));
        $this->syncService->expects(self::never())->method('syncFeed');

        $job = $this->job();
        $this->jobList->expects(self::once())->method('remove')->with($job, ['feedId' => 7]);

        $this->runJob($job, ['feedId' => 7]);
    }
}
