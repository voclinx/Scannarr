<?php

namespace App\Enum;

enum VolumeStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ERROR = 'error';
}
