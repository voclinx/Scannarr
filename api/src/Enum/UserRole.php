<?php

namespace App\Enum;

enum UserRole: string
{
    case ADMIN = 'ROLE_ADMIN';
    case ADVANCED_USER = 'ROLE_ADVANCED_USER';
    case USER = 'ROLE_USER';
    case GUEST = 'ROLE_GUEST';
}
