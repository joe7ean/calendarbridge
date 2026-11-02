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
        switch ($notification->getSubject()) {
            case 'feed_error':
            case 'feed_quarantine':
            case 'feed_notice':
                // Ignore legacy parameters too: old stored notifications must
                // not render names or technical errors into forwarded mail.
                $subject = $l->t('Calendar Bridge: Important calendar status update');
                $message = $l->t('There is an important status update for a subscribed calendar. Sign in to Nextcloud to view the details.');
                break;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }

        $notification
            ->setParsedSubject($subject)
            ->setParsedMessage($message)
            ->setLink($notification->getSubject() === 'feed_notice'
                ? $this->urlGenerator->linkToRouteAbsolute('activity.Activities.showList')
                : $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]));

        return $notification;
    }
}
