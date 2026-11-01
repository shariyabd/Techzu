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

    /** @return array{id: string, title: string, body: string, url: string|null, created_at: string}|null */
    public function latestUnread(User $user): ?array
    {
        $notification = $this->visible($user, $user->unreadNotifications())
            ->select(['id', 'data', 'created_at'])
            ->first();

        if ($notification === null) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
            'body' => is_string($data['body'] ?? null) ? $data['body'] : '',
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'created_at' => $notification->created_at?->toIso8601String() ?? '',
        ];
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
