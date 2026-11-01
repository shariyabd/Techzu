<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\Users\AvatarStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateAvatar
{
    public function __construct(private readonly AvatarStorer $avatars) {}

    public function handle(User $user, ?UploadedFile $file): User
    {
        $previous = $user->avatar_path;
        $stored = $file === null ? null : $this->avatars->store($file);

        try {
            return DB::transaction(function () use ($user, $previous, $stored): User {
                $user->forceFill(['avatar_path' => $stored])->save();

                DB::afterCommit(fn () => $this->avatars->delete($previous));

                return $user;
            });
        } catch (Throwable $e) {
            $this->avatars->delete($stored);

            throw $e;
        }
    }
}
