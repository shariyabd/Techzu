<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Exceptions\Domain\AgentNotEligible;
use App\Models\Ticket;
use App\Models\User;

final class AgentEligibility
{
    /** @throws AgentNotEligible */
    public function ensureEligible(User $agent, Ticket $ticket, bool $outsideCategory): void
    {
        $available = User::query()
            ->availableAgents()
            ->whereKey($agent->getKey())
            ->lockForUpdate()
            ->first(['users.id']);

        if ($available === null) {
            throw new AgentNotEligible(sprintf('%s is not an available agent.', $agent->name));
        }

        if ($outsideCategory) {
            return;
        }

        $handlesCategory = $agent->categories()
            ->where('categories.id', $ticket->category_id)
            ->exists();

        if (! $handlesCategory) {
            throw new AgentNotEligible(sprintf('%s does not handle this ticket\'s category.', $agent->name));
        }
    }
}
