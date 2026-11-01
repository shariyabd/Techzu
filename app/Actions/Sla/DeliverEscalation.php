<?php

declare(strict_types=1);

namespace App\Actions\Sla;

use App\Enums\EscalationLevel;
use App\Models\Ticket;
use Closure;
use Illuminate\Support\Facades\DB;

final class DeliverEscalation
{
    private const int DEADLOCK_ATTEMPTS = 3;

    /** @param Closure(): void $deliver */
    public function handle(Ticket $ticket, EscalationLevel $level, Closure $deliver): bool
    {
        return DB::transaction(static function () use ($ticket, $level, $deliver): bool {
            $current = Ticket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->first(['id', 'escalation_level', 'escalation_notified_level']);

            if ($current === null
                || $current->escalation_level->value < $level->value
                || $current->escalation_notified_level->value >= $level->value) {
                return false;
            }

            $deliver();

            Ticket::query()
                ->whereKey($ticket->getKey())
                ->toBase()
                ->update(['escalation_notified_level' => $level->value]);

            $ticket->forceFill(['escalation_notified_level' => $level])->syncOriginalAttribute('escalation_notified_level');

            return true;
        }, self::DEADLOCK_ATTEMPTS);
    }
}
