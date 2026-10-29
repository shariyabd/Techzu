<?php

declare(strict_types=1);

namespace App\Notifications;

final class SlaApproachingNotification extends TicketNotification
{
    protected function title(object $notifiable): string
    {
        return sprintf('SLA approaching on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('"%s" is due %s.', $this->ticket->subject, $this->dueAt());
    }
}
