<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

final class IssueApiToken
{
    private const int MINIMUM_DURATION_MICROSECONDS = 200_000;

    public function __construct(private readonly Timebox $timebox) {}

    public function handle(string $email, string $password, string $deviceName): NewAccessToken
    {
        return $this->timebox->call(function (Timebox $timebox) use ($email, $password, $deviceName): NewAccessToken {
            $user = User::query()->where('email', $email)->first();

            if ($user === null || ! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }

            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['email' => __('Verify your email address before requesting an API token.')]);
            }

            $abilities = $user->getAllPermissions()->pluck('name')->values()->all();

            $token = $user->createToken($deviceName, $abilities);

            $timebox->returnEarly();

            return $token;
        }, self::MINIMUM_DURATION_MICROSECONDS);
    }
}
