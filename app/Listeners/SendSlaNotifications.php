<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Sla\DeliverEscalation;
use App\Enums\EscalationLevel;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendSlaNotifications
{
    public function __construct(
        private readonly Recipients $recipients,
        private readonly RecordTicketActivity $activity,
        private readonly DeliverEscalation $deliverEscalation,
    ) {}

    public function handleApproaching(TicketSlaApproaching $event): void
    {
        $ticket = $event->ticket;

        $this->deliverEscalation->handle($ticket, EscalationLevel::Approaching, function () use ($ticket): void {
            $this->activity->recordSlaApproaching($ticket);

            $agent = $this->recipients->find($ticket->assigned_agent_id);

            Notification::send(
                $this->recipients->excluding($agent !== null ? [$agent] : $this->recipients->admins(), null),
                (new SlaApproachingNotification($ticket))->beforeCommit(),
            );
        });
    }

    public function handleBreached(TicketSlaBreached $event): void
    {
        $ticket = $event->ticket;

        $this->deliverEscalation->handle($ticket, EscalationLevel::Breached, function () use ($ticket): void {
            $this->activity->recordSlaBreached($ticket);

            $targets = [$this->recipients->find($ticket->assigned_agent_id), ...$this->recipients->admins()->all()];

            Notification::send(
                $this->recipients->excluding($targets, null),
                (new SlaBreachedNotification($ticket))->beforeCommit(),
            );
        });
    }
}
