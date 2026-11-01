<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateSlaSettings;
use App\Actions\Tickets\RecalculateTicketSla;
use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketDataVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
});

/** @return array{resolution_minutes: array<string, int>, warning_threshold_percent: int, timezone: string, week: array<string, list<array{start: string, end: string}>>} */
function shorterMediumTarget(): array
{
    return [
        'resolution_minutes' => ['low' => 2880, 'medium' => 60, 'high' => 480, 'critical' => 120],
        'warning_threshold_percent' => 75,
        'timezone' => 'Asia/Dhaka',
        'week' => ['thursday' => [['start' => '09:00', 'end' => '18:00']]],
    ];
}

it('recalculates only active tickets, each under a row lock, when the box is ticked', function (): void {
    $active = collect([TicketStatus::Open, TicketStatus::InProgress])
        ->map(fn (TicketStatus $status): Ticket => Ticket::factory()->priority(TicketPriority::Medium)->status($status)->create(['sla_started_at' => now()]));
    $finished = collect([TicketStatus::Resolved, TicketStatus::Closed])
        ->map(fn (TicketStatus $status): Ticket => Ticket::factory()->priority(TicketPriority::Medium)->status($status)->create(['sla_started_at' => now()]));
    $finishedDue = $finished->map(fn (Ticket $ticket): string => $ticket->sla_due_at->toDateTimeString())->all();
    $lockedTicketReads = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$lockedTicketReads): void {
        if (str_contains($query->sql, 'from `tickets`') && str_contains($query->sql, 'for update')) {
            $lockedTicketReads++;
        }
    });

    app(UpdateSlaSettings::class)->handle(shorterMediumTarget(), true);

    foreach ($active as $ticket) {
        $ticket->refresh();
        expect($ticket->sla_due_at->toDateTimeString())->toBe('2026-10-29 07:00:00')
            ->and($ticket->sla_warning_at->toDateTimeString())->toBe('2026-10-29 06:45:00');
    }
    expect($finished->map(fn (Ticket $ticket): string => $ticket->refresh()->sla_due_at->toDateTimeString())->all())->toBe($finishedDue)
        ->and($lockedTicketReads)->toBe(2);
});

it('leaves stored deadlines alone when the box is not ticked', function (): void {
    $ticket = Ticket::factory()->priority(TicketPriority::Medium)->status(TicketStatus::Open)->create(['sla_started_at' => now()]);
    $due = $ticket->sla_due_at->toDateTimeString();

    app(UpdateSlaSettings::class)->handle(shorterMediumTarget());

    expect($ticket->refresh()->sla_due_at->toDateTimeString())->toBe($due);
});

it('logs a customer-visible deadline update and rotates the ticket data version only when the deadline moves', function (): void {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->priority(TicketPriority::Medium)->status(TicketStatus::InProgress)->create([
        'customer_id' => $customer->id,
        'created_by_id' => $customer->id,
        'sla_started_at' => now(),
    ]);
    $previousDue = $ticket->sla_due_at;
    app(UpdateSlaSettings::class)->handle(shorterMediumTarget());
    $version = app(TicketDataVersion::class);
    $before = $version->current();

    app(RecalculateTicketSla::class)->handle($ticket->id, CarbonImmutable::now());
    $afterChange = $version->current();
    app(RecalculateTicketSla::class)->handle($ticket->id, CarbonImmutable::now());

    $logged = $ticket->activitiesVisibleTo($customer)->where('event', ActivityType::SlaRecalculated->value)->sole();
    expect($logged->description)->toBe('Deadline updated')
        ->and($logged->causer_id)->toBeNull()
        ->and(CarbonImmutable::parse($logged->properties['previous_due_at'])->equalTo($previousDue))->toBeTrue()
        ->and(CarbonImmutable::parse($logged->properties['due_at'])->toDateTimeString())->toBe('2026-10-29 07:00:00')
        ->and($afterChange)->not->toBe($before)
        ->and($version->current())->toBe($afterChange);
});
