<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Notification;

use OCA\CalendarBridge\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    public function __construct(
        private readonly IFactory $l10nFactory,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Calendar Bridge';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Wrong app');
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

        if ($notification->getSubject() === 'feed_error') {
            $params = $notification->getSubjectParameters();
            $feedName = $params['feedName'] ?? 'Unknown feed';
            $errors   = (int)($params['consecutiveErrors'] ?? 0);
            $error    = $params['error'] ?? '';

            $subject = $l->t('Calendar Bridge: Feed "%s" has errors', [$feedName]);
            $message = $errors >= 10
                ? $l->t('Feed "%s" was automatically paused after %d consecutive errors. Last error: %s', [$feedName, $errors, $error])
                : $l->t('Feed "%s" failed %d times in a row. Last error: %s', [$feedName, $errors, $error]);

            $notification
                ->setParsedSubject($subject)
                ->setParsedMessage($message)
                ->setLink(\OC::$server->getURLGenerator()->linkToRouteAbsolute(Application::APP_ID . '.page.index'));
        }

        return $notification;
    }
}
