<?php

declare(strict_types=1);

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC');
    $this->travelTo($this->now);

    $this->tickets = [
        'within' => Ticket::factory()->status(TicketStatus::InProgress)->create([
            'sla_warning_at' => $this->now->addHours(2),
            'sla_due_at' => $this->now->addHours(4),
            'sla_paused_at' => null,
        ]),
        'approaching' => Ticket::factory()->status(TicketStatus::Open)->create([
            'sla_warning_at' => $this->now->subMinutes(10),
            'sla_due_at' => $this->now->addHour(),
            'sla_paused_at' => null,
        ]),
        'approaching_at_boundary' => Ticket::factory()->status(TicketStatus::Open)->create([
            'sla_warning_at' => $this->now,
            'sla_due_at' => $this->now->addHour(),
            'sla_paused_at' => null,
        ]),
        'breached' => Ticket::factory()->status(TicketStatus::InProgress)->create([
            'sla_warning_at' => $this->now->subHours(3),
            'sla_due_at' => $this->now->subMinute(),
            'sla_paused_at' => null,
        ]),
        'breached_at_boundary' => Ticket::factory()->status(TicketStatus::InProgress)->create([
            'sla_warning_at' => $this->now->subHours(3),
            'sla_due_at' => $this->now,
            'sla_paused_at' => null,
        ]),
        'paused' => Ticket::factory()->status(TicketStatus::WaitingForCustomer)->create([
            'sla_warning_at' => $this->now->subHours(3),
            'sla_due_at' => $this->now->subMinute(),
            'sla_paused_at' => $this->now->subHours(5),
        ]),
        'resolved' => Ticket::factory()->status(TicketStatus::Resolved)->create([
            'sla_warning_at' => $this->now->subHours(3),
            'sla_due_at' => $this->now->subMinute(),
            'sla_paused_at' => null,
        ]),
        'closed' => Ticket::factory()->status(TicketStatus::Closed)->create([
            'sla_warning_at' => $this->now->addHour(),
            'sla_due_at' => $this->now->addHours(2),
            'sla_paused_at' => null,
        ]),
    ];
});

dataset('sla states', [
    'within' => [SlaState::Within, ['within']],
    'approaching' => [SlaState::Approaching, ['approaching', 'approaching_at_boundary']],
    'breached' => [SlaState::Breached, ['breached', 'breached_at_boundary']],
    'paused' => [SlaState::Paused, ['paused']],
    'stopped' => [SlaState::Stopped, ['resolved', 'closed']],
]);

it('selects exactly the tickets in a given SLA state', function (SlaState $state, array $expectedKeys): void {
    $ids = SlaQuery::whereState(Ticket::query(), $state, $this->now)->pluck('id')->sort()->values()->all();

    $expected = collect($expectedKeys)->map(fn (string $k): int => $this->tickets[$k]->id)->sort()->values()->all();

    expect($ids)->toBe($expected);
})->with('sla states');

it('agrees with Ticket::slaState() for every ticket', function (): void {
    foreach (SlaState::cases() as $state) {
        $ids = SlaQuery::whereState(Ticket::query(), $state, $this->now)->pluck('id')->all();

        foreach ($this->tickets as $key => $ticket) {
            $matches = in_array($ticket->id, $ids, true);
            expect($matches)->toBe(
                $ticket->fresh()?->slaState($this->now) === $state,
                "ticket [{$key}] disagrees for state [{$state->value}]",
            );
        }
    }
});
