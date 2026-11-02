<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class Setting extends ActivitySettings {
    public function __construct(private readonly IL10N $l) {
    }

    public function getIdentifier(): string { return 'calendarbridge_summary'; }
    public function getName(): string { return $this->l->t('Calendar Bridge summaries and status changes'); }
    public function getGroupIdentifier(): string { return 'calendarbridge'; }
    public function getGroupName(): string { return 'Calendar Bridge'; }
    public function getPriority(): int { return 70; }
    public function canChangeMail(): bool { return false; }
    public function isDefaultEnabledMail(): bool { return false; }
    public function canChangeNotification(): bool { return false; }
    public function isDefaultEnabledNotification(): bool { return false; }
}
