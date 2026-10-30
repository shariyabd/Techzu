<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class CustomerInternalNotesForbidden extends DomainException
{
    public function __construct(string $message = 'Customers can never receive permission for internal notes.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'customer_internal_notes_forbidden';
    }
}
