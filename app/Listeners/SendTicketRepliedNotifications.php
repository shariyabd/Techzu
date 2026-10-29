<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketReplied;
use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketRepliedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketReplied $event): void
    {
        $ticket = $event->ticket;

        if ($event->reply->is_internal) {
            $recipients = $this->recipients
                ->excluding([$this->recipients->find($ticket->assigned_agent_id)], $event->author)
                ->reject(static fn (User $user): bool => $user->getKey() === $ticket->customer_id);
            Notification::send($recipients, new InternalNoteAddedNotification($ticket, $event->reply));

            return;
        }

        $targets = match (true) {
            $event->author->id !== $ticket->customer_id => [$this->recipients->find($ticket->customer_id)],
            $ticket->assigned_agent_id !== null => [$this->recipients->find($ticket->assigned_agent_id)],
            default => $this->recipients->admins(),
        };

        Notification::send($this->recipients->excluding($targets, $event->author), new TicketRepliedNotification($ticket, $event->reply));
    }
}
