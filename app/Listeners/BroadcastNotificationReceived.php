<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Broadcast\NotificationReceived;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;

final class BroadcastNotificationReceived
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        NotificationReceived::dispatch($event->notifiable->id, CarbonImmutable::now());
    }
}
