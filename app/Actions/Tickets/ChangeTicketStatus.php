<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Events\TicketResolved;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\InvalidStatusTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ChangeTicketStatus
{
    public function __construct(
        private readonly SlaCalculator $sla,
    ) {}

    public function handle(?User $actor, Ticket $ticket, TicketStatus $to): Ticket
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $ticket, $to, $now): Ticket {
            $this->lockAndRefresh($ticket);

            $from = $ticket->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between($from, $to);
            }

            if ($from === TicketStatus::WaitingForCustomer) {
                $this->sla->resume($ticket, $now);
            }

            if ($to === TicketStatus::WaitingForCustomer) {
                $this->sla->pause($ticket, $now);
            }

            if ($to === TicketStatus::Resolved) {
                $ticket->resolved_at = $now;
            }

            if ($from === TicketStatus::Resolved && $to === TicketStatus::InProgress) {
                $ticket->resolved_at = null;
            }

            if ($to === TicketStatus::Closed) {
                $ticket->closed_at = $now;
            }

            $ticket->status = $to;
            $ticket->save();

            TicketStatusChanged::dispatch($ticket, $actor, $from, $to);

            if ($to === TicketStatus::Resolved) {
                TicketResolved::dispatch($ticket, $actor);
            }

            return $ticket;
        });
    }

    private function lockAndRefresh(Ticket $ticket): void
    {
        $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
        $ticket->setRawAttributes($locked->getAttributes(), true);
    }
}
