<?php

namespace App\Command;

use App\Repository\WatcherLogRepository;
use App\Repository\WatcherRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:clean-watcher-logs',
    description: 'Remove expired watcher logs based on per-watcher retention settings',
)]
class CleanWatcherLogsCommand extends Command
{
    public function __construct(
        private readonly WatcherRepository $watcherRepository,
        private readonly WatcherLogRepository $watcherLogRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();
        $totalDeleted = 0;

        foreach ($this->watcherRepository->findAllOrderedByName() as $watcher) {
            $config = $watcher->getConfig();
            $retentionDays = (int)($config['log_retention_days'] ?? 30);
            $debugRetentionHours = (int)($config['debug_log_retention_hours'] ?? 24);

            // Remove info/warn/error logs older than log_retention_days
            if ($retentionDays > 0) {
                $before = $now->modify("-{$retentionDays} days");
                foreach (['info', 'warn', 'error'] as $level) {
                    $deleted = $this->watcherLogRepository->deleteOlderThan($watcher, $before, $level);
                    $totalDeleted += $deleted;
                }
            }

            // Remove debug logs older than debug_log_retention_hours
            if ($debugRetentionHours > 0) {
                $before = $now->modify("-{$debugRetentionHours} hours");
                $deleted = $this->watcherLogRepository->deleteOlderThan($watcher, $before, 'debug');
                $totalDeleted += $deleted;
            }
        }

        $io->success("Cleaned {$totalDeleted} watcher log entries.");

        return Command::SUCCESS;
    }
}
