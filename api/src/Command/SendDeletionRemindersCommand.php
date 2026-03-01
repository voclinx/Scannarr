<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledDeletion;
use App\Enum\DeletionStatus;
use App\ExternalService\Notification\DiscordNotificationService;
use App\Repository\ScheduledDeletionRepository;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'scanarr:send-reminders',
    description: 'Send Discord reminders for upcoming scheduled deletions (cron: daily at 09:00)',
)]
class SendDeletionRemindersCommand extends Command
{
    public function __construct(
        private readonly ScheduledDeletionRepository $deletionRepository,
        private readonly DiscordNotificationService $discordService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sending deletion reminders');

        $deletions = $this->deletionRepository->findNeedingReminder();

        if ($deletions === []) {
            $io->info('No deletions needing reminders.');

            return Command::SUCCESS;
        }

        $remindersSent = 0;
        $today = new DateTime('today');

        foreach ($deletions as $deletion) {
            if ($this->isDueForReminder($deletion, $today)) {
                $remindersSent += $this->sendReminder($deletion, $io);
            }
        }

        $io->success(sprintf('Reminders sent: %d', $remindersSent));

        return Command::SUCCESS;
    }

    private function isDueForReminder(ScheduledDeletion $deletion, DateTime $today): bool
    {
        $scheduledDate = $deletion->getScheduledDate();
        $reminderDaysBefore = $deletion->getReminderDaysBefore();

        if (!$scheduledDate instanceof DateTimeInterface || $reminderDaysBefore === null) {
            return false;
        }

        $reminderDate = (clone $scheduledDate);
        if ($reminderDate instanceof DateTime) {
            $reminderDate->modify("-{$reminderDaysBefore} days");
        }

        return $today >= $reminderDate;
    }

    private function sendReminder(ScheduledDeletion $deletion, SymfonyStyle $io): int
    {
        $io->text(sprintf(
            'Sending reminder for deletion #%s (scheduled: %s, %d items)',
            (string)$deletion->getId(),
            $deletion->getScheduledDate()?->format('Y-m-d') ?? '??',
            $deletion->getItems()->count(),
        ));

        try {
            return $this->attemptSendReminder($deletion, $io);
        } catch (Throwable $e) {
            $this->logReminderError($deletion, $e, $io);

            return 0;
        }
    }

    private function attemptSendReminder(ScheduledDeletion $deletion, SymfonyStyle $io): int
    {
        $sent = $this->discordService->sendDeletionReminder($deletion);

        if (!$sent) {
            $io->warning('  → Failed to send reminder (webhook not configured or error)');

            return 0;
        }

        $deletion->setReminderSentAt(new DateTimeImmutable());
        $deletion->setStatus(DeletionStatus::REMINDER_SENT);
        $this->em->flush();

        $io->text('  → Reminder sent successfully');

        return 1;
    }

    private function logReminderError(ScheduledDeletion $deletion, Throwable $e, SymfonyStyle $io): void
    {
        $this->logger->error('Error sending deletion reminder', [
            'deletion_id' => (string)$deletion->getId(),
            'error' => $e->getMessage(),
        ]);

        $io->error(sprintf(
            'Error sending reminder for deletion #%s: %s',
            (string)$deletion->getId(),
            $e->getMessage(),
        ));
    }
}
