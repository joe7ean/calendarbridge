<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Command;

use OCA\CalendarBridge\Service\HealthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Health check for humans and monitoring.
 * Exit code: 0 = healthy, 1 = warnings, 2 = errors.
 */
class Check extends Command {
    public function __construct(
        private readonly HealthService $healthService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('calendarbridge:check')
            ->setDescription('Check feeds, background jobs and service users (exit 0 ok, 1 warnings, 2 errors)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $findings = $this->healthService->check();

        $levels = array_column($findings, 'level');
        $exit = in_array(HealthService::ERROR, $levels, true) ? 2
            : (in_array(HealthService::WARNING, $levels, true) ? 1 : 0);

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode(['status' => $exit, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $exit;
        }

        foreach ($findings as $f) {
            $prefix = match ($f['level']) {
                HealthService::ERROR   => '<error>ERROR</error>  ',
                HealthService::WARNING => '<comment>WARN</comment>   ',
                default                => 'INFO   ',
            };
            $feed = $f['feedId'] !== null ? sprintf('[%d %s] ', $f['feedId'], $f['feedName']) : '';
            $output->writeln($prefix . $feed . $f['message']);
        }
        if ($exit === 0) {
            $output->writeln('<info>All feeds are healthy.</info>');
        }
        return $exit;
    }
}
