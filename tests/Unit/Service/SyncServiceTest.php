<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Notification\NotificationService;
use OCA\CalendarBridge\Service\CalendarService;
use OCA\CalendarBridge\Service\FeedService;
use OCA\CalendarBridge\Service\SyncService;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class SyncServiceTest extends TestCase {
    private FeedMapper&MockObject $feedMapper;
    private FeedService&MockObject $feedService;
    private CalendarService&MockObject $calendarService;
    private ICrypto&MockObject $crypto;
    private ILockingProvider&MockObject $lockingProvider;

    protected function setUp(): void {
        parent::setUp();
        $this->feedMapper      = $this->createMock(FeedMapper::class);
        $this->feedService     = $this->createMock(FeedService::class);
        $this->calendarService = $this->createMock(CalendarService::class);
        $this->crypto          = $this->createMock(ICrypto::class);
        $this->lockingProvider = $this->createMock(ILockingProvider::class);
    }

    private function service(): SyncService {
        return new SyncService(
            $this->feedMapper,
            $this->feedService,
            $this->calendarService,
            $this->createMock(NotificationService::class),
            $this->createMock(IAppConfig::class),
            $this->crypto,
            $this->lockingProvider,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function feed(): Feed {
        $feed = new Feed();
        $feed->setId(42);
        $feed->setFeedUrl('https://example.org/calendar.ics');
        $feed->setFeedName('Example');
        $feed->setServiceUser('calendar-service');
        $feed->setCalendarId(99);
        return $feed;
    }

    public function testEmptyFeedIsRejectedWithoutDeletingEvents(): void {
        $feed = $this->feed();
        $this->feedMapper->method('findById')->with(42)->willReturn($feed);
        $this->feedMapper->expects(self::once())->method('update')->with($feed);

        $this->feedService->expects(self::once())
            ->method('fetch')
            ->willReturn([
                'calendar' => Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"),
                'etag' => 'empty-feed',
                'notModified' => false,
            ]);
        $this->feedService->method('sanitize')->willReturnArgument(0);

        $this->calendarService->expects(self::once())->method('shareWithGroups')->with(99, []);
        $this->calendarService->expects(self::never())->method('syncEvents');

        $result = $this->service()->syncFeed(42);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $feed->getLastStatus());
        self::assertSame(
            'Feed contains no events; sync skipped to protect the relay calendar.',
            $feed->getLastError()
        );
    }

    public function testConcurrentSyncOfSameFeedIsSkipped(): void {
        $this->lockingProvider->method('acquireLock')
            ->with('calendarbridge/feed/42', ILockingProvider::LOCK_EXCLUSIVE)
            ->willThrowException(new LockedException('calendarbridge/feed/42'));
        $this->lockingProvider->expects(self::never())->method('releaseLock');
        $this->feedMapper->expects(self::never())->method('findById');

        $result = $this->service()->syncFeed(42);

        self::assertTrue($result['skipped']);
        self::assertTrue($result['locked']);
    }

    public function testLockIsReleasedAfterSync(): void {
        $this->feedMapper->method('findById')->willReturn($this->feed());
        $this->feedService->method('fetch')->willReturn(['calendar' => null, 'etag' => 'x', 'notModified' => true]);
        $this->lockingProvider->expects(self::once())->method('acquireLock');
        $this->lockingProvider->expects(self::once())
            ->method('releaseLock')
            ->with('calendarbridge/feed/42', ILockingProvider::LOCK_EXCLUSIVE);

        $result = $this->service()->syncFeed(42);

        self::assertFalse($result['skipped']);
    }

    public function testUndecryptablePasswordFailsInsteadOfFetchingWithoutAuth(): void {
        $feed = $this->feed();
        $feed->setHttpAuthUser('user');
        $feed->setHttpAuthPass('garbage');
        $this->feedMapper->method('findById')->willReturn($feed);
        $this->crypto->method('decrypt')->willThrowException(new \Exception('HMAC does not match.'));
        $this->feedService->expects(self::never())->method('fetch');

        $this->service()->syncFeed(42);

        self::assertSame(1, $feed->getLastStatus());
        self::assertSame(1, $feed->getConsecutiveErrors());
        self::assertStringContainsString('could not be decrypted', (string)$feed->getLastError());
    }
}
