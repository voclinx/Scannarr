<?php

declare(strict_types=1);

namespace App\Enum;

enum VolumeType: string
{
    case LOCAL = 'local';
    case NETWORK = 'network';
}
