<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class UnassignedTicketAssigner
{
    public function assignIfUnassigned(Ticket $ticket, User $agent, CarbonImmutable $now): bool
    {
        $affected = $ticket->newQuery()
            ->whereKey($ticket->getKey())
            ->whereNull('assigned_agent_id')
            ->whereIn('status', array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::active()))
            ->update([
                'assigned_agent_id' => $agent->getKey(),
                'assigned_at' => $now,
                'status' => DB::raw(sprintf(
                    "CASE WHEN status = '%s' THEN '%s' ELSE status END",
                    TicketStatus::Open->value,
                    TicketStatus::InProgress->value,
                )),
            ]);

        if ($affected !== 1) {
            return false;
        }

        $this->markAgentAssigned($agent, $now);

        return true;
    }

    public function markAgentAssigned(User $agent, CarbonImmutable $now): void
    {
        $agent->newQuery()->whereKey($agent->getKey())->update(['last_assigned_at' => $now]);
        $agent->setAttribute('last_assigned_at', $now);
        $agent->syncOriginalAttribute('last_assigned_at');
    }
}
