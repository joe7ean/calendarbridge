<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Controller;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

class SettingsController extends Controller {
    private const SETTINGS_KEYS = [
        'default_service_user',
        'default_sync_interval',
        'min_sync_interval',
        'max_feed_size_mb',
        'allow_http',
        'strip_attendees_default',
        'http_timeout',
    ];

    public function __construct(
        IRequest $request,
        private readonly IAppConfig $appConfig,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getSettings(): DataResponse {
        return new DataResponse([
            'default_service_user'    => $this->appConfig->getValueString(Application::APP_ID, 'default_service_user', ''),
            'default_sync_interval'   => $this->appConfig->getValueInt(Application::APP_ID, 'default_sync_interval', 3600),
            'min_sync_interval'       => $this->appConfig->getValueInt(Application::APP_ID, 'min_sync_interval', 900),
            'max_feed_size_mb'        => $this->appConfig->getValueInt(Application::APP_ID, 'max_feed_size_mb', 10),
            'allow_http'              => $this->appConfig->getValueBool(Application::APP_ID, 'allow_http', false),
            'strip_attendees_default' => $this->appConfig->getValueBool(Application::APP_ID, 'strip_attendees_default', true),
            'http_timeout'            => $this->appConfig->getValueInt(Application::APP_ID, 'http_timeout', 30),
            'max_events'              => $this->appConfig->getValueInt(Application::APP_ID, 'max_events', 20000),
            'quarantine_min_deletions' => $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_min_deletions', 10),
            'quarantine_ratio_percent' => $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_ratio_percent', 50),
            'quarantine_confirm_hours' => $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_confirm_hours', 24),
            'quarantine_confirm_syncs' => $this->appConfig->getValueInt(Application::APP_ID, 'quarantine_confirm_syncs', 2),
        ]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveSettings(
        ?string $default_service_user = null,
        ?int $default_sync_interval = null,
        ?int $min_sync_interval = null,
        ?int $max_feed_size_mb = null,
        ?bool $allow_http = null,
        ?bool $strip_attendees_default = null,
        ?int $http_timeout = null,
        ?int $max_events = null,
        ?int $quarantine_min_deletions = null,
        ?int $quarantine_ratio_percent = null,
        ?int $quarantine_confirm_hours = null,
        ?int $quarantine_confirm_syncs = null,
    ): DataResponse {
        // Bounded so that a typo cannot switch a safety net off entirely.
        $bounded = [
            'max_events'               => [$max_events, 100, 200000],
            'quarantine_min_deletions' => [$quarantine_min_deletions, 1, 10000],
            'quarantine_ratio_percent' => [$quarantine_ratio_percent, 10, 100],
            'quarantine_confirm_hours' => [$quarantine_confirm_hours, 1, 168],
            'quarantine_confirm_syncs' => [$quarantine_confirm_syncs, 2, 20],
        ];
        foreach ($bounded as $key => [$value, $min, $max]) {
            if ($value !== null) {
                $this->appConfig->setValueInt(Application::APP_ID, $key, max($min, min($max, $value)));
            }
        }

        if ($default_service_user !== null) {
            $this->appConfig->setValueString(Application::APP_ID, 'default_service_user', $default_service_user);
        }
        if ($default_sync_interval !== null) {
            $this->appConfig->setValueInt(Application::APP_ID, 'default_sync_interval', max(900, $default_sync_interval));
        }
        if ($min_sync_interval !== null) {
            $this->appConfig->setValueInt(Application::APP_ID, 'min_sync_interval', max(900, $min_sync_interval));
        }
        if ($max_feed_size_mb !== null) {
            $this->appConfig->setValueInt(Application::APP_ID, 'max_feed_size_mb', max(1, min(100, $max_feed_size_mb)));
        }
        if ($allow_http !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, 'allow_http', $allow_http);
        }
        if ($strip_attendees_default !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, 'strip_attendees_default', $strip_attendees_default);
        }
        if ($http_timeout !== null) {
            $this->appConfig->setValueInt(Application::APP_ID, 'http_timeout', max(5, min(120, $http_timeout)));
        }

        return new DataResponse(['status' => 'saved']);
    }

    /**
     * Return all Nextcloud groups for the multi-select in the feed form.
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getGroups(?string $search = null): DataResponse {
        $groups = $this->groupManager->search($search ?? '');
        $data   = [];
        foreach ($groups as $group) {
            $data[] = [
                'id'          => $group->getGID(),
                'displayName' => $group->getDisplayName(),
            ];
        }
        return new DataResponse($data);
    }

    /**
     * Return all Nextcloud users for the service-user selector.
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getServiceUsers(?string $search = null): DataResponse {
        $users = $this->userManager->search($search ?? '');
        $data  = [];
        foreach ($users as $user) {
            $data[] = [
                'uid'         => $user->getUID(),
                'displayName' => $user->getDisplayName(),
            ];
        }
        return new DataResponse($data);
    }
}
