<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\SetupCheck;

use OCA\CalendarBridge\Service\HealthService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Surfaces Calendar Bridge problems in Administration → Overview.
 */
class FeedHealthCheck implements ISetupCheck {
    public function __construct(
        private readonly HealthService $healthService,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        return $this->l->t('Calendar Bridge');
    }

    public function run(): SetupResult {
        $errors = [];
        $warnings = [];
        foreach ($this->healthService->check() as $finding) {
            $line = ($finding['feedName'] !== null ? '"' . $finding['feedName'] . '": ' : '') . $finding['message'];
            if ($finding['level'] === HealthService::ERROR) {
                $errors[] = $line;
            } elseif ($finding['level'] === HealthService::WARNING) {
                $warnings[] = $line;
            }
        }

        if ($errors !== []) {
            return SetupResult::error(implode("\n", [...$errors, ...$warnings]));
        }
        if ($warnings !== []) {
            return SetupResult::warning(implode("\n", $warnings));
        }
        return SetupResult::success($this->l->t('All feeds are healthy.'));
    }
}
