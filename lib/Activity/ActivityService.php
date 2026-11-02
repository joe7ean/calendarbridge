<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Activity;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Notification\NotificationService;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/** The caller holds the feed lock and persists the feed after each call. */
class ActivityService {
    public function __construct(
        private readonly IManager $activities,
        private readonly IGroupManager $groups,
        private readonly NotificationService $notifications,
        private readonly ITimeFactory $time,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function success(Feed $feed, array $stats, bool $wasQuarantined, bool $adminApproved, bool $heldResultApplied = false): void {
        $this->change($feed, function (array &$state) use ($feed, $stats, $wasQuarantined, $adminApproved, $heldResultApplied): void {
            if ($state['outage'] ?? false) {
                $this->status($feed, 'recovered');
            }
            unset($state['errorSince'], $state['outage']);
            foreach (['added', 'updated', 'deleted'] as $key) {
                $state[$key] = ($state[$key] ?? 0) + max(0, (int)($stats[$key] ?? 0));
            }
            if (array_sum(array_intersect_key($state, array_flip(['added', 'updated', 'deleted']))) > 0) {
                $state['since'] ??= $this->time->getTime();
            }
            if ($feed->isInQuarantine() && !$wasQuarantined) {
                $this->status($feed, 'held');
            } elseif ($wasQuarantined && !$feed->isInQuarantine()) {
                if ($heldResultApplied && ($stats['deleted'] ?? 0) > 0) {
                    $this->status($feed, $adminApproved ? 'applied_admin' : 'applied_auto', ['deleted' => $stats['deleted']]);
                } else {
                    $this->status($feed, 'released');
                }
            }
        });
    }

    public function failure(Feed $feed): void {
        $this->change($feed, function (array &$state): void {
            $state['errorSince'] ??= $this->time->getTime();
        });
    }

    public function flush(Feed $feed): void {
        $this->change($feed, function (array &$state) use ($feed): void {
            $now = $this->time->getTime();
            if (isset($state['errorSince']) && $now - $state['errorSince'] >= 86400
                && $feed->getConsecutiveErrors() >= 3 && !($state['outage'] ?? false)) {
                $this->status($feed, 'outage');
                $state['outage'] = true;
            }
            if (isset($state['since']) && $now - $state['since'] >= 86400) {
                $this->publish($feed, 'summary', [
                    'added' => (int)($state['added'] ?? 0),
                    'updated' => (int)($state['updated'] ?? 0),
                    'deleted' => (int)($state['deleted'] ?? 0),
                ]);
                unset($state['added'], $state['updated'], $state['deleted'], $state['since']);
            }
        });
    }

    public function stopped(Feed $feed): void {
        $this->change($feed, function (array &$state) use ($feed): void {
            $this->status($feed, 'stopped');
            unset($state['errorSince'], $state['outage']);
        });
    }

    private function change(Feed $feed, callable $operation): void {
        try {
            $state = json_decode($feed->getActivityState() ?? '{}', true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new \UnexpectedValueException('Invalid activity state');
            }
            $operation($state);
            $feed->setActivityState(json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            // Reporting must never turn a completed sync into a fetch error.
            $this->logger->error('CalendarBridge: activity reporting failed', ['feedId' => $feed->getId(), 'errorType' => $e::class]);
        }
    }

    private function recipients(Feed $feed): array {
        $users = [];
        foreach ($feed->getTargetGroupsArray() as $groupId) {
            foreach ($this->groups->get($groupId)?->getUsers() ?? [] as $user) {
                $users[$user->getUID()] = true;
            }
        }
        return array_keys($users);
    }

    private function status(Feed $feed, string $subject, array $parameters = []): void {
        try {
            $this->publish($feed, $subject, $parameters);
            $this->notifications->notifyStatus($feed, $this->recipients($feed));
        } catch (\Throwable $e) {
            // Preserve accumulated counts even when a transition cannot be sent.
            $this->logger->error('CalendarBridge: status reporting failed', ['feedId' => $feed->getId(), 'errorType' => $e::class]);
        }
    }

    private function publish(Feed $feed, string $subject, array $parameters): void {
        foreach ($this->recipients($feed) as $userId) {
            $event = $this->activities->generateEvent();
            $event->setApp(Application::APP_ID)
                ->setType('calendarbridge_summary')
                ->setAuthor($feed->getServiceUser())
                ->setAffectedUser($userId)
                ->setTimestamp($this->time->getTime())
                ->setObject('calendarbridge_feed', $feed->getId())
                ->setSubject($subject, $parameters);
            $this->activities->publish($event);
        }
    }
}
