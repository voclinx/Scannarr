<?php

declare(strict_types=1);

namespace App\Enum;

enum FileEventType: string
{
    case CREATED = 'file.created';
    case DELETED = 'file.deleted';
    case RENAMED = 'file.renamed';
    case MODIFIED = 'file.modified';
}
