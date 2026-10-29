<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;

final class MarkAllNotificationsRead
{
    public function handle(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => CarbonImmutable::now()]);
    }
}
