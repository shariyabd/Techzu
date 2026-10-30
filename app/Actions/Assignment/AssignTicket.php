<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Enums\AssignmentMethod;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentNotEligible;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AssignTicket
{
    public function __construct(
        private readonly UnassignedTicketAssigner $assigner,
    ) {}

    public function handle(User $actor, Ticket $ticket, User $agent): Ticket
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $ticket, $agent, $now): Ticket {
            if (! User::query()->availableAgents()->whereKey($agent->getKey())->exists()) {
                throw new AgentNotEligible(sprintf('%s is not an available agent.', $agent->name));
            }

            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->setRawAttributes($locked->getAttributes(), true);
            if (! $ticket->isActive()) {
                throw new TicketClosed(sprintf('Ticket %s is finished and can no longer be assigned.', $ticket->number));
            }

            $previousId = $ticket->assigned_agent_id;

            if ($previousId === $agent->getKey()) {
                return $ticket;
            }

            $ticket->assigned_agent_id = $agent->getKey();
            $ticket->assigned_at = $now;
            $ticket->save();
            $ticket->unsetRelation('assignedAgent');

            $this->assigner->markAgentAssigned($agent, $now);

            $previous = $previousId === null ? null : User::query()->find($previousId);

            TicketAssigned::dispatch($ticket, $actor, $agent, $previous, AssignmentMethod::Manual);

            return $ticket;
        });
    }
}
