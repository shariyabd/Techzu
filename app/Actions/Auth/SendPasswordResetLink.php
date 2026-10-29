<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Contracts\Auth\PasswordBroker;

final class SendPasswordResetLink
{
    public function __construct(private readonly PasswordBroker $broker) {}

    public function handle(string $email): void
    {
        $this->broker->sendResetLink(['email' => $email]);
    }
}
