<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendSlaNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handleApproaching(TicketSlaApproaching $event): void
    {
        $agent = $this->recipients->find($event->ticket->assigned_agent_id);

        Notification::send(
            $this->recipients->excluding($agent !== null ? [$agent] : $this->recipients->admins(), null),
            new SlaApproachingNotification($event->ticket),
        );
    }

    public function handleBreached(TicketSlaBreached $event): void
    {
        $targets = [$this->recipients->find($event->ticket->assigned_agent_id), ...$this->recipients->admins()->all()];

        Notification::send($this->recipients->excluding($targets, null), new SlaBreachedNotification($event->ticket));
    }
}
