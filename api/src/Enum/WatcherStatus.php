<?php

declare(strict_types=1);

namespace App\Enum;

enum WatcherStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case CONNECTED = 'connected';
    case DISCONNECTED = 'disconnected';
    case REVOKED = 'revoked';
}
