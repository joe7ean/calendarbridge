<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Activity;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\FeedMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IL10N;
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
            'summary' => $this->summary($l, $id, $p),
            'held' => $l->t('Calendar Bridge %s: suspicious removals are on hold. The affected events are kept.', [$id]),
            'released' => $l->t('Calendar Bridge %s: the source changed and quarantine has ended.', [$id]),
            'applied_admin' => $l->t('Calendar Bridge %s: %s held removals were approved by an administrator.', [$id, (int)($p['deleted'] ?? 0)]),
            'applied_auto' => $l->t('Calendar Bridge %s: %s held removals were applied after repeated source checks.', [$id, (int)($p['deleted'] ?? 0)]),
            'outage' => $l->t('Calendar Bridge %s: updates have failed for at least a day. The calendar may be out of date.', [$id]),
            'recovered' => $l->t('Calendar Bridge %s: updates are working again.', [$id]),
            'stopped' => $l->t('Calendar Bridge %s: updates have been stopped by an administrator.', [$id]),
            default => throw new UnknownActivityException('Unknown subject'),
        };
        return $event->setParsedSubject($text)->setIcon($this->icon());
    }

    /**
     * Only non-zero counts, and "no longer in the source" instead of "removed":
     * absence from the feed is the only known fact, it does not mean cancellation
     * (ADR 0016). The wording explains itself, so no extra sentence per entry.
     */
    private function summary(IL10N $l, string $id, array $p): string {
        $parts = [];
        if (($added = (int)($p['added'] ?? 0)) > 0) {
            $parts[] = $l->n('%n new event', '%n new events', $added);
        }
        if (($updated = (int)($p['updated'] ?? 0)) > 0) {
            $parts[] = $l->n('%n changed event', '%n changed events', $updated);
        }
        if (($deleted = (int)($p['deleted'] ?? 0)) > 0) {
            $parts[] = $l->n('%n event no longer in the source', '%n events no longer in the source', $deleted);
        }
        if ($parts === []) {
            return $l->t('Calendar Bridge %s: no changes.', [$id]);
        }
        return $l->t('Calendar Bridge %1$s: %2$s.', [$id, implode(', ', $parts)]);
    }

    /**
     * imagePath() throws for a missing file, and Nextcloud drops an event whose
     * parse() throws. A broken icon must not hide the activity text (#2).
     */
    private function icon(): string {
        try {
            return $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
        } catch (\RuntimeException) {
            return '';
        }
    }
}
