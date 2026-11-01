<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Profile\ChangePassword;
use App\Actions\Profile\UpdateAvatar;
use App\Actions\Profile\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateRequest;
use App\Http\Resources\V1\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ProfileController extends Controller
{
    public function update(UpdateRequest $request, UpdateProfile $updateProfile): CurrentUserResource
    {
        return new CurrentUserResource($updateProfile->handle($this->user($request), $request->profileData()));
    }

    public function updatePassword(UpdatePasswordRequest $request, ChangePassword $changePassword): Response
    {
        $changePassword->handle($this->user($request), $request->newPassword());

        return response()->noContent();
    }

    public function updateAvatar(UpdateAvatarRequest $request, UpdateAvatar $updateAvatar): CurrentUserResource
    {
        return new CurrentUserResource($updateAvatar->handle($this->user($request), $request->avatar()));
    }

    public function destroyAvatar(Request $request, UpdateAvatar $updateAvatar): CurrentUserResource
    {
        return new CurrentUserResource($updateAvatar->handle($this->user($request), null));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
