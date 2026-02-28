<?php

declare(strict_types=1);

namespace App\Enum;

enum DeletionStatus: string
{
    case PENDING = 'pending';
    case REMINDER_SENT = 'reminder_sent';
    case EXECUTING = 'executing';
    case WAITING_WATCHER = 'waiting_watcher';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
