<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Exceptions\Domain\AgentNotEligible;
use App\Exceptions\Domain\DomainException;
use App\Exceptions\Domain\ImportHeaderInvalid;
use App\Exceptions\Domain\InvalidStatusTransition;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Exceptions\Domain\TicketClosed;

it('carries a stable status and code', function (DomainException $exception, int $status, string $code, string $message): void {
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->status())->toBe($status)
        ->and($exception->errorCode())->toBe($code)
        ->and($exception->getMessage())->toBe($message);
})->with([
    'invalid transition' => [
        fn () => InvalidStatusTransition::between(TicketStatus::Closed, TicketStatus::Open),
        422, 'invalid_status_transition', 'A ticket cannot move from Closed to Open.',
    ],
    'already claimed' => [
        fn () => TicketAlreadyClaimed::for('TKT-2026-000042'),
        409, 'ticket_already_claimed', 'Ticket TKT-2026-000042 has already been claimed by another agent.',
    ],
    'agent not eligible (default message)' => [
        fn () => new AgentNotEligible,
        422, 'agent_not_eligible', 'The selected user is not an available agent for this ticket.',
    ],
    'ticket closed (default message)' => [
        fn () => new TicketClosed,
        422, 'ticket_closed', 'This ticket is closed and no longer accepts changes.',
    ],
    'import header invalid (custom message)' => [
        fn () => new ImportHeaderInvalid('Missing columns: subject, priority.'),
        422, 'import_header_invalid', 'Missing columns: subject, priority.',
    ],
]);
