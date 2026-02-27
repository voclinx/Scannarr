<?php

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
