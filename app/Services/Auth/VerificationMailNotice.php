<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

final class VerificationMailNotice
{
    public function __construct(
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    public function mailIsLogged(): bool
    {
        return $this->app->environment('local') && $this->config->get('mail.default') === 'log';
    }

    public function linkSent(string $email): string
    {
        $message = "We sent a verification link to {$email}.";

        if ($this->mailIsLogged()) {
            $message .= " Running locally? Mail isn't really sent — open storage/logs/laravel.log and copy the link.";
        }

        return $message;
    }
}
