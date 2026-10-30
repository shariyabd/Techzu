<?php

declare(strict_types=1);

namespace App\Notifications;

final class TicketNeedsAssignmentNotification extends TicketNotification
{
    protected function title(object $notifiable): string
    {
        return sprintf('Ticket %s needs manual assignment', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('No agent was available for "%s". Please assign it manually.', $this->ticket->subject);
    }
}
