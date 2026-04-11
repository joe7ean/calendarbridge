<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Settings;

use OCA\CalendarBridge\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('Calendar Bridge');
    }

    public function getPriority(): int {
        return 55;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
    }
}
