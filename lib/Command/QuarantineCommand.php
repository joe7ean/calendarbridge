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
 * Apply or reject the result a feed holds in quarantine (ADR 0012).
 */
class QuarantineCommand extends Command {
    public function __construct(
        private readonly FeedMapper $feedMapper,
        private readonly SyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('calendarbridge:quarantine')
            ->setDescription('Apply or reject the deletions a feed holds in quarantine')
            ->addArgument('feed-id', InputArgument::REQUIRED, 'ID of the feed')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Fetch again and apply the deletions if the result is unchanged')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Keep the events; never apply this result automatically');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $id = (int)$input->getArgument('feed-id');
        $apply = (bool)$input->getOption('apply');
        $reject = (bool)$input->getOption('reject');
        if ($apply === $reject) {
            $output->writeln('<error>Use exactly one of --apply or --reject.</error>');
            return self::INVALID;
        }

        try {
            $feed = $this->feedMapper->findById($id);
        } catch (DoesNotExistException) {
            $output->writeln("<error>Feed {$id} not found.</error>");
            return self::FAILURE;
        }
        if (!$feed->isInQuarantine()) {
            $output->writeln("Feed {$id} is not in quarantine.");
            return self::SUCCESS;
        }

        if ($reject) {
            $this->syncService->rejectQuarantine($id);
            $output->writeln("Rejected. Feed {$id} keeps its events until the source changes.");
            return self::SUCCESS;
        }

        $r = $this->syncService->syncFeed($id, true);
        $feed = $this->feedMapper->findById($id);
        if ($r['locked'] ?? false) {
            $output->writeln('<error>A sync is already running, try again shortly.</error>');
            return self::FAILURE;
        }
        if ($feed->isInQuarantine()) {
            $output->writeln(sprintf(
                '<comment>The source changed since it was quarantined; the new result is held again (%d deletions).</comment>',
                $feed->getQuarantineHeld(),
            ));
            return self::FAILURE;
        }
        $output->writeln(sprintf('Applied: %d deleted.', $r['deleted']));
        return self::SUCCESS;
    }
}
