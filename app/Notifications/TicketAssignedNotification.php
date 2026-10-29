<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

final class TicketAssignedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public readonly User $agent)
    {
        parent::__construct($ticket);
    }

    protected function title(object $notifiable): string
    {
        return $this->isAgent($notifiable)
            ? sprintf('Ticket %s was assigned to you', $this->ticket->number)
            : sprintf('Your ticket %s has an agent', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return $this->isAgent($notifiable)
            ? sprintf('"%s" is now yours.', $this->ticket->subject)
            : sprintf('%s is now handling "%s".', $this->agent->name, $this->ticket->subject);
    }

    private function isAgent(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->getKey() === $this->agent->getKey();
    }
}
