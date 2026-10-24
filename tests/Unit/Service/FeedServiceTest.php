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
            ['calendarbridge', 'max_events', 20000, 20000],
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

    /**
     * @param array<string, string> $headers
     * @param string|resource $body
     */
    private function respond(int $status, mixed $body = '', array $headers = [], ?array &$options = null): void {
        $this->hostValidator->method('isValid')->willReturn(true);
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default) => $key === 'max_feed_size_mb' ? 1 : ($key === 'max_events' ? 2 : $default),
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        $response->method('getHeader')->willReturnCallback(static fn (string $h) => $headers[$h] ?? '');

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function (string $url, array $opts) use (&$options, $response) {
            $options = $opts;
            return $response;
        });
        $this->clientService->method('newClient')->willReturn($client);
    }

    public function testRedirectTargetsAreValidatedAgainstSsrf(): void {
        $options = null;
        $this->respond(304, '', [], $options);
        $this->service->fetch('https://example.org/cal.ics');

        self::assertFalse($options['http_errors']);
        self::assertSame(['https'], $options['allow_redirects']['protocols']);
        $onRedirect = $options['allow_redirects']['on_redirect'];
        self::assertIsCallable($onRedirect);

        // A redirect to a scheme or host that is not allowed aborts the request.
        $this->expectException(FeedFetchException::class);
        $onRedirect(null, null, 'http://127.0.0.1/admin');
    }

    public function testConditionalHeadersAreSent(): void {
        $options = null;
        $this->respond(304, '', [], $options);
        $this->service->fetch('https://example.org/cal.ics', '"e"', null, null, true, 'Sat, 24 Oct 2026 10:00:00 GMT');

        self::assertSame('"e"', $options['headers']['If-None-Match']);
        self::assertSame('Sat, 24 Oct 2026 10:00:00 GMT', $options['headers']['If-Modified-Since']);
    }

    public function testRetryAfterIsReportedOn429(): void {
        $this->respond(429, '', ['Retry-After' => '120']);
        try {
            $this->service->fetch('https://example.org/cal.ics');
            self::fail('Expected FeedFetchException');
        } catch (FeedFetchException $e) {
            self::assertSame(120, $e->getRetryAfter());
            self::assertSame(429, $e->getCode());
        }
    }

    public function testStreamedBodyOverTheSizeLimitIsRejected(): void {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "BEGIN:VCALENDAR\r\n" . str_repeat('X', 1024 * 1024 + 10));
        rewind($stream);
        $this->respond(200, $stream, ['Content-Type' => 'text/calendar']);

        $this->expectException(FeedFetchException::class);
        $this->expectExceptionMessageMatches('/maximum size/');
        $this->service->fetch('https://example.org/cal.ics');
    }

    public function testDeclaredContentLengthOverTheLimitIsRejectedBeforeReading(): void {
        $this->respond(200, '', ['Content-Length' => (string)(5 * 1024 * 1024)]);

        $this->expectException(FeedFetchException::class);
        $this->expectExceptionMessageMatches('/maximum size/');
        $this->service->fetch('https://example.org/cal.ics');
    }

    public function testTooManyEventsAreRejected(): void {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
        foreach (['a', 'b', 'c'] as $uid) {
            $ics .= "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTART:20260101T100000Z\r\nEND:VEVENT\r\n";
        }
        $this->respond(200, $ics . "END:VCALENDAR\r\n", ['Content-Type' => 'text/calendar']);

        $this->expectException(FeedFetchException::class);
        $this->expectExceptionMessageMatches('/more than the limit of 2/');
        $this->service->fetch('https://example.org/cal.ics');
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
