<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Command;

use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Service\SyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sync now, ignoring the interval and the error backoff. Quarantine still applies.
 */
class Sync extends Command {
    public function __construct(
        private readonly FeedMapper $feedMapper,
        private readonly SyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('calendarbridge:sync')
            ->setDescription('Sync one feed or all active feeds now (quarantine rules still apply)')
            ->addArgument('feed-id', InputArgument::OPTIONAL, 'ID of the feed (see calendarbridge:list)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Sync all active feeds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('all')) {
            $ids = array_map(static fn ($f) => $f->getId(), $this->feedMapper->findAllActive());
        } elseif ($input->getArgument('feed-id') !== null) {
            $ids = [(int)$input->getArgument('feed-id')];
        } else {
            $output->writeln('<error>Give a feed ID or --all.</error>');
            return self::INVALID;
        }

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $feed = $this->feedMapper->findById($id);
            } catch (DoesNotExistException) {
                $output->writeln("<error>Feed {$id} not found.</error>");
                $failed++;
                continue;
            }
            $r = $this->syncService->syncFeed($id);
            $feed = $this->feedMapper->findById($id);
            if ($r['locked'] ?? false) {
                $output->writeln(sprintf('[%d %s] skipped: a sync is already running', $id, $feed->getFeedName()));
                continue;
            }
            if ($r['skipped']) {
                $output->writeln(sprintf('[%d %s] skipped: feed is paused', $id, $feed->getFeedName()));
                continue;
            }
            if ($feed->getLastError() !== null) {
                $output->writeln(sprintf('<error>[%d %s] failed: %s</error>', $id, $feed->getFeedName(), $feed->getLastError()));
                $failed++;
                continue;
            }
            $output->writeln(sprintf(
                '[%d %s] +%d ~%d -%d%s (%d ms)',
                $id,
                $feed->getFeedName(),
                $r['added'],
                $r['updated'],
                $r['deleted'],
                $r['held'] > 0 ? sprintf(', %d deletions held in quarantine', $r['held']) : '',
                $r['durationMs'],
            ));
        }
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
