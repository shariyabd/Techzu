<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Profile\ChangePassword;
use App\Actions\Profile\UpdateAvatar;
use App\Actions\Profile\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateRequest;
use App\Models\User;
use App\Services\Users\AvatarRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    public function edit(Request $request, AvatarRules $rules): Response
    {
        $user = $this->user($request);

        return Inertia::render('profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatarUrl(),
            ],
            'avatarRules' => $rules->hints(),
        ]);
    }

    public function update(UpdateRequest $request, UpdateProfile $updateProfile): RedirectResponse
    {
        $updateProfile->handle($this->user($request), $request->profileData());

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request, ChangePassword $changePassword): RedirectResponse
    {
        $changePassword->handle($this->user($request), $request->newPassword());

        return back()->with('success', 'Password changed. Your other sessions were signed out.');
    }

    public function updateAvatar(UpdateAvatarRequest $request, UpdateAvatar $updateAvatar): RedirectResponse
    {
        $updateAvatar->handle($this->user($request), $request->avatar());

        return back()->with('success', 'Profile image updated.');
    }

    public function destroyAvatar(Request $request, UpdateAvatar $updateAvatar): RedirectResponse
    {
        $updateAvatar->handle($this->user($request), null);

        return back()->with('success', 'Profile image removed.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
