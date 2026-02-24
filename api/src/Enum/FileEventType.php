<?php

namespace App\Enum;

enum FileEventType: string
{
    case CREATED = 'file.created';
    case DELETED = 'file.deleted';
    case RENAMED = 'file.renamed';
    case MODIFIED = 'file.modified';
}
