<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\CalendarService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class CalendarServiceTest extends TestCase {
    private function serviceWithCalDavMock(CalDavBackend&MockObject $calDav): CalendarService {
        return new CalendarService(
            $calDav,
            $this->createMock(IDBConnection::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testExtractUidParsesFoldedUidWithSabre(): void {
        $ics = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:6529b8ab-1fe2-4f14-9ab9-
 22ac6bb7594b
DTSTAMP:20200101T120000Z
END:VEVENT
END:VCALENDAR
ICS;

        $service = $this->serviceWithCalDavMock($this->createMock(CalDavBackend::class));
        $m = new \ReflectionMethod(CalendarService::class, 'extractUid');
        $m->setAccessible(true);
        self::assertSame('6529b8ab-1fe2-4f14-9ab9-22ac6bb7594b', $m->invoke($service, $ics));
    }

    public function testExtractUidParsesUidWithParams(): void {
        $ics = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID;X-FOO=bar:test-uid@example.org
DTSTAMP:20200101T120000Z
END:VEVENT
END:VCALENDAR
ICS;

        $service = $this->serviceWithCalDavMock($this->createMock(CalDavBackend::class));
        $m = new \ReflectionMethod(CalendarService::class, 'extractUid');
        $m->setAccessible(true);
        self::assertSame('test-uid@example.org', $m->invoke($service, $ics));
    }

    public function testSyncResolvesDuplicateViaGetCalendarObject(): void {
        $calendarId = 30;
        $uid = '6529b8ab-1fe2-4f14-9ab9-22ac6bb7594b';
        $uri = $uid . '.ics';

        $storedIcs = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//EN
BEGIN:VEVENT
UID:$uid
DTSTAMP:20200101T120000Z
SEQUENCE:1
LAST-MODIFIED:20200101T120000Z
END:VEVENT
END:VCALENDAR
ICS;

        $incoming = Reader::read(<<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:$uid
DTSTAMP:20200102T120000Z
SEQUENCE:1
LAST-MODIFIED:20200101T120000Z
END:VEVENT
END:VCALENDAR
ICS
        );

        $calDav = $this->createMock(CalDavBackend::class);
        $calDav->expects(self::once())
            ->method('getCalendarObjects')
            ->with($calendarId)
            ->willReturn([]);

        $calDav->expects(self::once())
            ->method('createCalendarObject')
            ->with($calendarId, $uri, self::isType('string'))
            ->willThrowException(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'));

        $calDav->expects(self::once())
            ->method('getCalendarObject')
            ->with($calendarId, $uri)
            ->willReturn([
                'uri'          => $uri,
                'calendardata' => $storedIcs,
            ]);

        $calDav->expects(self::never())->method('updateCalendarObject');
        $calDav->expects(self::never())->method('deleteCalendarObject');

        $service = $this->serviceWithCalDavMock($calDav);
        $stats = $service->syncEvents($calendarId, $incoming);

        self::assertSame(0, $stats['added']);
        self::assertSame(0, $stats['updated']);
        self::assertSame(0, $stats['deleted']);
    }

    public function testSyncSecondVeventWithSameUidUpdatesInsteadOfSecondCreate(): void {
        $calendarId = 1;
        $uid = 'dup@example';

        $calDav = $this->createMock(CalDavBackend::class);
        $calDav->method('getCalendarObjects')->willReturn([]);

        $calDav->expects(self::once())
            ->method('createCalendarObject')
            ->with($calendarId, $uid . '.ics', self::isType('string'));

        $calDav->expects(self::never())->method('getCalendarObject');

        $calDav->expects(self::once())
            ->method('updateCalendarObject')
            ->with($calendarId, $uid . '.ics', self::isType('string'));

        $incoming = Reader::read(<<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:$uid
SEQUENCE:0
END:VEVENT
BEGIN:VEVENT
UID:$uid
SEQUENCE:1
END:VEVENT
END:VCALENDAR
ICS
        );

        $service = $this->serviceWithCalDavMock($calDav);
        $stats = $service->syncEvents($calendarId, $incoming);

        self::assertSame(1, $stats['added']);
        self::assertSame(1, $stats['updated']);
    }
}
