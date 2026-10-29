<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketResolved;
use App\Notifications\TicketResolvedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketResolvedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketResolved $event): void
    {
        Notification::send(
            $this->recipients->excluding([$this->recipients->find($event->ticket->customer_id)], $event->actor),
            new TicketResolvedNotification($event->ticket),
        );
    }
}
