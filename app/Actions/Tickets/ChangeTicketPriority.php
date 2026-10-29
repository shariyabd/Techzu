<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketPriority;
use App\Events\TicketPriorityChanged;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ChangeTicketPriority
{
    public function __construct(
        private readonly SlaCalculator $sla,
    ) {}

    public function handle(User $actor, Ticket $ticket, TicketPriority $to): Ticket
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $ticket, $to, $now): Ticket {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->setRawAttributes($locked->getAttributes(), true);

            $from = $ticket->priority;

            if ($from === $to) {
                return $ticket;
            }

            $ticket->priority = $to;
            $this->sla->recalculateForPriority($ticket, $now);
            $ticket->save();

            TicketPriorityChanged::dispatch($ticket, $actor, $from, $to);

            return $ticket;
        });
    }
}
