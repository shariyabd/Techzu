<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterCustomer
{
    public function handle(string $name, string $email, string $password): User
    {
        return DB::transaction(fn (): User => User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]));
    }
}
