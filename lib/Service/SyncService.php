<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Notification\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class SyncService {
    // After this many consecutive failures: send admin notification
    private const NOTIFY_AFTER_ERRORS = 3;
    // After this many consecutive failures: auto-pause the feed
    private const PAUSE_AFTER_ERRORS = 10;
    // Upper bound for the error backoff
    private const MAX_BACKOFF = 86400;

    public function __construct(
        private readonly FeedMapper $feedMapper,
        private readonly FeedService $feedService,
        private readonly CalendarService $calendarService,
        private readonly NotificationService $notificationService,
        private readonly Quarantine $quarantine,
        private readonly ICrypto $crypto,
        private readonly ILockingProvider $lockingProvider,
        private readonly ITimeFactory $time,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sync a single feed by ID. Called by SyncJob, the manual-sync endpoint and occ.
     * Runs at most once per feed at a time; a concurrent call returns
     * immediately with skipped = locked = true.
     *
     * @param bool $applyQuarantine the admin applies the currently quarantined result
     * @return array{added: int, updated: int, deleted: int, held: int, durationMs: int, skipped: bool, locked?: bool}
     */
    public function syncFeed(int $feedId, bool $applyQuarantine = false): array {
        $lockKey = 'calendarbridge/feed/' . $feedId;
        try {
            $this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            $this->logger->info('CalendarBridge: feed is already being synced', ['feedId' => $feedId]);
            return [...$this->emptyResult(0), 'skipped' => true, 'locked' => true];
        }

        try {
            return $this->syncFeedUnlocked($feedId, $applyQuarantine);
        } finally {
            $this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * Keep the relay calendar as it is although the quarantined result persists.
     * The rejected result is never applied automatically (ADR 0012).
     */
    public function rejectQuarantine(int $feedId): Feed {
        $feed = $this->feedMapper->findById($feedId);
        if ($feed->isInQuarantine()) {
            $feed->setRejectedHash($feed->getQuarantineHash());
            $this->feedMapper->update($feed);
            $this->logger->info('CalendarBridge: quarantined result rejected by admin', ['feedId' => $feedId]);
        }
        return $feed;
    }

    /**
     * @return array{added: int, updated: int, deleted: int, held: int, durationMs: int, skipped: bool}
     */
    private function syncFeedUnlocked(int $feedId, bool $applyQuarantine): array {
        $startTime = (int)(microtime(true) * 1000);

        try {
            $feed = $this->feedMapper->findById($feedId);
        } catch (DoesNotExistException) {
            $this->logger->warning('CalendarBridge: feed not found for sync', ['feedId' => $feedId]);
            return [...$this->emptyResult(0), 'skipped' => true];
        }

        if (!$feed->isActive()) {
            return [...$this->emptyResult(0), 'skipped' => true];
        }

        try {
            if (!$this->userManager->userExists($feed->getServiceUser())) {
                throw new FeedFetchException(sprintf(
                    'Service user "%s" does not exist; choose another one in the feed settings.',
                    $feed->getServiceUser(),
                ));
            }

            $authPass = null;
            if ($feed->getHttpAuthPass() !== null) {
                try {
                    $authPass = $this->crypto->decrypt($feed->getHttpAuthPass());
                } catch (\Exception $e) {
                    // Fetching without the password would only produce a misleading 401.
                    throw new FeedFetchException('Stored HTTP password could not be decrypted; please enter it again.', 0, $e);
                }
            }

            // In quarantine every sync must see the full result to confirm it, so
            // conditional requests (304) are not used.
            $conditional = !$feed->isInQuarantine();
            $result = $this->feedService->fetch(
                $feed->getFeedUrl(),
                $conditional ? $feed->getLastEtag() : null,
                $feed->getHttpAuthUser(),
                $authPass,
                $feed->isVerifySsl(),
                $conditional ? $feed->getLastModified() : null,
            );

            $calendarId = $this->calendarService->ensureCalendar(
                $feed->getCalendarId(),
                $feed->getServiceUser(),
                $feed->getFeedName(),
                $feed->getCalendarColor() ?? '#0082c9',
            );
            if ($calendarId !== $feed->getCalendarId()) {
                $feed->setCalendarId($calendarId);
                $this->feedMapper->update($feed);
            }

            // Always re-apply group sharing (idempotent — safe to call on every sync)
            $this->calendarService->shareWithGroups($calendarId, $feed->getTargetGroupsArray());

            if ($result['notModified']) {
                $this->logger->debug('CalendarBridge: feed not modified (304)', ['feedId' => $feedId]);
                $this->markSuccess($feed, $result['etag'], $result['lastModified']);
                return [...$this->emptyResult($startTime), 'skipped' => false];
            }

            $calendar = $this->feedService->sanitize($result['calendar'], $feed->isStripAttendees());

            $uids = [];
            $eventCount = 0;
            foreach ($calendar->VEVENT ?? [] as $event) {
                $eventCount++;
                $uid = trim((string)($event->UID ?? ''));
                if ($uid !== '') {
                    $uids[] = $uid;
                }
            }
            if ($eventCount > 0 && $uids === []) {
                throw new FeedFetchException('Feed contains events but none with a UID; nothing can be synced.');
            }
            $hash = Quarantine::hashUids($uids);
            $incoming = count(array_unique($uids));

            $decision = null;
            $stats = $this->calendarService->syncEvents(
                $calendarId,
                $calendar,
                function (int $toDelete, int $existing) use ($feed, $incoming, $hash, $applyQuarantine, &$decision): bool {
                    $decision = $this->quarantine->decide($feed, $existing, $toDelete, $incoming, $hash, $applyQuarantine);
                    return $decision === Quarantine::APPLY;
                },
            );
            if ($decision === null && $feed->isInQuarantine()) {
                // Nothing to delete any more: the suspicious result is gone.
                $feed->clearQuarantine();
                $decision = Quarantine::APPLY;
            }

            $this->logger->info('CalendarBridge: sync completed', [
                'feedId'   => $feedId,
                'feedName' => $feed->getFeedName(),
                ...$stats,
            ]);

            $this->markSuccess($feed, $result['etag'], $result['lastModified']);

            if ($decision === Quarantine::HOLD_NEW) {
                $this->logger->warning('CalendarBridge: suspicious feed result quarantined', [
                    'feedId' => $feedId,
                    'reason' => $feed->getQuarantineReason(),
                    'held'   => $feed->getQuarantineHeld(),
                ]);
                $this->notificationService->notifyQuarantine($feed);
            }

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

    private function markSuccess(Feed $feed, ?string $etag, ?string $lastModified): void {
        $feed->setLastStatus($feed->isInQuarantine() ? Feed::STATUS_QUARANTINE : Feed::STATUS_OK);
        $feed->setLastError(null);
        $feed->setConsecutiveErrors(0);
        $feed->setLastEtag($etag);
        $feed->setLastModified($lastModified);
        $feed->setNextAttemptAt(null);
        $feed->setLastSyncedAt($this->now());
        $this->feedMapper->update($feed);

        // Dismiss any existing error notifications for this feed
        $this->notificationService->dismissFeedErrors($feed->getId());
        if (!$feed->isInQuarantine()) {
            $this->notificationService->dismissQuarantine($feed->getId());
        }
    }

    private function handleFetchError(Feed $feed, \Exception $e, int $startTime): array {
        $errors = $feed->getConsecutiveErrors() + 1;
        $feed->setConsecutiveErrors($errors);
        $feed->setLastStatus(Feed::STATUS_ERROR);
        // Store only the technical error message — no event content
        $feed->setLastError(substr($e->getMessage(), 0, 512));
        $feed->setLastSyncedAt($this->now());

        // Exponential backoff: the regular interval after the first error, doubling
        // with every further one, capped at a day — or longer if the source asks.
        $backoff = min(max(900, $feed->getSyncInterval()) * (2 ** min($errors - 1, 10)), self::MAX_BACKOFF);
        if ($e instanceof FeedFetchException && $e->getRetryAfter() !== null) {
            $backoff = max($backoff, $e->getRetryAfter());
        }
        $feed->setNextAttemptAt(new \DateTime('@' . ($this->time->getTime() + $backoff)));

        if ($errors >= self::PAUSE_AFTER_ERRORS) {
            $feed->setActive(0);
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
            'feedId'            => $feed->getId(),
            'consecutiveErrors' => $errors,
            'nextAttemptIn'     => $backoff,
            // Log error type/code only — not the full stack trace in production
            'error'             => $e->getMessage(),
        ]);

        return [...$this->emptyResult($startTime), 'skipped' => false];
    }

    /** @return array{added: int, updated: int, deleted: int, held: int, durationMs: int} */
    private function emptyResult(int $startTime): array {
        return [
            'added'      => 0,
            'updated'    => 0,
            'deleted'    => 0,
            'held'       => 0,
            'durationMs' => $startTime === 0 ? 0 : $this->elapsed($startTime),
        ];
    }

    private function now(): \DateTime {
        return new \DateTime('@' . $this->time->getTime());
    }

    private function elapsed(int $startMs): int {
        return (int)(microtime(true) * 1000) - $startMs;
    }
}
