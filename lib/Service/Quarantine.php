<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\Feed;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Decides whether the deletions of a sync may be applied (ADR 0012).
 *
 * A result is suspicious when the feed is empty while the relay calendar is not,
 * or when it would delete at least `quarantine_ratio_percent` of the relay objects
 * and at least `quarantine_min_deletions` of them. Suspicious deletions are held
 * until the same result (same set of UIDs) has been seen in
 * `quarantine_confirm_syncs` syncs spanning `quarantine_confirm_hours`, or an
 * admin applies it. A rejected result is never applied automatically.
 *
 * decide() mutates the quarantine fields of the feed; the caller persists it.
 */
class Quarantine {
    public const REASON_EMPTY = 'empty';
    public const REASON_MASS_DELETE = 'mass_delete';

    public const APPLY = 'apply';
    public const HOLD = 'hold';
    public const HOLD_NEW = 'hold_new';

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ITimeFactory $time,
    ) {
    }

    /**
     * Hash identifying a feed result by its set of UIDs.
     *
     * @param string[] $uids
     */
    public static function hashUids(array $uids): string {
        $uids = array_values(array_unique($uids));
        sort($uids, SORT_STRING);
        return hash('sha256', implode("\n", $uids));
    }

    public function reason(int $existing, int $toDelete, int $incoming): ?string {
        if ($toDelete === 0) {
            return null;
        }
        if ($incoming === 0) {
            return self::REASON_EMPTY;
        }
        $minDeletions = $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_min_deletions', 10);
        $ratio = $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_ratio_percent', 50);
        if ($toDelete >= $minDeletions && $toDelete * 100 >= $ratio * $existing) {
            return self::REASON_MASS_DELETE;
        }
        return null;
    }

    /**
     * @param bool $adminApproved the admin applied the quarantined result
     * @return self::APPLY|self::HOLD|self::HOLD_NEW
     */
    public function decide(Feed $feed, int $existing, int $toDelete, int $incoming, string $hash, bool $adminApproved = false): string {
        $reason = $this->reason($existing, $toDelete, $incoming);

        if ($reason === null) {
            $feed->clearQuarantine();
            return self::APPLY;
        }

        if ($feed->getQuarantineHash() === $hash) {
            if ($adminApproved) {
                $feed->clearQuarantine();
                return self::APPLY;
            }
            if ($feed->getRejectedHash() === $hash) {
                return self::HOLD;
            }

            $feed->setQuarantineCount($feed->getQuarantineCount() + 1);
            $feed->setQuarantineHeld($toDelete);

            $minSyncs = $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_confirm_syncs', 2);
            $minAge = $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_confirm_hours', 24) * 3600;
            $since = $feed->getQuarantineSince()?->getTimestamp() ?? $this->time->getTime();
            if ($feed->getQuarantineCount() >= $minSyncs && $this->time->getTime() - $since >= $minAge) {
                $feed->clearQuarantine();
                return self::APPLY;
            }
            return self::HOLD;
        }

        // A new suspicious result (also when the source changed during a quarantine).
        $feed->setQuarantineSince(new \DateTime('@' . $this->time->getTime()));
        $feed->setQuarantineCount(1);
        $feed->setQuarantineHash($hash);
        $feed->setQuarantineReason($reason);
        $feed->setQuarantineHeld($toDelete);
        $feed->setRejectedHash(null);
        return self::HOLD_NEW;
    }
}
