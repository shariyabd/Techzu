<?php

declare(strict_types=1);

namespace App\Enums;

enum SavedReplyScope: string
{
    case Mine = 'mine';
    case Team = 'team';
}
