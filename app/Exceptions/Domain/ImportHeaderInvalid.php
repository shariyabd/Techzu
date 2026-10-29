<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class ImportHeaderInvalid extends DomainException
{
    public function __construct(string $message = 'The CSV header does not match the expected columns.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'import_header_invalid';
    }
}
