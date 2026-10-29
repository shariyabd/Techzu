<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetUserPassword
{
    public function __construct(private readonly PasswordBroker $broker) {}

    public function handle(string $email, string $password, string $token): void
    {
        $status = $this->broker->reset(
            ['email' => $email, 'password' => $password, 'token' => $token],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __(PasswordBroker::INVALID_TOKEN)]);
        }
    }
}
