<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;

final class ClaimTicket
{
    public function __construct(
        private readonly UnassignedTicketAssigner $assigner,
    ) {}

    public function handle(User $agent, Ticket $ticket): Ticket
    {
        $now = CarbonImmutable::now();
        $before = $ticket->status;

        return $ticket->getConnection()->transaction(function () use ($agent, $ticket, $now, $before): Ticket {
            if (! $this->assigner->assignIfUnassigned($ticket, $agent, $now)) {
                throw TicketAlreadyClaimed::for($ticket->number);
            }

            $ticket->refresh();

            TicketAssigned::dispatch($ticket, $agent, $agent, null, AssignmentMethod::Claimed);

            if ($before === TicketStatus::Open && $ticket->status === TicketStatus::InProgress) {
                TicketStatusChanged::dispatch($ticket, $agent, TicketStatus::Open, TicketStatus::InProgress);
            }

            return $ticket;
        });
    }
}
