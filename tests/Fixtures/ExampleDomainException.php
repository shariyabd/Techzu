<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Exceptions\Domain\DomainException;

final class ExampleDomainException extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'example_conflict';
    }
}
