<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityType;
use App\Enums\AssignmentMethod;
use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketPriorityChanged;
use App\Events\TicketReplied;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Events\TicketStatusChanged;
use App\Events\TicketUnassigned;
use App\Events\TicketUpdated;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Throwable;

final class RecordTicketActivity
{
    public function handleTicketCreated(TicketCreated $event): void
    {
        $ticket = $event->ticket;

        if ($event->imported) {
            $this->record($ticket, $event->actor, ActivityType::Imported, [
                'number' => $ticket->number,
                'external_reference' => $ticket->external_reference,
            ]);

            return;
        }

        $this->record($ticket, $event->actor, ActivityType::Created, [
            'number' => $ticket->number,
            'priority' => $ticket->priority->value,
            'category_id' => $ticket->category_id,
        ]);
    }

    public function handleTicketUpdated(TicketUpdated $event): void
    {
        $changes = $event->changes;

        if (array_key_exists('category_id', $changes)) {
            $from = (int) $changes['category_id']['from'];
            $to = (int) $changes['category_id']['to'];
            $names = Category::query()->whereKey([$from, $to])->pluck('name', 'id');

            $this->record($event->ticket, $event->actor, ActivityType::CategoryChanged, [
                'from' => ['id' => $from, 'name' => $names->get($from)],
                'to' => ['id' => $to, 'name' => $names->get($to)],
            ]);

            unset($changes['category_id']);
        }

        if ($changes === []) {
            return;
        }

        $properties = ['fields' => array_keys($changes), 'from' => [], 'to' => []];
        if (array_key_exists('subject', $changes)) {
            $properties['from']['subject'] = $changes['subject']['from'];
            $properties['to']['subject'] = $changes['subject']['to'];
        }

        $this->record($event->ticket, $event->actor, ActivityType::Updated, $properties);
    }

    public function handleTicketAssigned(TicketAssigned $event): void
    {
        $type = $event->method === AssignmentMethod::Claimed ? ActivityType::Claimed : ActivityType::Assigned;

        $this->record($event->ticket, $event->actor, $type, [
            'method' => $event->method->value,
            'from' => $event->previousAgent === null ? null : $this->summary($event->previousAgent),
            'to' => $this->summary($event->agent),
        ]);
    }

    public function handleTicketUnassigned(TicketUnassigned $event): void
    {
        $this->record($event->ticket, $event->actor, ActivityType::Unassigned, [
            'from' => $this->summary($event->previousAgent),
        ]);
    }

    public function handleTicketStatusChanged(TicketStatusChanged $event): void
    {
        $ticket = $event->ticket;

        $this->record($ticket, $event->actor, ActivityType::StatusChanged, [
            'from' => $event->from->value,
            'to' => $event->to->value,
        ]);

        if ($event->to === TicketStatus::WaitingForCustomer) {
            $this->record($ticket, $event->actor, ActivityType::SlaPaused, [
                'paused_at' => $ticket->sla_paused_at?->toIso8601String(),
            ]);
        }

        if ($event->from === TicketStatus::WaitingForCustomer) {
            $this->record($ticket, $event->actor, ActivityType::SlaResumed, [
                'due_at' => $ticket->sla_due_at->toIso8601String(),
                'warning_at' => $ticket->sla_warning_at->toIso8601String(),
            ]);
        }
    }

    public function handleTicketPriorityChanged(TicketPriorityChanged $event): void
    {
        $this->record($event->ticket, $event->actor, ActivityType::PriorityChanged, [
            'from' => $event->from->value,
            'to' => $event->to->value,
            'due_at' => $event->ticket->sla_due_at->toIso8601String(),
        ]);
    }

    public function handleTicketReplied(TicketReplied $event): void
    {
        $type = $event->reply->is_internal ? ActivityType::InternalNote : ActivityType::Replied;

        $this->record($event->ticket, $event->author, $type, [
            'reply_id' => $event->reply->id,
            'author_is_staff' => $event->author->isStaff(),
        ]);
    }

    public function handleTicketSlaApproaching(TicketSlaApproaching $event): void
    {
        $this->record($event->ticket, null, ActivityType::SlaApproaching, [
            'due_at' => $event->ticket->sla_due_at->toIso8601String(),
        ]);
    }

    public function handleTicketSlaBreached(TicketSlaBreached $event): void
    {
        $this->record($event->ticket, null, ActivityType::Escalated, [
            'level' => EscalationLevel::Breached->value,
            'due_at' => $event->ticket->sla_due_at->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $properties */
    private function record(Ticket $ticket, ?User $actor, ActivityType $type, array $properties): void
    {
        try {
            $logger = activity('ticket')
                ->performedOn($ticket)
                ->event($type->value)
                ->withProperties($properties);

            $actor === null ? $logger->causedByAnonymous() : $logger->causedBy($actor);

            $logger->log($type->describe($properties));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array{id: int, name: string} */
    private function summary(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name];
    }
}
