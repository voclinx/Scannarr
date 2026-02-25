<?php

namespace App\Enum;

enum DeletionStatus: string
{
    case PENDING = 'pending';
    case REMINDER_SENT = 'reminder_sent';
    case EXECUTING = 'executing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
