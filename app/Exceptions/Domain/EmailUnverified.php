<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class EmailUnverified extends DomainException
{
    public function __construct(string $message = 'Verify your email address before requesting an API token.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'email_unverified';
    }
}
