<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketImportFinished;
use App\Notifications\TicketImportFinishedNotification;
use App\Services\Notifications\Recipients;
use Illuminate\Support\Facades\Notification;

final class SendImportFinishedNotification
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketImportFinished $event): void
    {
        $admin = $this->recipients->find($event->import->user_id);
        if ($admin !== null) {
            Notification::send($admin, new TicketImportFinishedNotification($event->import));
        }
    }
}
