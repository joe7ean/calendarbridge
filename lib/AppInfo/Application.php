<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\AppInfo;

use OCA\CalendarBridge\Activity\PrivacyManager;
use OCA\CalendarBridge\Activity\PrivacyScope;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Notification\Notifier;
use OCA\CalendarBridge\Service\JobRegistrar;
use OCA\CalendarBridge\SetupCheck\FeedHealthCheck;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Activity\IManager as ActivityManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Server;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'calendarbridge';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register the notifier for admin error notifications
        $context->registerNotifierService(Notifier::class);

        // Feed health in Administration → Overview
        $context->registerSetupCheck(FeedHealthCheck::class);

        // CalDavBackend is an internal NC class — wire it explicitly
        // so it's available for injection in our services. Resolved from the
        // server container, not the app container, which would recurse here.
        $context->registerService(CalDavBackend::class, function () {
            return Server::get(CalDavBackend::class);
        });
    }

    public function boot(IBootContext $context): void {
        // Install before DAV's lazy activity listener is resolved. Only public
        // container/activity APIs are used; the original manager owns consumers.
        $server = $context->getServerContainer();
        $inner = $server->get(ActivityManager::class);
        if (!$inner instanceof PrivacyManager) {
            $privacy = new PrivacyManager(
                $inner,
                $context->getAppContainer()->get(FeedMapper::class),
                $context->getAppContainer()->get(PrivacyScope::class),
            );
            $server->registerService(ActivityManager::class, static fn () => $privacy);
        }
        // Self-healing: restore sync jobs that cron removed while the app was
        // disabled. Throttled to once per hour; must never break a request.
        try {
            $context->injectFn(static function (JobRegistrar $registrar): void {
                $registrar->ensureJobsThrottled();
            });
        } catch (\Throwable $e) {
            Server::get(LoggerInterface::class)->warning('CalendarBridge: job self-check failed', [
                'exception' => $e,
            ]);
        }
    }
}
