<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Profile\UpdateAvatar;
use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\UserSessions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateUser
{
    public function __construct(
        private readonly ChangeUserRole $changeRole,
        private readonly SyncAgentProfile $syncAgent,
        private readonly UserSessions $sessions,
        private readonly UpdateAvatar $updateAvatar,
    ) {}

    /** @param array{name: string, email: string, password: string|null, role: string, category_ids: list<int>|null, is_available: bool|null, avatar?: UploadedFile|null, remove_avatar?: bool} $data */
    public function handle(User $admin, User $user, array $data): User
    {
        return DB::transaction(function () use ($admin, $user, $data): User {
            $role = Role::from($data['role']);

            $this->changeRole->handle($admin, $user, $role);

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($data['password'] !== null) {
                $user->password = $data['password'];
                $user->setRememberToken(Str::random(60));
                $user->tokens()->delete();
                $this->sessions->endFor($user, keepCurrent: $admin->is($user));
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

            $avatar = $data['avatar'] ?? null;

            if ($avatar !== null || ($data['remove_avatar'] ?? false)) {
                $this->updateAvatar->handle($user, $avatar);
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
