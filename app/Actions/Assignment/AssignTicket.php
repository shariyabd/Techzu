<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentNotEligible;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AgentEligibility;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AssignTicket
{
    public function __construct(
        private readonly UnassignedTicketAssigner $assigner,
        private readonly AgentEligibility $eligibility,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    /** @throws AgentNotEligible|AuthorizationException|TicketClosed */
    public function handle(User $actor, Ticket $ticket, User $agent, bool $outsideCategory = false): Ticket
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $ticket, $agent, $outsideCategory, $now): Ticket {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->setRawAttributes($locked->getAttributes(), true);

            if (! $ticket->isActive()) {
                throw new TicketClosed(sprintf('Ticket %s is finished and can no longer be assigned.', $ticket->number));
            }

            Gate::forUser($actor)->authorize('assign', $ticket);

            $previousId = $ticket->assigned_agent_id;

            if ($previousId === $agent->getKey()) {
                return $ticket;
            }

            $this->eligibility->ensureEligible($agent, $ticket, $outsideCategory);

            $ticket->assigned_agent_id = $agent->getKey();
            $ticket->assigned_at = $now;
            $ticket->save();
            $ticket->unsetRelation('assignedAgent');

            $this->assigner->markAgentAssigned($agent, $now);

            $previous = $previousId === null ? null : User::query()->find($previousId);

            TicketAssigned::dispatch($ticket, $actor, $agent, $previous, AssignmentMethod::Manual);

            if ($ticket->status === TicketStatus::Open) {
                $this->changeStatus->handleLocked($actor, $ticket, TicketStatus::InProgress);
            }

            return $ticket;
        });
    }
}
