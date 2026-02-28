<?php

declare(strict_types=1);

namespace App\Enum;

enum TorrentStatus: string
{
    case SEEDING = 'seeding';
    case PAUSED = 'paused';
    case STALLED = 'stalled';
    case ERROR = 'error';
    case COMPLETED = 'completed';
    case REMOVED = 'removed';
}
