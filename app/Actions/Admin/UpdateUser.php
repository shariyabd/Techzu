<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUser
{
    public function __construct(
        private readonly ChangeUserRole $changeRole,
        private readonly SyncAgentProfile $syncAgent,
    ) {}

    /** @param array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null} $data */
    public function handle(User $admin, User $user, array $data): User
    {
        return DB::transaction(function () use ($admin, $user, $data): User {
            $role = Role::from($data['role']);

            $this->changeRole->handle($admin, $user, $role);

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($data['password'] !== null) {
                $user->password = $data['password'];
                $user->tokens()->delete();
            }

            $user->save();

            if ($role === Role::Agent) {
                $this->syncAgent->handle(
                    $admin,
                    $user,
                    $data['category_ids'] ?? $this->currentCategoryIds($user),
                    $data['is_available'] ?? $user->is_available,
                );
            }

            return $user;
        });
    }

    /** @return list<int> */
    private function currentCategoryIds(User $user): array
    {
        return $user->categories()
            ->pluck('categories.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
