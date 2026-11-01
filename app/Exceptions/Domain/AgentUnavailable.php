<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class AgentUnavailable extends DomainException
{
    public function __construct(string $message = 'You are marked as unavailable, so you cannot claim tickets.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'agent_unavailable';
    }
}
