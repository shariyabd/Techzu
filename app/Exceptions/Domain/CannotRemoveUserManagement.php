<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class CannotRemoveUserManagement extends DomainException
{
    public function __construct(string $message = 'The admin role must keep permission for user management.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'cannot_remove_user_management';
    }
}
