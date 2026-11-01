<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateProfile
{
    /** @param array{name: string} $data */
    public function handle(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->fill(['name' => $data['name']])->save();

            return $user;
        });
    }
}
