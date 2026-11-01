<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Auth\Passwords\PasswordBroker as ConcretePasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use LogicException;

final class PasswordResetLinks
{
    public function __construct(private readonly PasswordBroker $broker) {}

    public function isUsable(string $email, string $token): bool
    {
        if ($email === '' || $token === '') {
            return false;
        }

        if (! $this->broker instanceof ConcretePasswordBroker) {
            throw new LogicException('Password reset links need the token-backed password broker.');
        }

        $user = $this->broker->getUser(['email' => $email]);

        return $user instanceof CanResetPassword && $this->broker->tokenExists($user, $token);
    }
}
