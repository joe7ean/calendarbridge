<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Command;

use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListFeeds extends Command {
    public function __construct(
        private readonly FeedMapper $feedMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('calendarbridge:list')
            ->setDescription('List configured feeds and their sync state')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $feeds = $this->feedMapper->findAll();

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode(array_map(static fn (Feed $f) => $f->toApiArray(), $feeds), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Service user', 'Groups', 'Interval', 'State', 'Last sync', 'Next attempt']);
        foreach ($feeds as $feed) {
            $table->addRow([
                $feed->getId(),
                $feed->getFeedName(),
                $feed->getServiceUser(),
                implode(', ', $feed->getTargetGroupsArray()),
                $feed->getSyncInterval() . ' s',
                $this->state($feed),
                $feed->getLastSyncedAt()?->format('Y-m-d H:i') ?? '-',
                $feed->getNextAttemptAt()?->format('Y-m-d H:i') ?? '-',
            ]);
        }
        $table->render();
        return self::SUCCESS;
    }

    private function state(Feed $feed): string {
        if (!$feed->isActive()) {
            return 'paused';
        }
        if ($feed->isInQuarantine()) {
            $rejected = $feed->getRejectedHash() === $feed->getQuarantineHash() ? ', rejected' : '';
            return sprintf('quarantine (%s, %d held%s)', $feed->getQuarantineReason(), $feed->getQuarantineHeld(), $rejected);
        }
        if ($feed->getLastStatus() === Feed::STATUS_ERROR) {
            return sprintf('error x%d', $feed->getConsecutiveErrors());
        }
        return $feed->getLastSyncedAt() === null ? 'new' : 'ok';
    }
}
