<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class ChangePassword
{
    public function __construct(private readonly UserSessions $sessions) {}

    public function handle(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password): User {
            $current = $user->currentAccessToken();

            $user->password = $password;
            $user->setRememberToken(Str::random(60));
            $user->save();

            $user->tokens()
                ->when($current instanceof PersonalAccessToken && $current->getKey() !== null, fn ($tokens) => $tokens->whereKeyNot($current->getKey()))
                ->delete();

            $this->sessions->endFor($user, keepCurrent: true);

            return $user;
        });
    }
}
