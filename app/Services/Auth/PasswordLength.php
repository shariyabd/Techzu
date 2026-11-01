<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

final class PasswordLength
{
    public function __construct(
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    public function min(): int
    {
        return (int) $this->config->get($this->app->isProduction() ? 'auth.password_length.production_min' : 'auth.password_length.min');
    }

    public function max(): int
    {
        return (int) $this->config->get('auth.password_length.max');
    }

    /** @return array{min: int, max: int} */
    public function toArray(): array
    {
        return ['min' => $this->min(), 'max' => $this->max()];
    }
}
