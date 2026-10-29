<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;

final class Recipients
{
    private const COLUMNS = ['id', 'name', 'email'];

    public function find(?int $userId): ?User
    {
        return $userId === null ? null : User::query()->whereKey($userId)->first(self::COLUMNS);
    }

    /** @return Collection<int, User> */
    public function admins(): Collection
    {
        return User::query()->admins()->orderBy('id')->get(self::COLUMNS)->toBase();
    }

    /**
     * @param  iterable<int, User|null>  $users
     * @return Collection<int, User>
     */
    public function excluding(iterable $users, ?User $actor): Collection
    {
        return collect($users)
            ->filter()
            ->unique(static fn (User $user): mixed => $user->getKey())
            ->reject(static fn (User $user): bool => $actor !== null && $user->getKey() === $actor->getKey())
            ->values();
    }
}
