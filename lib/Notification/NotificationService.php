<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Notification;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\Feed;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

class NotificationService {
    public function __construct(
        private readonly IManager $notificationManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send a notification to all admin users when a feed has persistent errors.
     */
    public function notifyFeedError(Feed $feed): void {
        $this->notifyAdmins($feed, 'feed_error', [
            'feedName'          => $feed->getFeedName(),
            'consecutiveErrors' => $feed->getConsecutiveErrors(),
            // Only technical error info — no event content
            'error'             => $feed->getLastError() ?? 'Unknown error',
        ]);
    }

    /**
     * Tell admins that deletions of a feed are held in quarantine (ADR 0012).
     */
    public function notifyQuarantine(Feed $feed): void {
        $this->notifyAdmins($feed, 'feed_quarantine', [
            'feedName' => $feed->getFeedName(),
            'reason'   => $feed->getQuarantineReason(),
            'held'     => $feed->getQuarantineHeld(),
        ]);
    }

    public function dismissQuarantine(int $feedId): void {
        $this->dismiss($feedId, 'feed_quarantine');
    }

    private function notifyAdmins(Feed $feed, string $subject, array $parameters): void {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return;
        }

        foreach ($adminGroup->getUsers() as $admin) {
            try {
                $notification = $this->notificationManager->createNotification();
                $notification
                    ->setApp(Application::APP_ID)
                    ->setUser($admin->getUID())
                    ->setDateTime(new \DateTime())
                    ->setObject('feed', (string)$feed->getId())
                    ->setSubject($subject, $parameters);

                $this->notificationManager->notify($notification);
            } catch (\Exception $e) {
                $this->logger->warning('CalendarBridge: failed to send notification', [
                    'admin'   => $admin->getUID(),
                    'subject' => $subject,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dismiss all error notifications for a feed (called after successful sync).
     */
    public function dismissFeedErrors(int $feedId): void {
        $this->dismiss($feedId, 'feed_error');
    }

    private function dismiss(int $feedId, string $subject): void {
        $notification = $this->notificationManager->createNotification();
        $notification
            ->setApp(Application::APP_ID)
            ->setObject('feed', (string)$feedId)
            ->setSubject($subject);

        $this->notificationManager->markProcessed($notification);
    }
}
