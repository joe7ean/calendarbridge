<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\IRemoteHostValidator;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class FeedService {
    private const APP_ID = 'calendarbridge';

    private const MAX_REDIRECTS = 3;
    private const DEFAULT_MAX_EVENTS = 20000;
    private const READ_CHUNK = 65536;

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
     * @throws FeedFetchException on HTTP, size, plausibility or parsing errors
     * @return array{calendar: ?VCalendar, etag: ?string, lastModified: ?string, notModified: bool}
     */
    public function fetch(
        string $url,
        ?string $etag = null,
        ?string $authUser = null,
        ?string $authPass = null,
        bool $verifySsl = true,
        ?string $lastModified = null,
    ): array {
        $url = $this->assertValidUrl($url);

        $timeout   = $this->appConfig->getValueInt(self::APP_ID, 'http_timeout', 30);
        $maxSizeMb = $this->appConfig->getValueInt(self::APP_ID, 'max_feed_size_mb', 10);
        $maxBytes  = $maxSizeMb * 1024 * 1024;

        $headers = ['Accept' => 'text/calendar, application/calendar+json, */*'];
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        $options = [
            'timeout'         => $timeout,
            'verify'          => $verifySsl,
            // Status codes are handled below (304, Retry-After) instead of as exceptions.
            'http_errors'     => false,
            // Read the body incrementally so the size limit holds without buffering it all.
            'stream'          => true,
            // Overriding allow_redirects replaces Nextcloud's own redirect guard, so every
            // redirect target is validated here (scheme + SSRF host check).
            'allow_redirects' => [
                'max'         => self::MAX_REDIRECTS,
                'protocols'   => $this->allowedSchemes(),
                'on_redirect' => function ($request, $response, $uri): void {
                    $this->assertValidUrl((string)$uri);
                },
            ],
            'headers'         => $headers,
        ];
        if ($authUser !== null && $authPass !== null) {
            $options['auth'] = [$authUser, $authPass];
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
        } catch (FeedFetchException $e) {
            throw $e;
        } catch (\Exception $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof FeedFetchException) {
                throw $previous; // rejected redirect target
            }
            throw new FeedFetchException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 304) {
            $this->closeBody($response);
            return ['calendar' => null, 'etag' => $etag, 'lastModified' => $lastModified, 'notModified' => true];
        }

        if ($status === 429 || $status === 503) {
            $this->closeBody($response);
            $retryAfter = $this->parseRetryAfter($response->getHeader('Retry-After'));
            throw new FeedFetchException(
                "HTTP {$status} fetching feed" . ($retryAfter !== null ? " (retry after {$retryAfter} s)" : ''),
                $status,
                null,
                $retryAfter,
            );
        }

        if ($status !== 200) {
            $this->closeBody($response);
            throw new FeedFetchException("HTTP {$status} fetching feed", $status);
        }

        $contentLength = $response->getHeader('Content-Length');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int)$contentLength > $maxBytes) {
            $this->closeBody($response);
            throw new FeedFetchException("Feed exceeds maximum size of {$maxSizeMb} MB");
        }

        $body = $this->readBody($response, $maxBytes, $maxSizeMb);

        $contentType = $response->getHeader('Content-Type');
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

        $maxEvents = $this->appConfig->getValueInt(self::APP_ID, 'max_events', self::DEFAULT_MAX_EVENTS);
        $eventCount = count($calendar->VEVENT ?? []);
        if ($eventCount > $maxEvents) {
            throw new FeedFetchException("Feed contains {$eventCount} events, more than the limit of {$maxEvents}");
        }

        $newEtag = $response->getHeader('ETag');
        $newLastModified = $response->getHeader('Last-Modified');

        return [
            'calendar'     => $calendar,
            'etag'         => $newEtag !== '' ? $newEtag : null,
            'lastModified' => $newLastModified !== '' ? $newLastModified : null,
            'notModified'  => false,
        ];
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

    /**
     * Normalise a feed URL (webcal → https) and check scheme and host.
     *
     * @throws FeedFetchException when the URL may not be fetched
     * @return string the normalised URL
     */
    public function assertValidUrl(string $url): string {
        // webcal:// and webcals:// are just HTTP aliases used by calendar clients
        $url = (string)preg_replace('#^webcals?://#i', 'https://', trim($url));

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'http' && !$this->appConfig->getValueBool(self::APP_ID, 'allow_http', false)) {
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

        return $url;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function allowedSchemes(): array {
        return $this->appConfig->getValueBool(self::APP_ID, 'allow_http', false)
            ? ['http', 'https']
            : ['https'];
    }

    private function readBody(IResponse $response, int $maxBytes, int $maxSizeMb): string {
        $body = $response->getBody();
        if (!is_resource($body)) {
            $body = (string)$body;
            if (strlen($body) > $maxBytes) {
                throw new FeedFetchException("Feed exceeds maximum size of {$maxSizeMb} MB");
            }
            return $body;
        }

        $data = '';
        try {
            while (!feof($body)) {
                $chunk = fread($body, self::READ_CHUNK);
                if ($chunk === false) {
                    throw new FeedFetchException('Reading the feed failed');
                }
                $data .= $chunk;
                if (strlen($data) > $maxBytes) {
                    throw new FeedFetchException("Feed exceeds maximum size of {$maxSizeMb} MB");
                }
            }
        } finally {
            fclose($body);
        }
        return $data;
    }

    private function closeBody(IResponse $response): void {
        $body = $response->getBody();
        if (is_resource($body)) {
            fclose($body);
        }
    }

    /**
     * Retry-After is either delta-seconds or an HTTP date. Capped at one day.
     */
    private function parseRetryAfter(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $seconds = (int)$value;
        } else {
            $ts = strtotime($value);
            if ($ts === false) {
                return null;
            }
            $seconds = $ts - time();
        }
        return max(0, min($seconds, 86400));
    }

    private function isCalendarContentType(string $contentType): bool {
        return str_contains($contentType, 'text/calendar')
            || str_contains($contentType, 'application/calendar');
    }

    private function looksLikeIcal(string $body): bool {
        return str_starts_with(ltrim($body), 'BEGIN:VCALENDAR');
    }
}
