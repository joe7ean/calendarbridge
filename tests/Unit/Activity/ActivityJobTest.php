<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Activity;

use OCA\CalendarBridge\Activity\ActivityService;
use OCA\CalendarBridge\BackgroundJob\ActivityJob;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActivityJobTest extends TestCase {
    public function testPausedFeedIsReloadedUnderLockAndFlushed(): void {
        $feed = new Feed();
        $feed->setId(42);
        $feed->setActive(0);
        $feeds = $this->createMock(FeedMapper::class);
        $feeds->method('findAll')->willReturn([$feed]);
        $feeds->expects(self::once())->method('findById')->with(42)->willReturn($feed);
        $feeds->expects(self::once())->method('update')->with($feed);
        $activities = $this->createMock(ActivityService::class);
        $activities->expects(self::once())->method('flush')->with($feed);
        $locks = $this->createMock(ILockingProvider::class);
        $locks->expects(self::once())->method('acquireLock')->with('calendarbridge/feed/42', ILockingProvider::LOCK_EXCLUSIVE);
        $locks->expects(self::once())->method('releaseLock')->with('calendarbridge/feed/42', ILockingProvider::LOCK_EXCLUSIVE);
        $job = new ActivityJob($this->createMock(ITimeFactory::class), $feeds, $activities, $locks, $this->createMock(LoggerInterface::class));
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }

    public function testBusyFeedIsSkippedWithoutReadingOrReleasingItsLock(): void {
        $feed = new Feed();
        $feed->setId(42);
        $feeds = $this->createMock(FeedMapper::class);
        $feeds->method('findAll')->willReturn([$feed]);
        $feeds->expects(self::never())->method('findById');
        $activities = $this->createMock(ActivityService::class);
        $activities->expects(self::never())->method('flush');
        $locks = $this->createMock(ILockingProvider::class);
        $locks->method('acquireLock')->willThrowException(new LockedException('calendarbridge/feed/42'));
        $locks->expects(self::never())->method('releaseLock');
        $job = new ActivityJob($this->createMock(ITimeFactory::class), $feeds, $activities, $locks, $this->createMock(LoggerInterface::class));
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }
}
