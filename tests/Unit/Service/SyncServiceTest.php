<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Notification\NotificationService;
use OCA\CalendarBridge\Service\CalendarService;
use OCA\CalendarBridge\Service\FeedFetchException;
use OCA\CalendarBridge\Service\FeedService;
use OCA\CalendarBridge\Service\Quarantine;
use OCA\CalendarBridge\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class SyncServiceTest extends TestCase {
    private const NOW = 1_800_000_000;

    private FeedMapper&MockObject $feedMapper;
    private FeedService&MockObject $feedService;
    private CalendarService&MockObject $calendarService;
    private NotificationService&MockObject $notifications;
    private ICrypto&MockObject $crypto;
    private ILockingProvider&MockObject $lockingProvider;
    private IUserManager&MockObject $userManager;
    private Feed $feed;

    protected function setUp(): void {
        parent::setUp();
        $this->feedMapper      = $this->createMock(FeedMapper::class);
        $this->feedService     = $this->createMock(FeedService::class);
        $this->calendarService = $this->createMock(CalendarService::class);
        $this->notifications   = $this->createMock(NotificationService::class);
        $this->crypto          = $this->createMock(ICrypto::class);
        $this->lockingProvider = $this->createMock(ILockingProvider::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->userManager->method('userExists')->willReturn(true);

        $this->feed = new Feed();
        $this->feed->setId(42);
        $this->feed->setFeedUrl('https://example.org/calendar.ics');
        $this->feed->setFeedName('Example');
        $this->feed->setServiceUser('calendar-service');
        $this->feed->setCalendarId(99);
        $this->feedMapper->method('findById')->willReturn($this->feed);
        $this->calendarService->method('ensureCalendar')->willReturn(99);
        $this->feedService->method('sanitize')->willReturnArgument(0);
    }

    private function service(): SyncService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(static fn ($app, $key, $default) => $default);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        return new SyncService(
            $this->feedMapper,
            $this->feedService,
            $this->calendarService,
            $this->notifications,
            new Quarantine($appConfig, $time),
            $this->crypto,
            $this->lockingProvider,
            $time,
            $this->userManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testMissingServiceUserIsAClearError(): void {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userManager->method('userExists')->willReturn(false);
        $this->feedService->expects(self::never())->method('fetch');

        $this->service()->syncFeed(42);

        self::assertSame(Feed::STATUS_ERROR, $this->feed->getLastStatus());
        self::assertStringContainsString('Service user "calendar-service" does not exist', (string)$this->feed->getLastError());
    }

    private function feedReturns(string $ics, ?string $etag = 'etag'): void {
        $this->feedService->method('fetch')->willReturn([
            'calendar'     => Reader::read($ics),
            'etag'         => $etag,
            'lastModified' => 'Sat, 24 Oct 2026 10:00:00 GMT',
            'notModified'  => false,
        ]);
    }

    /** Let syncEvents ask for approval of $toDelete out of $existing objects. */
    private function relayDeletes(int $toDelete, int $existing): void {
        $this->calendarService->method('syncEvents')->willReturnCallback(
            static function ($calendarId, $calendar, ?callable $approve) use ($toDelete, $existing): array {
                $ok = $approve === null || $approve($toDelete, $existing);
                return ['added' => 0, 'updated' => 0, 'deleted' => $ok ? $toDelete : 0, 'held' => $ok ? 0 : $toDelete];
            },
        );
    }

    public function testEmptyFeedIsQuarantinedNotAnErrorAndNothingIsDeleted(): void {
        $this->feedReturns("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");
        $this->relayDeletes(5, 5);
        $this->notifications->expects(self::once())->method('notifyQuarantine')->with($this->feed);

        $result = $this->service()->syncFeed(42);

        self::assertSame(0, $result['deleted']);
        self::assertSame(5, $result['held']);
        self::assertSame(Feed::STATUS_QUARANTINE, $this->feed->getLastStatus());
        self::assertSame(0, $this->feed->getConsecutiveErrors());
        self::assertSame(Quarantine::REASON_EMPTY, $this->feed->getQuarantineReason());
        self::assertSame('Sat, 24 Oct 2026 10:00:00 GMT', $this->feed->getLastModified());
    }

    public function testQuarantinedFeedIsFetchedWithoutConditionalHeaders(): void {
        $this->feed->setLastEtag('"old"');
        $this->feed->setLastModified('old');
        $this->feed->setQuarantineHash('h');
        $this->feedService->expects(self::once())
            ->method('fetch')
            ->with(self::anything(), null, self::anything(), self::anything(), self::anything(), null)
            ->willReturn(['calendar' => null, 'etag' => null, 'lastModified' => null, 'notModified' => true]);

        $this->service()->syncFeed(42);
    }

    public function testNormalSyncClearsAnEarlierQuarantine(): void {
        $this->feed->setQuarantineHash('h');
        $this->feed->setQuarantineReason('empty');
        $this->feedReturns("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20260101T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->relayDeletes(0, 1);
        $this->notifications->expects(self::once())->method('dismissQuarantine')->with(42);

        $this->service()->syncFeed(42);

        self::assertFalse($this->feed->isInQuarantine());
        self::assertSame(Feed::STATUS_OK, $this->feed->getLastStatus());
    }

    public function testEventsWithoutAnyUidFailTheSync(): void {
        $this->feedReturns("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART:20260101T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->calendarService->expects(self::never())->method('syncEvents');

        $this->service()->syncFeed(42);

        self::assertSame(Feed::STATUS_ERROR, $this->feed->getLastStatus());
        self::assertStringContainsString('none with a UID', (string)$this->feed->getLastError());
    }

    public function testErrorsBackOffExponentiallyAndHonourRetryAfter(): void {
        $this->feed->setSyncInterval(3600);
        $this->feedService->method('fetch')->willThrowException(new FeedFetchException('HTTP 500 fetching feed'));

        $this->service()->syncFeed(42);
        self::assertSame(self::NOW + 3600, $this->feed->getNextAttemptAt()->getTimestamp());

        $this->service()->syncFeed(42);
        self::assertSame(self::NOW + 7200, $this->feed->getNextAttemptAt()->getTimestamp());

        $this->feed->setConsecutiveErrors(8);
        $this->service()->syncFeed(42);
        self::assertSame(self::NOW + 86400, $this->feed->getNextAttemptAt()->getTimestamp(), 'capped at one day');
    }

    public function testRetryAfterExtendsTheBackoff(): void {
        $this->feed->setSyncInterval(900);
        $this->feedService->method('fetch')->willThrowException(
            new FeedFetchException('HTTP 429 fetching feed', 429, null, 7200),
        );

        $this->service()->syncFeed(42);

        self::assertSame(self::NOW + 7200, $this->feed->getNextAttemptAt()->getTimestamp());
    }

    public function testConcurrentSyncOfSameFeedIsSkipped(): void {
        $this->lockingProvider->method('acquireLock')
            ->with('calendarbridge/feed/42', ILockingProvider::LOCK_EXCLUSIVE)
            ->willThrowException(new LockedException('calendarbridge/feed/42'));
        $this->lockingProvider->expects(self::never())->method('releaseLock');
        $this->feedService->expects(self::never())->method('fetch');

        $result = $this->service()->syncFeed(42);

        self::assertTrue($result['skipped']);
        self::assertTrue($result['locked']);
    }

    public function testLockIsReleasedAfterSync(): void {
        $this->feedService->method('fetch')->willReturn(['calendar' => null, 'etag' => 'x', 'lastModified' => null, 'notModified' => true]);
        $this->lockingProvider->expects(self::once())
            ->method('releaseLock')
            ->with('calendarbridge/feed/42', ILockingProvider::LOCK_EXCLUSIVE);

        self::assertFalse($this->service()->syncFeed(42)['skipped']);
    }

    public function testUndecryptablePasswordFailsInsteadOfFetchingWithoutAuth(): void {
        $this->feed->setHttpAuthUser('user');
        $this->feed->setHttpAuthPass('garbage');
        $this->crypto->method('decrypt')->willThrowException(new \Exception('HMAC does not match.'));
        $this->feedService->expects(self::never())->method('fetch');

        $this->service()->syncFeed(42);

        self::assertSame(Feed::STATUS_ERROR, $this->feed->getLastStatus());
        self::assertStringContainsString('could not be decrypted', (string)$this->feed->getLastError());
    }

    public function testRejectMarksTheQuarantinedResult(): void {
        $this->feed->setQuarantineHash('h');
        $this->feedMapper->expects(self::once())->method('update')->with($this->feed);

        $this->service()->rejectQuarantine(42);

        self::assertSame('h', $this->feed->getRejectedHash());
    }
}
