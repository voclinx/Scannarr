<?php

declare(strict_types=1);

namespace App\Contract\Notification;

use App\Entity\ScheduledDeletion;

interface NotificationChannelInterface
{
    public function sendDeletionReminder(ScheduledDeletion $deletion): bool;

    public function sendDeletionSuccess(ScheduledDeletion $deletion): bool;

    public function sendDeletionError(ScheduledDeletion $deletion): bool;
}
