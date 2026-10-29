<?php

declare(strict_types=1);

namespace App\Notifications;

final class SlaBreachedNotification extends TicketNotification
{
    protected function title(object $notifiable): string
    {
        return sprintf('SLA breached on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('"%s" passed its resolution deadline (%s) and has been escalated.', $this->ticket->subject, $this->dueAt());
    }
}
