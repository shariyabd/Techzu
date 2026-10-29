<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AgentSelector
{
    public function selectFor(Ticket $ticket, bool $lock = false): ?User
    {
        $candidates = User::query()
            ->availableAgents()
            ->whereHas('categories', static fn (Builder $q) => $q->whereKey($ticket->category_id));

        if ($lock) {
            $ids = (clone $candidates)
                ->orderBy('users.id')
                ->lockForUpdate()
                ->pluck('users.id')
                ->all();

            if ($ids === []) {
                return null;
            }

            $candidates = User::query()->whereKey($ids);
        }

        $active = array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::active());

        return $candidates
            ->select('users.*')
            ->withCount(['assignedTickets as active_tickets_count' => static fn (Builder $q) => $q->whereIn('status', $active)])
            ->orderBy('active_tickets_count')
            ->orderByRaw('users.last_assigned_at IS NOT NULL')
            ->orderBy('users.last_assigned_at')
            ->orderBy('users.id')
            ->first();
    }
}
