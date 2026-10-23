<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\FeedFetchException;
use OCA\CalendarBridge\Service\FeedService;
use OCP\IAppConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedServiceTest extends TestCase {
    private FeedService $service;
    private IClientService&MockObject $clientService;
    private IRemoteHostValidator&MockObject $hostValidator;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();

        $this->clientService = $this->createMock(IClientService::class);
        $this->hostValidator = $this->createMock(IRemoteHostValidator::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->service = new FeedService(
            $this->clientService,
            $this->hostValidator,
            $this->appConfig,
            $this->logger,
        );
    }

    public function testWebcalUrlNormalisedToHttps(): void {
        $this->hostValidator->method('isValid')->willReturn(true);
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->appConfig->method('getValueInt')->willReturnMap([
            ['calendarbridge', 'http_timeout', 30, 30],
            ['calendarbridge', 'max_feed_size_mb', 10, 10],
        ]);

        $validIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR";

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($validIcs);
        $response->method('getHeader')->willReturn('');

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('https://'), $this->anything())
            ->willReturn($response);

        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service->fetch('webcal://example.org/cal.ics');
        $this->assertFalse($result['notModified']);
    }

    public function testHttpUrlRejectedWhenDisabled(): void {
        $this->appConfig->method('getValueBool')->willReturn(false); // allow_http = false
        $this->hostValidator->method('isValid')->willReturn(true);

        $this->expectException(FeedFetchException::class);
        $this->expectExceptionMessageMatches('/HTTP feeds are disabled/');

        $this->service->fetch('http://example.org/cal.ics');
    }

    public function testSsrfBlockedByHostValidator(): void {
        $this->appConfig->method('getValueBool')->willReturn(true); // allow_http = true
        $this->hostValidator->method('isValid')->willReturn(false); // blocked

        $this->expectException(FeedFetchException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->service->fetch('http://192.168.1.1/cal.ics');
    }

    public function testNotModifiedReturnsEarlyWithoutParsing(): void {
        $this->hostValidator->method('isValid')->willReturn(true);
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->appConfig->method('getValueInt')->willReturnMap([
            ['calendarbridge', 'http_timeout', 30, 30],
            ['calendarbridge', 'max_feed_size_mb', 10, 10],
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(304);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service->fetch('https://example.org/cal.ics', '"etag123"');
        $this->assertTrue($result['notModified']);
        $this->assertNull($result['calendar']);
    }

    public function testAttendeesStrippedWhenEnabled(): void {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
             . "BEGIN:VEVENT\r\nUID:test@example\r\nSUMMARY:Test\r\n"
             . "ATTENDEE:mailto:person@example.org\r\n"
             . "ORGANIZER:mailto:org@example.org\r\n"
             . "END:VEVENT\r\nEND:VCALENDAR";

        $calendar = \Sabre\VObject\Reader::read($ics);
        $sanitized = $this->service->sanitize($calendar, true);

        foreach ($sanitized->VEVENT as $event) {
            $this->assertNull($event->ATTENDEE, 'ATTENDEE should be removed');
            $this->assertNull($event->ORGANIZER, 'ORGANIZER should be removed');
        }
    }
}
