<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

final class NotificationFeed
{
    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    public function for(User $user, int $perPage, bool $unreadOnly): LengthAwarePaginator
    {
        $relation = $unreadOnly ? $user->unreadNotifications() : $user->notifications();

        return $relation->select(['id', 'type', 'data', 'read_at', 'created_at'])->paginate($perPage)->withQueryString();
    }
}
