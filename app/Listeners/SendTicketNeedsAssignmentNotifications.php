<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketAutoAssignmentFailed;
use App\Notifications\TicketNeedsAssignmentNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendTicketNeedsAssignmentNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketAutoAssignmentFailed $event): void
    {
        Notification::send($this->recipients->admins(), new TicketNeedsAssignmentNotification($event->ticket));
    }
}
