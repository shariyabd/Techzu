<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(
        private readonly ChangeUserRole $changeRole,
        private readonly SyncAgentProfile $syncAgent,
    ) {}

    /** @param array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null} $data */
    public function handle(User $admin, array $data): User
    {
        return DB::transaction(function () use ($admin, $data): User {
            $isAvailable = $data['is_available'] ?? true;

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => (string) $data['password'],
                'is_available' => $isAvailable,
            ]);

            $role = Role::from($data['role']);
            $this->changeRole->handle($admin, $user, $role);

            if ($role === Role::Agent) {
                $this->syncAgent->handle($admin, $user, $data['category_ids'] ?? [], $isAvailable);
            }

            return $user;
        });
    }
}
