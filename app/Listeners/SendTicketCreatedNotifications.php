<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Notifications\TicketCreatedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketCreatedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketCreated $event): void
    {
        if ($event->imported) {
            return;
        }

        $customer = $this->recipients->find($event->ticket->customer_id);
        if ($customer !== null) {
            Notification::send($customer, new TicketCreatedNotification($event->ticket));
        }

        if (! config('tickets.auto_assign')) {
            Notification::send(
                $this->recipients->excluding($this->recipients->admins(), $event->actor),
                new TicketCreatedNotification($event->ticket),
            );
        }
    }
}
