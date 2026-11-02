<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Activity;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\FeedMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Provider implements IProvider {
    public function __construct(
        private readonly IFactory $l10n,
        private readonly IURLGenerator $urls,
        private readonly FeedMapper $feeds,
        private readonly IGroupManager $groups,
    ) {
    }

    public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        if ($event->getApp() !== Application::APP_ID) {
            throw new UnknownActivityException('Wrong app');
        }
        $l = $this->l10n->get(Application::APP_ID, $language);
        $p = $event->getSubjectParameters();
        $id = $l->t('feed #%s', [(int)$event->getObjectId()]);
        // Names are resolved for the in-Nextcloud stream only, never persisted
        // as event parameters or copied into a notification. Recheck access.
        try {
            $feed = $this->feeds->findById((int)$event->getObjectId());
            foreach ($feed->getTargetGroupsArray() as $group) {
                if ($this->groups->isInGroup($event->getAffectedUser(), $group)) {
                    $id = $feed->getFeedName();
                    break;
                }
            }
        } catch (DoesNotExistException) {
            // Removed feeds retain a useful, non-sensitive historical ID.
        }
        $text = match ($event->getSubject()) {
            'summary' => $l->t('Calendar Bridge %s: %s added, %s updated, %s removed. Removed events are no longer in the source; this does not necessarily mean cancellation.', [$id, (int)($p['added'] ?? 0), (int)($p['updated'] ?? 0), (int)($p['deleted'] ?? 0)]),
            'held' => $l->t('Calendar Bridge %s: suspicious removals are on hold. The affected events are kept.', [$id]),
            'released' => $l->t('Calendar Bridge %s: the source changed and quarantine has ended.', [$id]),
            'applied_admin' => $l->t('Calendar Bridge %s: %s held removals were approved by an administrator.', [$id, (int)($p['deleted'] ?? 0)]),
            'applied_auto' => $l->t('Calendar Bridge %s: %s held removals were applied after repeated source checks.', [$id, (int)($p['deleted'] ?? 0)]),
            'outage' => $l->t('Calendar Bridge %s: updates have failed for at least a day. The calendar may be out of date.', [$id]),
            'recovered' => $l->t('Calendar Bridge %s: updates are working again.', [$id]),
            'stopped' => $l->t('Calendar Bridge %s: updates have been stopped by an administrator.', [$id]),
            default => throw new UnknownActivityException('Unknown subject'),
        };
        return $event->setParsedSubject($text)->setIcon($this->urls->imagePath(Application::APP_ID, 'app.svg'));
    }
}
