<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

abstract class TicketNotification extends BaseNotification
{
    public function __construct(public Ticket $ticket)
    {
        parent::__construct();
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }

    protected function isCustomer(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->getKey() === $this->ticket->customer_id;
    }

    protected function dueAt(): string
    {
        return $this->ticket->sla_due_at
            ->setTimezone((string) config('business_hours.timezone'))
            ->format('D j M Y H:i T');
    }
}
