<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class CannotChangeOwnRole extends DomainException
{
    public function __construct(string $message = 'You cannot change your own role.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'cannot_change_own_role';
    }
}
