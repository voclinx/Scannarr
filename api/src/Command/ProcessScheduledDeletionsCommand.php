<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\DeletionStatus;
use App\Repository\ScheduledDeletionRepository;
use App\Service\DeletionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'scanarr:process-deletions',
    description: 'Process scheduled deletions that are due for execution (cron: daily at 23:55)',
)]
class ProcessScheduledDeletionsCommand extends Command
{
    public function __construct(
        private readonly ScheduledDeletionRepository $deletionRepository,
        private readonly DeletionService $deletionService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Processing scheduled deletions');

        $deletions = $this->deletionRepository->findDueForExecution();

        if ($deletions === []) {
            $io->info('No scheduled deletions due for execution today.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d deletion(s) to process.', count($deletions)));

        $totalInitiated = 0;
        $totalFailed = 0;

        foreach ($deletions as $deletion) {
            $io->section(sprintf(
                'Processing deletion #%s (scheduled: %s, %d items)',
                (string)$deletion->getId(),
                $deletion->getScheduledDate()?->format('Y-m-d') ?? '??',
                $deletion->getItems()->count(),
            ));

            try {
                $this->deletionService->executeDeletion($deletion);

                $status = $deletion->getStatus();
                $io->text(sprintf('  → Status: %s', $status->value));

                if ($status === DeletionStatus::COMPLETED) {
                    $io->text('  → Completed immediately (no physical files to delete)');
                } elseif ($status === DeletionStatus::EXECUTING) {
                    $io->text('  → Command sent to watcher, awaiting completion');
                } elseif ($status === DeletionStatus::WAITING_WATCHER) {
                    $io->text('  → Watcher offline, will retry on reconnection');
                }

                // Discord notification is now handled by WatcherMessageProcessor on completion
                ++$totalInitiated;
            } catch (Throwable $e) {
                $this->logger->error('Error processing scheduled deletion', [
                    'deletion_id' => (string)$deletion->getId(),
                    'error' => $e->getMessage(),
                ]);

                $io->error(sprintf(
                    'Error processing deletion #%s: %s',
                    (string)$deletion->getId(),
                    $e->getMessage(),
                ));

                ++$totalFailed;
            }
        }

        $io->success(sprintf(
            'Processing complete. Initiated: %d, Failed: %d',
            $totalInitiated,
            $totalFailed,
        ));

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
