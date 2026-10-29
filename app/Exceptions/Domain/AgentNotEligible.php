<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class AgentNotEligible extends DomainException
{
    public function __construct(string $message = 'The selected user is not an available agent for this ticket.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'agent_not_eligible';
    }
}
