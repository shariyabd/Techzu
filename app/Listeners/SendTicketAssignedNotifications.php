<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Notifications\TicketAssignedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketAssignedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketAssigned $event): void
    {
        $recipients = $this->recipients->excluding([
            $this->recipients->find($event->agent->id),
            $this->recipients->find($event->ticket->customer_id),
        ], $event->actor);

        Notification::send($recipients, new TicketAssignedNotification($event->ticket, $event->agent));
    }
}
