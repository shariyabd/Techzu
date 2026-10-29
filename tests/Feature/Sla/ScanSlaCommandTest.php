<?php

declare(strict_types=1);

use App\Console\Commands\ScanSlaCommand;
use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function scanNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
}

/** @param array<string, mixed> $attributes */
function scanTicket(array $attributes = []): Ticket
{
    return Ticket::factory()->create(array_merge([
        'status' => TicketStatus::InProgress,
        'sla_paused_at' => null,
        'escalation_level' => EscalationLevel::None,
        'escalated_at' => null,
    ], $attributes));
}

beforeEach(function (): void {
    Event::fake([TicketSlaApproaching::class, TicketSlaBreached::class]);
    $this->travelTo(scanNow());
});

it('escalates at the SLA thresholds', function (int $warningOffset, int $dueOffset, EscalationLevel $initial, EscalationLevel $expected, int $approaching, int $breached): void {
    $ticket = scanTicket([
        'sla_warning_at' => scanNow()->addSeconds($warningOffset),
        'sla_due_at' => scanNow()->addSeconds($dueOffset),
        'escalation_level' => $initial,
    ]);

    $this->artisan('sla:scan')->assertSuccessful();

    $fresh = $ticket->fresh();
    expect($fresh->escalation_level)->toBe($expected);
    Event::assertDispatchedTimes(TicketSlaApproaching::class, $approaching);
    Event::assertDispatchedTimes(TicketSlaBreached::class, $breached);
    expect($fresh->escalated_at?->equalTo(scanNow()) ?? false)->toBe($breached === 1);
})->with([
    'warning reached exactly' => [0, 1800, EscalationLevel::None, EscalationLevel::Approaching, 1, 0],
    'inside the warning window' => [-600, 1200, EscalationLevel::None, EscalationLevel::Approaching, 1, 0],
    'warning one second ahead' => [1, 1801, EscalationLevel::None, EscalationLevel::None, 0, 0],
    'due reached exactly' => [-1800, 0, EscalationLevel::Approaching, EscalationLevel::Breached, 0, 1],
    'past due, never escalated: only Breached fires' => [-7200, -3600, EscalationLevel::None, EscalationLevel::Breached, 0, 1],
    'past due, already approaching' => [-7200, -3600, EscalationLevel::Approaching, EscalationLevel::Breached, 0, 1],
    'already breached' => [-7200, -3600, EscalationLevel::Breached, EscalationLevel::Breached, 0, 0],
    'already approaching, before due' => [-600, 1200, EscalationLevel::Approaching, EscalationLevel::Approaching, 0, 0],
]);

it('dispatches each escalation once across repeated runs and reports the counts', function (): void {
    scanTicket(['sla_warning_at' => scanNow()->subMinutes(10), 'sla_due_at' => scanNow()->addMinutes(20)]);
    scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);

    $this->artisan('sla:scan')->expectsOutputToContain('1 approaching, 1 breached')->assertSuccessful();
    $this->artisan('sla:scan')->expectsOutputToContain('0 approaching, 0 breached')->assertSuccessful();

    Event::assertDispatchedTimes(TicketSlaApproaching::class, 1);
    Event::assertDispatchedTimes(TicketSlaBreached::class, 1);
});

it('escalates again after the deadline moved later and the level was lowered', function (): void {
    $ticket = scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);
    $this->artisan('sla:scan')->assertSuccessful();

    DB::table('tickets')->where('id', $ticket->id)->update([
        'escalation_level' => EscalationLevel::None->value,
        'sla_warning_at' => scanNow()->addMinutes(30),
        'sla_due_at' => scanNow()->addMinutes(40),
    ]);
    $this->travelTo(scanNow()->addMinutes(41));
    $this->artisan('sla:scan')->assertSuccessful();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::Breached);
    Event::assertDispatchedTimes(TicketSlaBreached::class, 2);
});

it('never escalates paused or finished tickets', function (TicketStatus $status, bool $paused): void {
    $ticket = scanTicket([
        'status' => $status,
        'sla_paused_at' => $paused ? scanNow()->subHours(3) : null,
        'sla_warning_at' => scanNow()->subHours(2),
        'sla_due_at' => scanNow()->subHour(),
    ]);

    $this->artisan('sla:scan')->assertSuccessful();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::None);
    Event::assertNothingDispatched();
})->with([
    'paused' => [TicketStatus::WaitingForCustomer, true],
    'resolved' => [TicketStatus::Resolved, false],
    'closed' => [TicketStatus::Closed, false],
]);

it('re-checks under the row lock, so a ticket paused mid-scan is not escalated', function (): void {
    $ticket = scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);
    $chunkQueries = 0;
    DB::listen(function (QueryExecuted $query) use ($ticket, &$chunkQueries): void {
        if (! str_contains($query->sql, 'limit '.ScanSlaCommand::CHUNK_SIZE)) {
            return;
        }
        if (++$chunkQueries === 2) {
            DB::table('tickets')->where('id', $ticket->id)->update([
                'status' => TicketStatus::WaitingForCustomer->value,
                'sla_paused_at' => scanNow(),
            ]);
        }
    });

    $this->artisan('sla:scan')->assertSuccessful();

    expect($chunkQueries)->toBe(2)
        ->and($ticket->fresh()->escalation_level)->toBe(EscalationLevel::None);
    Event::assertNotDispatched(TicketSlaBreached::class);
});

it('escalates every ticket when there is more than one chunk', function (): void {
    $count = ScanSlaCommand::CHUNK_SIZE + 1;
    Ticket::factory()
        ->count($count)
        ->recycle(User::factory()->customer()->create())
        ->recycle(Category::factory()->create())
        ->create([
            'status' => TicketStatus::Open,
            'sla_warning_at' => scanNow()->subHours(2),
            'sla_due_at' => scanNow()->subHour(),
        ]);

    $this->artisan('sla:scan')->assertSuccessful();

    expect(Ticket::query()->where('escalation_level', EscalationLevel::Breached->value)->count())->toBe($count);
    Event::assertDispatchedTimes(TicketSlaBreached::class, $count);
});
