<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Controller;

use OCA\CalendarBridge\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoCSRFRequired]
    #[FrontpageRoute(verb: 'GET', url: '/')]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'calendarbridge-main');
        return new TemplateResponse(Application::APP_ID, 'index');
    }
}
