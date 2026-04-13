<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\Security\IRemoteHostValidator;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class FeedService {
    private const APP_ID = 'calendarbridge';

    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly IClientService $clientService,
        private readonly IRemoteHostValidator $remoteHostValidator,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch and parse an iCal feed.
     *
     * @throws FeedFetchException on HTTP or parsing errors
     * @return array{calendar: VCalendar, etag: ?string, notModified: bool}
     */
    public function fetch(
        string $url,
        ?string $etag = null,
        ?string $authUser = null,
        ?string $authPass = null,
        bool $verifySsl = true,
    ): array {
        $url = $this->normaliseUrl($url);
        $this->validateUrl($url);

        $timeout   = $this->appConfig->getValueInt(self::APP_ID, 'http_timeout', 30);
        $maxSizeMb = $this->appConfig->getValueInt(self::APP_ID, 'max_feed_size_mb', 10);

        $headers = ['Accept' => 'text/calendar, application/calendar+json, */*'];
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        $options = [
            'timeout'         => $timeout,
            'verify'          => $verifySsl,
            'allow_redirects' => ['max' => self::MAX_REDIRECTS],
            'headers'         => $headers,
        ];
        if ($authUser !== null && $authPass !== null) {
            $options['auth'] = [$authUser, $authPass];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get($url, $options);
        } catch (\Exception $e) {
            throw new FeedFetchException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 304) {
            return ['calendar' => null, 'etag' => $etag, 'notModified' => true];
        }

        if ($status !== 200) {
            throw new FeedFetchException("HTTP {$status} fetching feed");
        }

        $body = (string)$response->getBody();
        if (strlen($body) > $maxSizeMb * 1024 * 1024) {
            throw new FeedFetchException("Feed exceeds maximum size of {$maxSizeMb} MB");
        }

        $contentType = $response->getHeader('Content-Type') ?? '';
        if (!$this->isCalendarContentType($contentType) && !$this->looksLikeIcal($body)) {
            throw new FeedFetchException(
                "Unexpected content type '{$contentType}' — not a valid iCal feed"
            );
        }

        try {
            $calendar = Reader::read($body, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (\Exception $e) {
            throw new FeedFetchException('Failed to parse iCal data: ' . $e->getMessage(), 0, $e);
        }

        if (!($calendar instanceof VCalendar)) {
            throw new FeedFetchException('Parsed document is not a VCALENDAR');
        }

        $newEtag = $response->getHeader('ETag');

        return ['calendar' => $calendar, 'etag' => $newEtag ?: null, 'notModified' => false];
    }

    /**
     * Sanitize a VCalendar by optionally removing personal data fields.
     */
    public function sanitize(VCalendar $calendar, bool $stripAttendees): VCalendar {
        if (!$stripAttendees) {
            return $calendar;
        }

        foreach ($calendar->VEVENT ?? [] as $event) {
            unset($event->ATTENDEE);
            unset($event->ORGANIZER);
        }

        return $calendar;
    }

    /**
     * Dry-run: fetch a feed and return a summary without writing anything.
     *
     * @throws FeedFetchException
     * @return array{eventCount: int, calendarName: ?string, minDate: ?string, maxDate: ?string}
     */
    public function preview(
        string $url,
        ?string $authUser = null,
        ?string $authPass = null,
        bool $verifySsl = true,
    ): array {
        $result   = $this->fetch($url, null, $authUser, $authPass, $verifySsl);
        $calendar = $result['calendar'];

        $eventCount   = 0;
        $minTimestamp = PHP_INT_MAX;
        $maxTimestamp = 0;

        foreach ($calendar->VEVENT ?? [] as $event) {
            $eventCount++;
            $dtStart = $event->DTSTART?->getDateTime();
            if ($dtStart !== null) {
                $ts = $dtStart->getTimestamp();
                if ($ts < $minTimestamp) {
                    $minTimestamp = $ts;
                }
                if ($ts > $maxTimestamp) {
                    $maxTimestamp = $ts;
                }
            }
        }

        return [
            'eventCount'   => $eventCount,
            'calendarName' => (string)($calendar->{'X-WR-CALNAME'} ?? ''),
            'minDate'      => $minTimestamp < PHP_INT_MAX
                ? (new \DateTimeImmutable("@{$minTimestamp}"))->format(\DateTime::ATOM)
                : null,
            'maxDate'      => $maxTimestamp > 0
                ? (new \DateTimeImmutable("@{$maxTimestamp}"))->format(\DateTime::ATOM)
                : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function normaliseUrl(string $url): string {
        // webcal:// and webcals:// are just HTTP aliases used by calendar clients
        $url = preg_replace('#^webcals?://#', 'https://', $url);
        return $url;
    }

    private function validateUrl(string $url): void {
        $allowHttp = $this->appConfig->getValueBool(self::APP_ID, 'allow_http', false);
        $scheme    = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === 'http' && !$allowHttp) {
            throw new FeedFetchException('HTTP feeds are disabled. Use HTTPS.');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new FeedFetchException("Unsupported URL scheme: {$scheme}");
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null || $host === '') {
            throw new FeedFetchException('Invalid URL: no host found');
        }

        // Nextcloud's built-in SSRF protection — blocks private IPs, loopback, etc.
        if (!$this->remoteHostValidator->isValid($host)) {
            throw new FeedFetchException("Host '{$host}' is not allowed (SSRF protection)");
        }
    }

    private function isCalendarContentType(string $contentType): bool {
        return str_contains($contentType, 'text/calendar')
            || str_contains($contentType, 'application/calendar');
    }

    private function looksLikeIcal(string $body): bool {
        return str_starts_with(ltrim($body), 'BEGIN:VCALENDAR');
    }
}
