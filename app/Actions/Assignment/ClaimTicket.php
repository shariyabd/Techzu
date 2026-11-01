<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentUnavailable;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;

final class ClaimTicket
{
    public function __construct(
        private readonly UnassignedTicketAssigner $assigner,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    /** @throws AgentUnavailable|TicketAlreadyClaimed */
    public function handle(User $agent, Ticket $ticket): Ticket
    {
        $now = CarbonImmutable::now();

        return $ticket->getConnection()->transaction(function () use ($agent, $ticket, $now): Ticket {
            if (! $this->isAvailable($agent)) {
                throw new AgentUnavailable;
            }

            if (! $this->assigner->assignIfUnassigned($ticket, $agent, $now)) {
                throw TicketAlreadyClaimed::for($ticket->number);
            }

            $ticket->refresh();

            TicketAssigned::dispatch($ticket, $agent, $agent, null, AssignmentMethod::Claimed);

            if ($ticket->status === TicketStatus::Open) {
                $this->changeStatus->handleLocked($agent, $ticket, TicketStatus::InProgress);
            }

            return $ticket;
        });
    }

    private function isAvailable(User $agent): bool
    {
        return $agent->newQuery()
            ->whereKey($agent->getKey())
            ->where('is_available', true)
            ->lockForUpdate()
            ->exists();
    }
}
