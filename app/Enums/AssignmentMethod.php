<?php

declare(strict_types=1);

namespace App\Enums;

enum AssignmentMethod: string
{
    case Manual = 'manual';
    case Claimed = 'claimed';
    case Automatic = 'automatic';
}
