<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Notification;

use OCA\CalendarBridge\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
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
            throw new UnknownNotificationException('Wrong app');
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();
        $feedName = (string)($params['feedName'] ?? 'Unknown feed');

        switch ($notification->getSubject()) {
            case 'feed_error':
                $errors = (int)($params['consecutiveErrors'] ?? 0);
                $error  = (string)($params['error'] ?? '');
                $subject = $l->t('Calendar Bridge: Feed "%s" has errors', [$feedName]);
                $message = $errors >= 10
                    ? $l->t('Feed "%s" was automatically paused after %d consecutive errors. Last error: %s', [$feedName, $errors, $error])
                    : $l->t('Feed "%s" failed %d times in a row. Last error: %s', [$feedName, $errors, $error]);
                break;

            case 'feed_quarantine':
                $held = (int)($params['held'] ?? 0);
                $subject = $l->t('Calendar Bridge: Changes to "%s" are on hold', [$feedName]);
                $message = ($params['reason'] ?? '') === 'empty'
                    ? $l->t('The feed "%s" is suddenly empty. Its %d events are kept until the feed stays empty for a day or you apply the change.', [$feedName, $held])
                    : $l->t('The feed "%s" would remove %d events at once. They are kept until the result is confirmed for a day or you apply the change.', [$feedName, $held]);
                break;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }

        $notification
            ->setParsedSubject($subject)
            ->setParsedMessage($message)
            ->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]));

        return $notification;
    }
}
