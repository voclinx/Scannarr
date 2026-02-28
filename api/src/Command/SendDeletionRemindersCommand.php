<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\DeletionStatus;
use App\ExternalService\Notification\DiscordNotificationService;
use App\Repository\ScheduledDeletionRepository;
use DateTime;
use DateTimeImmutable;
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

        $today = new DateTime('today');
        $deletions = $this->deletionRepository->findNeedingReminder();

        if ($deletions === []) {
            $io->info('No deletions needing reminders.');

            return Command::SUCCESS;
        }

        $remindersSent = 0;

        foreach ($deletions as $deletion) {
            $scheduledDate = $deletion->getScheduledDate();
            $reminderDaysBefore = $deletion->getReminderDaysBefore();

            if ($scheduledDate === null || $reminderDaysBefore === null) {
                continue;
            }

            // Calculate the reminder date
            $reminderDate = (clone $scheduledDate);
            if ($reminderDate instanceof DateTime) {
                $reminderDate->modify("-{$reminderDaysBefore} days");
            }

            // Check if today is on or after the reminder date
            if ($today >= $reminderDate) {
                $io->text(sprintf(
                    'Sending reminder for deletion #%s (scheduled: %s, %d items)',
                    (string)$deletion->getId(),
                    $scheduledDate->format('Y-m-d'),
                    $deletion->getItems()->count(),
                ));

                try {
                    $sent = $this->discordService->sendDeletionReminder($deletion);

                    if ($sent) {
                        $deletion->setReminderSentAt(new DateTimeImmutable());
                        $deletion->setStatus(DeletionStatus::REMINDER_SENT);
                        $this->em->flush();
                        ++$remindersSent;

                        $io->text('  → Reminder sent successfully');
                    } else {
                        $io->warning('  → Failed to send reminder (webhook not configured or error)');
                    }
                } catch (Throwable $e) {
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
        }

        $io->success(sprintf('Reminders sent: %d', $remindersSent));

        return Command::SUCCESS;
    }
}
