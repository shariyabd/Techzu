<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Str;

final class InternalNoteAddedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public readonly TicketReply $reply)
    {
        parent::__construct($ticket);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    protected function title(object $notifiable): string
    {
        return sprintf('Internal note on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return Str::limit($this->reply->body, 160);
    }
}
