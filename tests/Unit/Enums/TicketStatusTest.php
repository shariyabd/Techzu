<?php

declare(strict_types=1);

use App\Enums\TicketStatus;

dataset('every status pair', function (): iterable {
    $allowed = [
        TicketStatus::Open->value => [TicketStatus::InProgress, TicketStatus::WaitingForCustomer, TicketStatus::Resolved],
        TicketStatus::InProgress->value => [TicketStatus::WaitingForCustomer, TicketStatus::Resolved],
        TicketStatus::WaitingForCustomer->value => [TicketStatus::InProgress, TicketStatus::Resolved],
        TicketStatus::Resolved->value => [TicketStatus::Closed, TicketStatus::InProgress],
        TicketStatus::Closed->value => [],
    ];

    foreach (TicketStatus::cases() as $from) {
        foreach (TicketStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to, in_array($to, $allowed[$from->value], true)];
        }
    }
});

it('allows exactly the TD-040 transitions', function (TicketStatus $from, TicketStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed)
        ->and(in_array($to, $from->allowedTransitions(), true))->toBe($allowed);
})->with('every status pair');

it('splits statuses into active and finished', function (): void {
    expect(TicketStatus::active())->toBe([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::WaitingForCustomer])
        ->and(TicketStatus::finished())->toBe([TicketStatus::Resolved, TicketStatus::Closed]);
});
