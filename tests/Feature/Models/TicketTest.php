<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'));
});

it('never mass-assigns system-controlled ticket columns', function (): void {
    expect((new Ticket)->getFillable())
        ->toBe(['subject', 'description', 'priority', 'category_id', 'customer_id', 'created_by_id', 'external_reference']);
});

it('limits customers to their own tickets and lets staff see every ticket', function (string $staffState): void {
    $customer = User::factory()->customer()->create();
    $own = Ticket::factory()->create(['customer_id' => $customer->id, 'created_by_id' => $customer->id]);
    $other = Ticket::factory()->create();
    $staff = User::factory()->{$staffState}()->create();

    expect(Ticket::query()->visibleTo($customer)->pluck('id')->all())->toBe([$own->id])
        ->and(Ticket::query()->visibleTo($staff)->orderBy('id')->pluck('id')->all())->toBe([$own->id, $other->id]);
})->with(['agent', 'admin']);

it('derives the SLA state from the stored timestamps', function (Ticket $ticket, SlaState $expected): void {
    expect($ticket->slaState())->toBe($expected);
})->with([
    'within' => [fn () => Ticket::factory()->create(), SlaState::Within],
    'approaching' => [fn () => Ticket::factory()->approaching()->create(), SlaState::Approaching],
    'breached' => [fn () => Ticket::factory()->breached()->create(), SlaState::Breached],
    'paused wins over breached' => [fn () => Ticket::factory()->breached()->paused()->create(), SlaState::Paused],
    'resolved is stopped' => [fn () => Ticket::factory()->breached()->status(TicketStatus::Resolved)->create(), SlaState::Stopped],
    'closed is stopped' => [fn () => Ticket::factory()->status(TicketStatus::Closed)->create(), SlaState::Stopped],
]);

it('treats the warning and due instants as inclusive boundaries', function (): void {
    $ticket = Ticket::factory()->create();

    expect($ticket->slaState($ticket->sla_warning_at->subSecond()))->toBe(SlaState::Within)
        ->and($ticket->slaState($ticket->sla_warning_at))->toBe(SlaState::Approaching)
        ->and($ticket->slaState($ticket->sla_due_at->subSecond()))->toBe(SlaState::Approaching)
        ->and($ticket->slaState($ticket->sla_due_at))->toBe(SlaState::Breached);
});

it('hides internal-note activities from customers only', function (): void {
    $ticket = Ticket::factory()->create();
    activity('ticket')->performedOn($ticket)->event(ActivityType::Replied->value)->log('Customer replied');
    activity('ticket')->performedOn($ticket)->event(ActivityType::InternalNote->value)->log('Internal note added');
    activity('default')->performedOn($ticket)->event(ActivityType::Updated->value)->log('Unrelated log');
    $customer = User::query()->findOrFail($ticket->customer_id);
    $agent = User::factory()->agent()->create();

    expect($ticket->activitiesVisibleTo($customer)->pluck('event')->all())->toBe([ActivityType::Replied->value])
        ->and($ticket->activitiesVisibleTo($agent)->pluck('event')->sort()->values()->all())
        ->toBe([ActivityType::InternalNote->value, ActivityType::Replied->value]);
});
