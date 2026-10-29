<?php

declare(strict_types=1);

namespace App\Notifications;

final class TicketResolvedNotification extends TicketNotification
{
    protected function title(object $notifiable): string
    {
        return sprintf('Ticket %s was resolved', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('"%s" is resolved. Reopen it from the ticket page if the problem persists.', $this->ticket->subject);
    }
}
