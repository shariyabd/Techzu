<?php

declare(strict_types=1);

namespace App\Enums;

enum EscalationLevel: int
{
    case None = 0;
    case Approaching = 1;
    case Breached = 2;
}
