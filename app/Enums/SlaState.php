<?php

declare(strict_types=1);

namespace App\Enums;

enum SlaState: string
{
    case Within = 'within';
    case Approaching = 'approaching';
    case Breached = 'breached';
    case Paused = 'paused';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Within => 'Within SLA',
            self::Approaching => 'Approaching breach',
            self::Breached => 'Breached',
            self::Paused => 'Paused',
            self::Stopped => 'Stopped',
        };
    }
}
