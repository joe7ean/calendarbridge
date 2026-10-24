<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\CalendarService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class CalendarServiceTest extends TestCase {
    /**
     * @param list<string> $trashedUris URIs returned by the trash-bin query
     */
    private function service(CalDavBackend&MockObject $calDav, array $trashedUris = []): CalendarService {
        $rows = array_map(static fn (string $uri): array => ['uri' => $uri], $trashedUris);
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'delete', 'insert', 'values'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturn($result);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        return new CalendarService(
            $calDav,
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Run a sync against an empty calendar and return the data written per URI.
     *
     * @return array<string, string>
     */
    private function captureCreated(string $feed): array {
        $created = [];
        $calDav = $this->createMock(CalDavBackend::class);
        $calDav->method('getCalendarObjects')->willReturn([]);
        $calDav->method('createCalendarObject')->willReturnCallback(
            static function ($calendarId, $uri, $data) use (&$created) {
                $created[$uri] = $data;
                return null;
            },
        );
        $this->service($calDav)->syncEvents(1, Reader::read($feed));
        return $created;
    }

    /**
     * Mock whose calendar already contains the given objects (uri => data).
     *
     * @param array<string, string> $objects
     */
    private function calDavWith(array $objects): CalDavBackend&MockObject {
        $calDav = $this->createMock(CalDavBackend::class);
        $calDav->method('getCalendarObjects')->willReturn(
            array_map(static fn (string $uri): array => ['uri' => $uri], array_keys($objects)),
        );
        $calDav->method('getMultipleCalendarObjects')->willReturn(
            array_map(
                static fn (string $uri, string $data): array => ['uri' => $uri, 'calendardata' => $data],
                array_keys($objects),
                array_values($objects),
            ),
        );
        return $calDav;
    }

    private const SERIES = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//EN
BEGIN:VTIMEZONE
TZID:Europe/Berlin
BEGIN:STANDARD
DTSTART:19701025T030000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
END:STANDARD
END:VTIMEZONE
BEGIN:VTIMEZONE
TZID:Unused/Zone
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0000
TZOFFSETTO:+0000
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:series@example
DTSTAMP:20260101T000000Z
RECURRENCE-ID;TZID=Europe/Berlin:20260112T090000
DTSTART;TZID=Europe/Berlin:20260112T100000
SUMMARY:Moved occurrence
END:VEVENT
BEGIN:VEVENT
UID:series@example
DTSTAMP:20260101T000000Z
DTSTART;TZID=Europe/Berlin:20260105T090000
RRULE:FREQ=WEEKLY;COUNT=10
EXDATE;TZID=Europe/Berlin:20260119T090000
SUMMARY:Weekly
END:VEVENT
BEGIN:VEVENT
UID:series@example
DTSTAMP:20260101T000000Z
RECURRENCE-ID;TZID=Europe/Berlin:20260126T090000
DTSTART;TZID=Europe/Berlin:20260126T110000
SUMMARY:Another override
END:VEVENT
BEGIN:VEVENT
UID:single@example
DTSTAMP:20260101T000000Z
DTSTART:20260201T120000Z
SUMMARY:Single
END:VEVENT
END:VCALENDAR
ICS;

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

        $m = new \ReflectionMethod(CalendarService::class, 'extractUid');
        self::assertSame(
            '6529b8ab-1fe2-4f14-9ab9-22ac6bb7594b',
            $m->invoke($this->service($this->createMock(CalDavBackend::class)), $ics),
        );
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

        $m = new \ReflectionMethod(CalendarService::class, 'extractUid');
        self::assertSame(
            'test-uid@example.org',
            $m->invoke($this->service($this->createMock(CalDavBackend::class)), $ics),
        );
    }

    public function testRecurringSeriesIsStoredAsOneObjectWithAllOverrides(): void {
        $created = $this->captureCreated(self::SERIES);

        self::assertSame(['series@example.ics', 'single@example.ics'], array_keys($created));

        $series = Reader::read($created['series@example.ics']);
        self::assertCount(3, $series->VEVENT);
        // Master first, then overrides in RECURRENCE-ID order.
        self::assertFalse(isset($series->VEVENT[0]->{'RECURRENCE-ID'}));
        self::assertSame('Weekly', (string)$series->VEVENT[0]->SUMMARY);
        self::assertSame('Moved occurrence', (string)$series->VEVENT[1]->SUMMARY);
        self::assertSame('Another override', (string)$series->VEVENT[2]->SUMMARY);
        // Only the referenced time zone is included.
        self::assertCount(1, $series->VTIMEZONE);
        self::assertSame('Europe/Berlin', (string)$series->VTIMEZONE->TZID);

        $single = Reader::read($created['single@example.ics']);
        self::assertCount(1, $single->VEVENT);
        self::assertFalse(isset($single->VTIMEZONE));
    }

    public function testUnchangedFeedWritesNothingEvenWithNewDtstamp(): void {
        $created = $this->captureCreated(self::SERIES);

        $calDav = $this->calDavWith($created);
        $calDav->expects(self::never())->method('createCalendarObject');
        $calDav->expects(self::never())->method('updateCalendarObject');
        $calDav->expects(self::never())->method('deleteCalendarObject');

        $feed = str_replace('DTSTAMP:20260101T000000Z', 'DTSTAMP:20260301T000000Z', self::SERIES);
        $stats = $this->service($calDav)->syncEvents(1, Reader::read($feed));

        self::assertSame(['added' => 0, 'updated' => 0, 'deleted' => 0, 'held' => 0], $stats);
    }

    public function testChangedOverrideUpdatesTheSeriesObject(): void {
        $created = $this->captureCreated(self::SERIES);

        $calDav = $this->calDavWith($created);
        $calDav->expects(self::once())
            ->method('updateCalendarObject')
            ->with(1, 'series@example.ics', self::stringContains('Moved again'));

        $feed = str_replace('Moved occurrence', 'Moved again', self::SERIES);
        $stats = $this->service($calDav)->syncEvents(1, Reader::read($feed));

        self::assertSame(1, $stats['updated']);
    }

    public function testLegacySplitObjectIsRepairedOnNextSync(): void {
        // 0.2.0 stored only the last VEVENT of a series.
        $legacy = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:series@example\r\n"
            . "RECURRENCE-ID:20260126T080000Z\r\nDTSTART:20260126T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $calDav = $this->calDavWith(['series@example.ics' => $legacy]);
        $calDav->expects(self::once())
            ->method('updateCalendarObject')
            ->with(1, 'series@example.ics', self::stringContains('RRULE:FREQ=WEEKLY'));

        $this->service($calDav)->syncEvents(1, Reader::read(self::SERIES));
    }

    public function testObjectMissingFromFeedIsDeletedPermanently(): void {
        $stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:removed@example\r\n"
            . "DTSTART:20200101T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $calDav = $this->calDavWith(['removed@example.ics' => $stored]);
        $calDav->expects(self::once())
            ->method('deleteCalendarObject')
            ->with(1, 'removed@example.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, true);

        $stats = $this->service($calDav)->syncEvents(1, Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"));

        self::assertSame(1, $stats['deleted']);
    }

    public function testDeletionsAreHeldWhenNotApproved(): void {
        $stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:removed@example\r\n"
            . "DTSTART:20200101T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $calDav = $this->calDavWith(['removed@example.ics' => $stored]);
        $calDav->expects(self::never())->method('deleteCalendarObject');

        $asked = null;
        $stats = $this->service($calDav)->syncEvents(
            1,
            Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"),
            function (int $toDelete, int $existing) use (&$asked): bool {
                $asked = [$toDelete, $existing];
                return false;
            },
        );

        self::assertSame([1, 1], $asked);
        self::assertSame(['added' => 0, 'updated' => 0, 'deleted' => 0, 'held' => 1], $stats);
    }

    public function testAdditionsAreAppliedEvenWhileDeletionsAreHeld(): void {
        $stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:old@example\r\n"
            . "DTSTART:20200101T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $calDav = $this->calDavWith(['old@example.ics' => $stored]);
        $calDav->expects(self::once())->method('createCalendarObject');
        $calDav->expects(self::never())->method('deleteCalendarObject');

        $stats = $this->service($calDav)->syncEvents(
            1,
            Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:new@example\r\nDTSTART:20260101T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"),
            static fn (): bool => false,
        );

        self::assertSame(1, $stats['added']);
        self::assertSame(1, $stats['held']);
    }

    public function testTrashedObjectsArePurgedBeforeSync(): void {
        $calDav = $this->createMock(CalDavBackend::class);
        $calDav->method('getCalendarObjects')->willReturn([]);
        $calDav->expects(self::once())
            ->method('deleteCalendarObject')
            ->with(1, 'old@example-deleted.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, true);

        $this->service($calDav, ['old@example-deleted.ics'])
            ->syncEvents(1, Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"));
    }

    public function testUnsafeUidGetsHashedUri(): void {
        $uid = 'weird uid/with?chars';
        $created = $this->captureCreated(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTART:20260101T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertSame([sha1($uid) . '.ics'], array_keys($created));
    }

    public function testSyncResolvesDuplicateViaGetCalendarObject(): void {
        $uid = '6529b8ab-1fe2-4f14-9ab9-22ac6bb7594b';
        $uri = $uid . '.ics';
        $feed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20200102T120000Z\r\n"
            . "SEQUENCE:1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $stored = $this->captureCreated($feed)[$uri];

        $calDav = $this->createMock(CalDavBackend::class);
        $calDav->method('getCalendarObjects')->willReturn([]);
        $calDav->expects(self::once())
            ->method('createCalendarObject')
            ->willThrowException(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'));
        $calDav->expects(self::once())
            ->method('getCalendarObject')
            ->with(1, $uri)
            ->willReturn(['uri' => $uri, 'calendardata' => $stored]);
        $calDav->expects(self::never())->method('updateCalendarObject');
        $calDav->expects(self::never())->method('deleteCalendarObject');

        $stats = $this->service($calDav)->syncEvents(1, Reader::read($feed));

        self::assertSame(['added' => 0, 'updated' => 0, 'deleted' => 0, 'held' => 0], $stats);
    }
}
