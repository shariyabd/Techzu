<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Str;
use LogicException;

final class TicketRepliedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public readonly TicketReply $reply)
    {
        if ($reply->is_internal) {
            throw new LogicException('Internal notes use InternalNoteAddedNotification.');
        }

        parent::__construct($ticket);
    }

    protected function title(object $notifiable): string
    {
        return sprintf('New reply on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return Str::limit($this->reply->body, 160);
    }
}
