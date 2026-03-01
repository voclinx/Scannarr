<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledDeletion;
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
        $totalFailed = $this->processDeletionBatch($deletions, $io);

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param ScheduledDeletion[] $deletions
     */
    private function processDeletionBatch(array $deletions, SymfonyStyle $io): int
    {
        $totalInitiated = 0;
        $totalFailed = 0;

        foreach ($deletions as $deletion) {
            $this->logDeletionHeader($deletion, $io);
            $succeeded = $this->processSingleDeletion($deletion, $io);
            $totalInitiated += (int)$succeeded;
            $totalFailed += (int)!$succeeded;
        }

        $io->success(sprintf('Processing complete. Initiated: %d, Failed: %d', $totalInitiated, $totalFailed));

        return $totalFailed;
    }

    private function logDeletionHeader(ScheduledDeletion $deletion, SymfonyStyle $io): void
    {
        $io->section(sprintf(
            'Processing deletion #%s (scheduled: %s, %d items)',
            (string)$deletion->getId(),
            $deletion->getScheduledDate()?->format('Y-m-d') ?? '??',
            $deletion->getItems()->count(),
        ));
    }

    private function processSingleDeletion(ScheduledDeletion $deletion, SymfonyStyle $io): bool
    {
        try {
            $this->deletionService->executeDeletion($deletion);
            $this->logDeletionStatus($deletion->getStatus(), $io);

            return true;
        } catch (Throwable $e) {
            $this->logDeletionError($deletion, $e, $io);

            return false;
        }
    }

    private function logDeletionStatus(DeletionStatus $status, SymfonyStyle $io): void
    {
        $io->text(sprintf('  → Status: %s', $status->value));

        $statusMessages = [
            DeletionStatus::COMPLETED->value => '  → Completed immediately (no physical files to delete)',
            DeletionStatus::EXECUTING->value => '  → Command sent to watcher, awaiting completion',
            DeletionStatus::WAITING_WATCHER->value => '  → Watcher offline, will retry on reconnection',
        ];

        if (isset($statusMessages[$status->value])) {
            $io->text($statusMessages[$status->value]);
        }
    }

    private function logDeletionError(ScheduledDeletion $deletion, Throwable $e, SymfonyStyle $io): void
    {
        $this->logger->error('Error processing scheduled deletion', [
            'deletion_id' => (string)$deletion->getId(),
            'error' => $e->getMessage(),
        ]);

        $io->error(sprintf(
            'Error processing deletion #%s: %s',
            (string)$deletion->getId(),
            $e->getMessage(),
        ));
    }
}
