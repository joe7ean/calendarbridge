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
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class SyncServiceTest extends TestCase {
    public function testEmptyFeedIsRejectedWithoutDeletingEvents(): void {
        $feed = new Feed();
        $feed->setId(42);
        $feed->setFeedUrl('https://example.org/calendar.ics');
        $feed->setFeedName('Example');
        $feed->setServiceUser('calendar-service');
        $feed->setCalendarId(99);

        $feedMapper = $this->createMock(FeedMapper::class);
        $feedMapper->expects(self::once())
            ->method('findById')
            ->with(42)
            ->willReturn($feed);
        $feedMapper->expects(self::once())->method('update')->with($feed);

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects(self::once())
            ->method('fetch')
            ->willReturn([
                'calendar' => Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"),
                'etag' => 'empty-feed',
                'notModified' => false,
            ]);
        $feedService->expects(self::once())
            ->method('sanitize')
            ->willReturnCallback(static fn($calendar, bool $stripAttendees) => $calendar);

        $calendarService = $this->createMock(CalendarService::class);
        $calendarService->expects(self::once())->method('shareWithGroups')->with(99, []);
        $calendarService->expects(self::never())->method('syncEvents');

        $service = new SyncService(
            $feedMapper,
            $feedService,
            $calendarService,
            $this->createMock(NotificationService::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(ICrypto::class),
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->syncFeed(42);

        self::assertSame(0, $result['added']);
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $feed->getLastStatus());
        self::assertSame(
            'Feed contains no events; sync skipped to protect the relay calendar.',
            $feed->getLastError()
        );
    }
}
