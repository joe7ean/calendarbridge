<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Activity;

use OCA\CalendarBridge\Db\FeedMapper;
use OCP\Activity\ActivitySettings;
use OCP\Activity\IEvent;
use OCP\Activity\IFilter;
use OCP\Activity\IManager;
use OCP\Activity\ISetting;

/**
 * Decorates the public activity manager, before storage, mail and push consumers.
 * Calendar events still reach every other DAV listener (reminders, webhooks, etc.).
 */
class PrivacyManager implements IManager {
    public function __construct(
        private readonly IManager $inner,
        private readonly FeedMapper $feeds,
        private readonly PrivacyScope $scope,
    ) {
    }

    private function suppress(IEvent $event): bool {
        if ($event->getApp() !== 'dav' || $event->getObjectType() !== 'calendar') {
            return false;
        }
        if ($this->scope->isActive()) {
            return true;
        }
        // No cache: a feed can acquire a new calendar ID during this request.
        foreach ($this->feeds->findAll() as $feed) {
            if ($feed->getCalendarId() === (int)$event->getObjectId()) {
                return true;
            }
        }
        return false;
    }

    public function publish(IEvent $event): void {
        if (!$this->suppress($event)) {
            $this->inner->publish($event);
        }
    }

    public function bulkPublish(IEvent $event, array $affectedUserIds, ISetting $setting): void {
        if (!$this->suppress($event)) {
            $this->inner->bulkPublish($event, $affectedUserIds, $setting);
        }
    }

    public function generateEvent(): IEvent { return $this->inner->generateEvent(); }
    public function registerConsumer(\Closure $callable): void { $this->inner->registerConsumer($callable); }
    public function registerFilter(string $filter): void { $this->inner->registerFilter($filter); }
    public function getFilters(): array { return $this->inner->getFilters(); }
    public function getFilterById(string $id): IFilter { return $this->inner->getFilterById($id); }
    public function registerSetting(string $setting): void { $this->inner->registerSetting($setting); }
    public function getSettings(): array { return $this->inner->getSettings(); }
    public function getSettingById(string $id): ActivitySettings { return $this->inner->getSettingById($id); }
    public function registerProvider(string $provider): void { $this->inner->registerProvider($provider); }
    public function getProviders(): array { return $this->inner->getProviders(); }
    public function setFormattingObject(string $type, int|string $id): void { $this->inner->setFormattingObject($type, $id); }
    public function isFormattingFilteredObject(): bool { return $this->inner->isFormattingFilteredObject(); }
    public function setRequirePNG(bool $status): void { $this->inner->setRequirePNG($status); }
    public function getRequirePNG(): bool { return $this->inner->getRequirePNG(); }
    public function setCurrentUserId(?string $currentUserId = null): void { $this->inner->setCurrentUserId($currentUserId); }
    public function getCurrentUserId(): string { return $this->inner->getCurrentUserId(); }
}
