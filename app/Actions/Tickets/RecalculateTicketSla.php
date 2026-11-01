<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Events\TicketSlaRecalculated;
use App\Models\Ticket;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecalculateTicketSla
{
    public function __construct(
        private readonly SlaCalculator $sla,
    ) {}

    public function handle(int $ticketId, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($ticketId, $now): void {
            $ticket = Ticket::query()
                ->whereKey($ticketId)
                ->whereIn('status', array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::active()))
                ->lockForUpdate()
                ->first();

            if ($ticket === null) {
                return;
            }

            $previousDueAt = $ticket->sla_due_at;

            $this->sla->recalculateForPriority($ticket, $now);
            $ticket->save();

            if (! $ticket->sla_due_at->equalTo($previousDueAt)) {
                TicketSlaRecalculated::dispatch($ticket, $previousDueAt, $ticket->sla_due_at);
            }
        });
    }
}
