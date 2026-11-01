<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketAutoAssignmentFailed;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AgentSelector;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AssignTicketAutomatically
{
    public function __construct(
        private readonly AgentSelector $selector,
        private readonly UnassignedTicketAssigner $assigner,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    public function handle(Ticket $ticket): ?User
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($ticket, $now): ?User {
            $lockable = Ticket::query()
                ->whereKey($ticket->getKey())
                ->whereNull('assigned_agent_id')
                ->whereIn('status', array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::active()))
                ->lockForUpdate()
                ->first();

            if ($lockable === null) {
                return null;
            }

            $agent = $this->selector->selectFor($ticket, lock: true);

            if ($agent === null) {
                Log::info('Auto-assignment found no eligible agent; the ticket stays in the unassigned queue.', [
                    'ticket' => $ticket->number,
                    'category_id' => $ticket->category_id,
                ]);

                TicketAutoAssignmentFailed::dispatch($ticket);

                return null;
            }

            if (! $this->assigner->assignIfUnassigned($ticket, $agent, $now)) {
                return null;
            }

            $ticket->refresh();

            TicketAssigned::dispatch($ticket, null, $agent, null, AssignmentMethod::Automatic);

            if ($ticket->status === TicketStatus::Open) {
                $this->changeStatus->handleLocked(null, $ticket, TicketStatus::InProgress);
            }

            return $agent;
        });
    }
}
