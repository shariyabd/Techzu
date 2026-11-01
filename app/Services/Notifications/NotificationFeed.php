<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

final class NotificationFeed
{
    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    public function for(User $user, int $perPage, bool $unreadOnly): LengthAwarePaginator
    {
        $relation = $this->visible($user, $unreadOnly ? $user->unreadNotifications() : $user->notifications());

        return $relation->select(['id', 'type', 'data', 'read_at', 'created_at'])->paginate($perPage)->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return $this->visible($user, $user->unreadNotifications())->count();
    }

    /**
     * @param  MorphMany<DatabaseNotification, User>  $relation
     * @return MorphMany<DatabaseNotification, User>
     */
    private function visible(User $user, MorphMany $relation): MorphMany
    {
        $hidden = StaffOnlyNotifications::hiddenFrom($user);

        if ($hidden !== []) {
            $relation->whereNotIn('type', $hidden);
        }

        return $relation;
    }
}
