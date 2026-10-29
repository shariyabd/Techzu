<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterCustomer
{
    public function handle(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->assignRole(Role::Customer);

            return $user;
        });
    }
}
