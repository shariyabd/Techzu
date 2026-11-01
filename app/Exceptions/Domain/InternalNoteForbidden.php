<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InternalNoteForbidden extends DomainException
{
    public function __construct(string $message = 'You are not allowed to add internal notes to this ticket.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'internal_note_forbidden';
    }
}
