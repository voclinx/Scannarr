<?php

namespace App\Enum;

enum VolumeType: string
{
    case LOCAL = 'local';
    case NETWORK = 'network';
}
