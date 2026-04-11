<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Settings;

use OCA\CalendarBridge\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
    public function __construct(
        private readonly IL10N $l,
    ) {
    }

    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'calendarbridge-main');
        return new TemplateResponse(Application::APP_ID, 'index', [], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
