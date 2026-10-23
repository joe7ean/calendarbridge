<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Notification\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class SyncService {
    // After this many consecutive failures: send admin notification
    private const NOTIFY_AFTER_ERRORS = 3;
    // After this many consecutive failures: auto-pause the feed
    private const PAUSE_AFTER_ERRORS = 10;

    public function __construct(
        private readonly FeedMapper $feedMapper,
        private readonly FeedService $feedService,
        private readonly CalendarService $calendarService,
        private readonly NotificationService $notificationService,
        private readonly IAppConfig $appConfig,
        private readonly ICrypto $crypto,
        private readonly ILockingProvider $lockingProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sync a single feed by ID. Called by SyncJob and manual-sync endpoint.
     * Runs at most once per feed at a time; a concurrent call returns
     * immediately with skipped = locked = true.
     *
     * @return array{added: int, updated: int, deleted: int, durationMs: int, skipped: bool, locked?: bool}
     */
    public function syncFeed(int $feedId): array {
        $lockKey = 'calendarbridge/feed/' . $feedId;
        try {
            $this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            $this->logger->info('CalendarBridge: feed is already being synced', ['feedId' => $feedId]);
            return ['added' => 0, 'updated' => 0, 'deleted' => 0, 'durationMs' => 0, 'skipped' => true, 'locked' => true];
        }

        try {
            return $this->syncFeedUnlocked($feedId);
        } finally {
            $this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * @return array{added: int, updated: int, deleted: int, durationMs: int, skipped: bool}
     */
    private function syncFeedUnlocked(int $feedId): array {
        $startTime = (int)(microtime(true) * 1000);

        try {
            $feed = $this->feedMapper->findById($feedId);
        } catch (DoesNotExistException) {
            $this->logger->warning('CalendarBridge: feed not found for sync', ['feedId' => $feedId]);
            return ['added' => 0, 'updated' => 0, 'deleted' => 0, 'durationMs' => 0, 'skipped' => true];
        }

        if (!$feed->isActive()) {
            return ['added' => 0, 'updated' => 0, 'deleted' => 0, 'durationMs' => 0, 'skipped' => true];
        }

        try {
            $authPass = null;
            if ($feed->getHttpAuthPass() !== null) {
                try {
                    $authPass = $this->crypto->decrypt($feed->getHttpAuthPass());
                } catch (\Exception $e) {
                    // Fetching without the password would only produce a misleading 401.
                    throw new FeedFetchException('Stored HTTP password could not be decrypted; please enter it again.', 0, $e);
                }
            }

            $result = $this->feedService->fetch(
                $feed->getFeedUrl(),
                $feed->getLastEtag(),
                $feed->getHttpAuthUser(),
                $authPass,
                $feed->isVerifySsl(),
            );

            // Ensure relay calendar exists (idempotent)
            $calendarId = $feed->getCalendarId();
            if ($calendarId === null) {
                $calendarId = $this->calendarService->ensureCalendarExists(
                    $feed->getServiceUser(),
                    $feed->getFeedName(),
                    $feed->getCalendarColor() ?? '#0082c9',
                );
                $feed->setCalendarId($calendarId);
                $this->feedMapper->update($feed);
            }

            // Always re-apply group sharing (idempotent — safe to call on every sync)
            $this->calendarService->shareWithGroups($calendarId, $feed->getTargetGroupsArray());

            if ($result['notModified']) {
                $this->logger->debug('CalendarBridge: feed not modified (304)', ['feedId' => $feedId]);
                $this->markSuccess($feed, $result['etag']);
                return ['added' => 0, 'updated' => 0, 'deleted' => 0, 'durationMs' => $this->elapsed($startTime), 'skipped' => false];
            }

            $calendar = $this->feedService->sanitize($result['calendar'], $feed->isStripAttendees());

            // A valid but empty calendar is suspicious: treating it as the
            // source of truth would delete every locally mirrored event.
            if (count($calendar->VEVENT ?? []) === 0) {
                throw new FeedFetchException('Feed contains no events; sync skipped to protect the relay calendar.');
            }

            // Sync events
            $stats = $this->calendarService->syncEvents($calendarId, $calendar);

            $this->logger->info('CalendarBridge: sync completed', [
                'feedId'   => $feedId,
                'feedName' => $feed->getFeedName(),
                ...$stats,
            ]);

            $this->markSuccess($feed, $result['etag']);

            return [
                ...$stats,
                'durationMs' => $this->elapsed($startTime),
                'skipped'    => false,
            ];

        } catch (FeedFetchException $e) {
            return $this->handleFetchError($feed, $e, $startTime);
        } catch (\Exception $e) {
            $this->logger->error('CalendarBridge: unexpected sync error', [
                'feedId' => $feedId,
                'error'  => $e->getMessage(),
            ]);
            return $this->handleFetchError($feed, $e, $startTime);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function markSuccess(Feed $feed, ?string $etag): void {
        $feed->setLastStatus(0);
        $feed->setLastError(null);
        $feed->setConsecutiveErrors(0);
        $feed->setLastEtag($etag);
        $feed->setLastSyncedAt(new \DateTime());
        $this->feedMapper->update($feed);

        // Dismiss any existing error notifications for this feed
        $this->notificationService->dismissFeedErrors($feed->getId());
    }

    private function handleFetchError(Feed $feed, \Exception $e, int $startTime): array {
        $errors = $feed->getConsecutiveErrors() + 1;
        $feed->setConsecutiveErrors($errors);
        $feed->setLastStatus(1);
        // Store only the technical error message — no event content
        $feed->setLastError(substr($e->getMessage(), 0, 512));
        $feed->setLastSyncedAt(new \DateTime());

        if ($errors >= self::PAUSE_AFTER_ERRORS) {
            $feed->setActive(false);
            $this->logger->warning('CalendarBridge: feed auto-paused after repeated errors', [
                'feedId' => $feed->getId(),
                'errors' => $errors,
            ]);
        }

        $this->feedMapper->update($feed);

        if ($errors === self::NOTIFY_AFTER_ERRORS || $errors === self::PAUSE_AFTER_ERRORS) {
            $this->notificationService->notifyFeedError($feed);
        }

        $this->logger->warning('CalendarBridge: feed sync failed', [
            'feedId'           => $feed->getId(),
            'consecutiveErrors' => $errors,
            // Log error type/code only — not the full stack trace in production
            'error'            => $e->getMessage(),
        ]);

        return ['added' => 0, 'updated' => 0, 'deleted' => 0, 'durationMs' => $this->elapsed($startTime), 'skipped' => false];
    }

    private function elapsed(int $startMs): int {
        return (int)(microtime(true) * 1000) - $startMs;
    }
}
