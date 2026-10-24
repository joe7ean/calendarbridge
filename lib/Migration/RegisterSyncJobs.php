<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Migration;

use OCA\CalendarBridge\Service\JobRegistrar;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Re-register background jobs lost while the app was disabled (ADR 0014).
 * Runs on install and after every app upgrade.
 */
class RegisterSyncJobs implements IRepairStep {
    public function __construct(
        private readonly JobRegistrar $jobRegistrar,
    ) {
    }

    public function getName(): string {
        return 'Register missing Calendar Bridge sync jobs';
    }

    public function run(IOutput $output): void {
        $added = $this->jobRegistrar->ensureJobs();
        if ($added > 0) {
            $output->info("Re-registered {$added} missing sync job(s).");
        }
    }
}
