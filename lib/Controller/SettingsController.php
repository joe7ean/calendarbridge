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
    ): DataResponse {
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
