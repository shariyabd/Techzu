<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InvalidSlaSettings extends DomainException
{
    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'invalid_sla_settings';
    }
}
