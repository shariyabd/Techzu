<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SlaRulesChanged;
use App\Jobs\RecalculateActiveTicketSlas;

final class QueueSlaRecalculation
{
    public function handle(SlaRulesChanged $event): void
    {
        if (! $event->recalculateActiveTickets) {
            return;
        }

        RecalculateActiveTicketSlas::dispatch();
    }
}
