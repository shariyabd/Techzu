<?php

declare(strict_types=1);

namespace App\Notifications;

final class TicketCreatedNotification extends TicketNotification
{
    protected function title(object $notifiable): string
    {
        return $this->isCustomer($notifiable)
            ? sprintf('We received your ticket %s', $this->ticket->number)
            : sprintf('New ticket %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return $this->isCustomer($notifiable)
            ? sprintf('Your ticket "%s" has been created. We will reply as soon as possible.', $this->ticket->subject)
            : sprintf('"%s" is waiting to be assigned.', $this->ticket->subject);
    }
}
