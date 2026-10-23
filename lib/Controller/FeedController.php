<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Controller;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\BackgroundJob\SyncJob;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\CalendarService;
use OCA\CalendarBridge\Service\FeedFetchException;
use OCA\CalendarBridge\Service\FeedService;
use OCA\CalendarBridge\Service\SyncService;
use OCA\CalendarBridge\Settings\AdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;

class FeedController extends Controller {
    public function __construct(
        IRequest $request,
        private readonly FeedMapper $feedMapper,
        private readonly FeedService $feedService,
        private readonly SyncService $syncService,
        private readonly CalendarService $calendarService,
        private readonly IJobList $jobList,
        private readonly ICrypto $crypto,
        private readonly IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function index(): DataResponse {
        $feeds = $this->feedMapper->findAll();
        $data  = array_map(fn(Feed $f) => $f->toApiArray(), $feeds);

        // Warn about duplicate URLs across different entries
        $urlCounts = array_count_values(array_column($data, 'feedUrl'));
        foreach ($data as &$feed) {
            $feed['duplicateUrlWarning'] = ($urlCounts[$feed['feedUrl']] ?? 1) > 1;
        }

        return new DataResponse($data);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function create(
        string $feedUrl,
        string $feedName,
        string $serviceUser,
        array $targetGroups = [],
        int $syncInterval = 3600,
        string $calendarColor = '#0082c9',
        bool $stripAttendees = true,
        bool $verifySsl = true,
        bool $active = true,
        ?string $httpAuthUser = null,
        ?string $httpAuthPass = null,
    ): DataResponse {
        $feed = new Feed();
        $feed->setFeedUrl($feedUrl);
        $feed->setFeedName($feedName);
        $feed->setServiceUser($serviceUser);
        $feed->setTargetGroupsArray($targetGroups);
        $feed->setSyncInterval(max(900, $syncInterval));
        $feed->setCalendarColor($calendarColor);
        $feed->setStripAttendees($stripAttendees ? 1 : 0);
        $feed->setVerifySsl($verifySsl ? 1 : 0);
        $feed->setActive($active ? 1 : 0);

        if ($httpAuthUser !== null && $httpAuthUser !== '') {
            $feed->setHttpAuthUser($httpAuthUser);
        }
        if ($httpAuthPass !== null && $httpAuthPass !== '') {
            $feed->setHttpAuthPass($this->crypto->encrypt($httpAuthPass));
        }

        try {
            $saved = $this->feedMapper->insert($feed);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                return new DataResponse(
                    ['error' => 'A feed with this URL already exists for this service user.'],
                    Http::STATUS_CONFLICT
                );
            }
            throw $e;
        }

        // Register a dedicated background job for this feed
        $this->jobList->add(SyncJob::class, ['feedId' => $saved->getId()]);

        return new DataResponse($saved->toApiArray(), Http::STATUS_CREATED);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function show(int $id): DataResponse {
        try {
            return new DataResponse($this->feedMapper->findById($id)->toApiArray());
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Feed not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function update(
        int $id,
        ?string $feedUrl = null,
        ?string $feedName = null,
        ?string $serviceUser = null,
        ?array $targetGroups = null,
        ?int $syncInterval = null,
        ?string $calendarColor = null,
        ?bool $stripAttendees = null,
        ?bool $verifySsl = null,
        ?bool $active = null,
        ?string $httpAuthUser = null,
        ?string $httpAuthPass = null,
        bool $clearHttpAuth = false,
    ): DataResponse {
        try {
            $feed = $this->feedMapper->findById($id);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Feed not found'], Http::STATUS_NOT_FOUND);
        }

        if ($feedUrl !== null)       $feed->setFeedUrl($feedUrl);
        if ($feedName !== null)      $feed->setFeedName($feedName);
        if ($serviceUser !== null)   $feed->setServiceUser($serviceUser);
        if ($targetGroups !== null)  $feed->setTargetGroupsArray($targetGroups);
        if ($syncInterval !== null)  $feed->setSyncInterval(max(900, $syncInterval));
        if ($calendarColor !== null) $feed->setCalendarColor($calendarColor);
        if ($stripAttendees !== null) $feed->setStripAttendees($stripAttendees ? 1 : 0);
        if ($verifySsl !== null)     $feed->setVerifySsl($verifySsl ? 1 : 0);
        if ($active !== null)        $feed->setActive($active ? 1 : 0);

        if ($clearHttpAuth) {
            $feed->setHttpAuthUser(null);
            $feed->setHttpAuthPass(null);
        } elseif ($httpAuthUser !== null) {
            $feed->setHttpAuthUser($httpAuthUser !== '' ? $httpAuthUser : null);
            if ($httpAuthPass !== null && $httpAuthPass !== '') {
                $feed->setHttpAuthPass($this->crypto->encrypt($httpAuthPass));
            }
        }

        $this->feedMapper->update($feed);

        // Re-apply group sharing in case groups changed
        if ($targetGroups !== null && $feed->getCalendarId() !== null) {
            $this->calendarService->shareWithGroups($feed->getCalendarId(), $feed->getTargetGroupsArray());
        }

        return new DataResponse($feed->toApiArray());
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function destroy(int $id): DataResponse {
        try {
            $feed = $this->feedMapper->findById($id);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Feed not found'], Http::STATUS_NOT_FOUND);
        }

        // Delete the relay calendar and all its events
        if ($feed->getCalendarId() !== null) {
            $this->calendarService->deleteCalendar($feed->getCalendarId());
        }

        $this->feedMapper->delete($feed);

        // Remove the background job for this feed
        $this->jobList->remove(SyncJob::class, ['feedId' => $id]);

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Feed actions
    // -------------------------------------------------------------------------

    /**
     * Test a feed URL without saving anything.
     * Rate-limited: 5 requests per minute per admin.
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    #[UserRateLimit(limit: 5, period: 60)]
    public function preview(
        string $url,
        ?string $httpAuthUser = null,
        ?string $httpAuthPass = null,
        bool $verifySsl = true,
    ): DataResponse {
        try {
            $result = $this->feedService->preview($url, $httpAuthUser, $httpAuthPass, $verifySsl);
            return new DataResponse($result);
        } catch (FeedFetchException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    /**
     * Trigger an immediate sync for an existing feed.
     * Rate-limited: 3 fetches per 5 minutes per source server.
     * The per-feed timestamp is also used to prevent an immediate retry of
     * the same feed.
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function manualSync(int $id): DataResponse {
        try {
            $feed = $this->feedMapper->findById($id);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Feed not found'], Http::STATUS_NOT_FOUND);
        }

        $cooldown = 300;
        $now = time();
        $serverKey = $this->sourceServerKey($feed->getFeedUrl());
        $recentServerFetches = 0;

        foreach ($this->feedMapper->findAll() as $candidate) {
            if ($this->sourceServerKey($candidate->getFeedUrl()) !== $serverKey) {
                continue;
            }

            $lastManual = $candidate->getLastManualSyncAt();
            if ($lastManual !== null && ($now - $lastManual->getTimestamp()) < $cooldown) {
                $recentServerFetches++;
            }
        }

        if ($recentServerFetches >= 3) {
            return new DataResponse(
                [
                    'error' => $this->l->t(
                        'The source server "%s" has already been queried three times in the last 5 minutes. '
                        . 'Please wait a moment before syncing another calendar from this server.',
                        [$serverKey]
                    ),
                    'retryAfter' => $cooldown,
                ],
                Http::STATUS_TOO_MANY_REQUESTS
            );
        }

        $lastManual = $feed->getLastManualSyncAt();
        if ($lastManual !== null) {
            $secondsSince = $now - $lastManual->getTimestamp();
            if ($secondsSince < $cooldown) {
                $remaining = $cooldown - $secondsSince;
                return new DataResponse(
                    [
                        'error' => $this->l->t(
                            'This calendar was synced only a moment ago. Please wait %d more seconds.',
                            [$remaining]
                        ),
                        'retryAfter' => $remaining,
                    ],
                    Http::STATUS_TOO_MANY_REQUESTS
                );
            }
        }

        $feed->setLastManualSyncAt(new \DateTime());
        $this->feedMapper->update($feed);

        $result = $this->syncService->syncFeed($id);
        if ($result['locked'] ?? false) {
            return new DataResponse(
                ['error' => $this->l->t('This calendar is being synced right now. Please try again shortly.')],
                Http::STATUS_CONFLICT
            );
        }
        return new DataResponse($result);
    }

    private function sourceServerKey(string $url): string {
        $parts = parse_url($url);
        if ($parts !== false && isset($parts['host'])) {
            $host = strtolower($parts['host']);
            $port = $parts['port'] ?? null;
            return $port === null ? $host : $host . ':' . $port;
        }

        // Feed URLs are validated before saving. Keep a deterministic fallback
        // for legacy rows that contain an unparsable URL.
        return strtolower($url);
    }
}
