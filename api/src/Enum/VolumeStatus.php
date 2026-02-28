<?php

declare(strict_types=1);

namespace App\Enum;

enum VolumeStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ERROR = 'error';
}
