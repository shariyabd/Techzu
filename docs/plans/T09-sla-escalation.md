# T09: SLA escalation scan (`sla:scan`)

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Work test-first. Read `CLAUDE.md`, the spec (§7, §8), TD-044 and README §3.4, §3.6–§3.8 before starting.

## 1. Header

- **Objective:** Add the scheduled, idempotent `sla:scan` command. Every minute it moves active, unpaused tickets to escalation level 1 (approaching) or 2 (breached), and dispatches `TicketSlaApproaching` / `TicketSlaBreached` exactly once for exactly the tickets it changed.
- **Depends on:** T05 (`SlaQuery`, the events, `RecordTicketActivity`). T02 (`sla_*` columns are maintained). T01 (`EscalationLevel`, `Ticket`, `TicketFactory` states).
- **Wave:** 4a (parallel with T06, T07, T08).
- **TDs implemented:** TD-044 (scheduled idempotent scan), TD-023 (state derived from stored timestamps), TD-021 (scan queries use `(status, sla_warning_at)` and `(status, sla_due_at)`), TD-101 (`chunkById`, no unbounded `get()`), TD-053 (short transactions, no I/O inside), TD-070 (side effects through events; notifications are T10's listeners), TD-026 (activity written by `RecordTicketActivity`).

### Design: exact, idempotent escalation

For each level `N` (1 = approaching, then 2 = breached), with `runAt` = now in UTC, truncated to the second:

1. **Stream candidates.** `Ticket::query()->where('escalation_level', '<', N)` plus `SlaQuery::whereApproaching($q, $runAt)` (active, not paused, `warning ≤ runAt < due`) or `SlaQuery::whereBreached($q, $runAt)` (active, not paused, `due ≤ runAt`). Select only `id`, `chunkById(500)`. Using `chunkById` instead of `chunk` matters: the loop changes the rows that the `WHERE` filters on, and keyset paging (`id > last`) never skips rows when that happens.
2. **Lock and re-check, per chunk, in one short transaction.** Re-run the *same* predicate, restricted to the chunk's ids, with `FOR UPDATE`. InnoDB locking reads see the latest *committed* row, not the transaction snapshot. So a row that another run escalated, or that an agent paused or resolved since step 1, drops out here. A concurrent run that wants the same rows blocks until we commit, then sees `escalation_level = N` and skips them.
3. **Conditional UPDATE.** `UPDATE tickets SET escalation_level = N [, escalated_at = :runAt] WHERE id IN (:locked) AND escalation_level < N`. The rows are locked, so this changes exactly the locked set; the `escalation_level < N` guard is there as defence in depth (TD-044's wording). It goes through the query builder (`toBase()`), so `updated_at` isn't bumped: escalation is system bookkeeping that the activity log records, not a user edit.
4. **Dispatch after commit, only for the locked ids.** Reload those tickets (at most 500, with `assignedAgent` eager-loaded for the listeners) and dispatch one event per ticket.

**Why not mark rows with `escalated_at = :runAt` and re-select them?** `escalated_at` has one-second precision. Two runs in the same second (a manual `sla:scan` during the scheduled one, or `onOneServer` misconfigured) would both re-select rows marked by the first, and dispatch twice. The lock-and-re-check approach is exact regardless of timing, and it lets `escalated_at` keep its spec meaning ("set when breached", spec §8).

**Level 0 → 2 dispatches only `TicketSlaBreached`.** The two predicates are disjoint (`due > now` vs `due ≤ now`), so a ticket whose deadline has already passed is never an approaching candidate. Sending "approaching" for a ticket that has already breached would be noise. The breach notification already reaches a superset of the recipients (agent + admins vs agent or admins, spec §11).

**Delivery is at most once.** If the process dies between commit and dispatch, the level has moved and that event is lost. TD-044 chose "never duplicate" over "always deliver". The ticket still shows correctly as approaching or breached everywhere, because state is derived from timestamps (TD-023).

**Notifications are T10's job.** This task dispatches events only. `RecordTicketActivity` (T05) writes the `sla_approaching` / `escalated` history entries, and T10's `SendSlaNotifications` sends the mail and database notifications.

## 2. Files

**Create**
- `app/Console/Commands/ScanSlaCommand.php` (auto-discovered from `app/Console/Commands`)
- `tests/Feature/Sla/ScanSlaCommandTest.php`
- `tests/Feature/Sla/ScanSlaActivityTest.php`
- `tests/Feature/Sla/ScanSlaScheduleTest.php`

**Modify**
- `routes/console.php`: the single schedule line (README §3.8, §4).

**Must not touch**
- `app/Services/Sla/*` (T02, T05), `app/Models/*`, `database/**`, `app/Enums/*` (T01).
- `app/Events/*`, `app/Listeners/*` (T05, T10), `app/Notifications/*` (T10).
- `bootstrap/app.php`, `bootstrap/providers.php`, `tests/Pest.php`.

## 3. Interfaces

### Consumes
- `App\Services\Sla\SlaQuery::whereApproaching(Builder $q, CarbonImmutable $now): Builder` and `::whereBreached(Builder $q, CarbonImmutable $now): Builder`. Both restrict to `TicketStatus::active()` and `sla_paused_at IS NULL`. T05.
- `App\Events\TicketSlaApproaching(Ticket $ticket)`, `App\Events\TicketSlaBreached(Ticket $ticket)`: `Dispatchable`, `ShouldDispatchAfterCommit`. T05.
- `App\Listeners\RecordTicketActivity`: maps `TicketSlaApproaching` → `activity('ticket')->event(ActivityType::SlaApproaching->value)` and `TicketSlaBreached` → `ActivityType::Escalated`, with a null causer (system). T05.
- `App\Enums\EscalationLevel` (`None = 0`, `Approaching = 1`, `Breached = 2`), `App\Enums\TicketStatus`, `App\Enums\ActivityType`. T01.
- `App\Models\Ticket` (`escalation_level`, `escalated_at`, `sla_*`, `assignedAgent()`, `activities()`). `TicketFactory` states `approaching()`, `breached()`, `paused()`, `status(TicketStatus)`. `CategoryFactory`, `UserFactory::customer()`. T01.

### Produces
- `App\Console\Commands\ScanSlaCommand`: signature `sla:scan` (README §3.8). Returns `0`. Prints `SLA scan at <ISO-8601>: <n> approaching, <m> breached.`
- `routes/console.php`: `Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();` (README §3.8, exact).
- Behaviour other tasks rely on:
  - `TicketSlaApproaching($ticket)` fires once per ticket when its level goes 0 → 1.
  - `TicketSlaBreached($ticket)` fires once per ticket when its level goes 0 → 2 or 1 → 2. Only Breached fires for a 0 → 2 jump.
  - The event's `$ticket` is fresh: it has the new `escalation_level`, and `assignedAgent` is eager-loaded.
  - Paused (`sla_paused_at` set) and finished (`Resolved`, `Closed`) tickets are never escalated.
  - `escalated_at = runAt` is set on breach only. `updated_at` isn't changed.
  - When `SlaCalculator` lowers a level (deadline moved later, T02), a later scan escalates the ticket again.

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| 9.1 Escalation command | `app/Console/Commands/ScanSlaCommand.php`, `tests/Feature/Sla/ScanSlaCommandTest.php`, `tests/Feature/Sla/ScanSlaActivityTest.php` | — | 9.2 |
| 9.2 Schedule registration | `routes/console.php`, `tests/Feature/Sla/ScanSlaScheduleTest.php` | — | 9.1 |

Mini-waves: **A** = 9.1 ∥ 9.2. The schedule line names the command by its signature string, so 9.2 doesn't need the class.

## 5. Subtasks

### 9.1 Escalation command (TD-044)

- [ ] **Step 1: Write the failing command test.** Create `tests/Feature/Sla/ScanSlaCommandTest.php`:

```php
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

/**
 * An active, unpaused, never-escalated ticket; callers override the SLA timestamps.
 *
 * @param  array<string, mixed>  $attributes
 */
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

it('escalates at the right thresholds', function (
    int $warningOffset,
    int $dueOffset,
    EscalationLevel $initial,
    EscalationLevel $expected,
    int $approachingEvents,
    int $breachedEvents,
): void {
    $ticket = scanTicket([
        'sla_warning_at' => scanNow()->addSeconds($warningOffset),
        'sla_due_at' => scanNow()->addSeconds($dueOffset),
        'escalation_level' => $initial,
    ]);

    $this->artisan('sla:scan')->assertSuccessful();

    $fresh = $ticket->fresh();
    expect($fresh->escalation_level)->toBe($expected);
    Event::assertDispatchedTimes(TicketSlaApproaching::class, $approachingEvents);
    Event::assertDispatchedTimes(TicketSlaBreached::class, $breachedEvents);

    if ($breachedEvents === 1) {
        expect($fresh->escalated_at?->equalTo(scanNow()))->toBeTrue();
    } else {
        expect($fresh->escalated_at)->toBeNull();
    }
})->with([
    'warning reached exactly' => [0, 1800, EscalationLevel::None, EscalationLevel::Approaching, 1, 0],
    'inside the warning window' => [-600, 1200, EscalationLevel::None, EscalationLevel::Approaching, 1, 0],
    'warning one second ahead' => [1, 1801, EscalationLevel::None, EscalationLevel::None, 0, 0],
    'due reached exactly' => [-1800, 0, EscalationLevel::Approaching, EscalationLevel::Breached, 0, 1],
    'past due, never escalated: 0 -> 2 sends only Breached' => [-7200, -3600, EscalationLevel::None, EscalationLevel::Breached, 0, 1],
    'past due, already approaching: 1 -> 2' => [-7200, -3600, EscalationLevel::Approaching, EscalationLevel::Breached, 0, 1],
    'already breached' => [-7200, -3600, EscalationLevel::Breached, EscalationLevel::Breached, 0, 0],
    'already approaching, still before due' => [-600, 1200, EscalationLevel::Approaching, EscalationLevel::Approaching, 0, 0],
]);

it('is idempotent: a second run dispatches nothing new', function (): void {
    scanTicket(['sla_warning_at' => scanNow()->subMinutes(10), 'sla_due_at' => scanNow()->addMinutes(20)]);
    scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);

    $this->artisan('sla:scan')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();

    Event::assertDispatchedTimes(TicketSlaApproaching::class, 1);
    Event::assertDispatchedTimes(TicketSlaBreached::class, 1);
});

it('moves an approaching ticket on to breached once its deadline passes', function (): void {
    $ticket = scanTicket(['sla_warning_at' => scanNow()->subMinutes(10), 'sla_due_at' => scanNow()->addMinutes(20)]);

    $this->artisan('sla:scan')->assertSuccessful();
    $this->travelTo(scanNow()->addMinutes(20));
    $this->artisan('sla:scan')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::Breached);
    Event::assertDispatchedTimes(TicketSlaApproaching::class, 1);
    Event::assertDispatchedTimes(TicketSlaBreached::class, 1);
});

it('escalates again after the calculator lowered the level', function (): void {
    $ticket = scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);
    $this->artisan('sla:scan')->assertSuccessful();

    // What SlaCalculator::resume()/recalculateForPriority() leave behind when the deadline moves later (T02).
    DB::table('tickets')->where('id', $ticket->id)->update([
        'escalation_level' => EscalationLevel::None->value,
        'sla_warning_at' => scanNow()->addMinutes(30),
        'sla_due_at' => scanNow()->addMinutes(40),
    ]);
    $this->travelTo(scanNow()->addMinutes(41));
    $this->artisan('sla:scan')->assertSuccessful();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::Breached);
    Event::assertDispatchedTimes(TicketSlaBreached::class, 2);
    Event::assertDispatchedTimes(TicketSlaApproaching::class, 0);
});

it('ignores paused and finished tickets', function (string $state): void {
    $timestamps = [
        'sla_warning_at' => scanNow()->subHours(2),
        'sla_due_at' => scanNow()->subHour(),
        'escalation_level' => EscalationLevel::None,
    ];
    $ticket = match ($state) {
        'paused' => Ticket::factory()->paused()->create(array_merge($timestamps, ['sla_paused_at' => scanNow()->subHours(3)])),
        'resolved' => Ticket::factory()->status(TicketStatus::Resolved)->create(array_merge($timestamps, ['sla_paused_at' => null])),
        'closed' => Ticket::factory()->status(TicketStatus::Closed)->create(array_merge($timestamps, ['sla_paused_at' => null])),
    };

    $this->artisan('sla:scan')->assertSuccessful();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::None);
    Event::assertNotDispatched(TicketSlaApproaching::class);
    Event::assertNotDispatched(TicketSlaBreached::class);
})->with(['paused', 'resolved', 'closed']);

it('dispatches fresh tickets with the assigned agent eager-loaded', function (): void {
    $agent = User::factory()->agent()->create();
    $ticket = scanTicket([
        'assigned_agent_id' => $agent->id,
        'sla_warning_at' => scanNow()->subHours(2),
        'sla_due_at' => scanNow()->subHour(),
    ]);

    $this->artisan('sla:scan')->assertSuccessful();

    Event::assertDispatched(TicketSlaBreached::class, fn (TicketSlaBreached $event): bool => $event->ticket->is($ticket)
        && $event->ticket->escalation_level === EscalationLevel::Breached
        && $event->ticket->relationLoaded('assignedAgent')
        && $event->ticket->assignedAgent?->is($agent) === true);
});

it('re-checks the threshold under the row lock, so a ticket paused mid-scan is not escalated', function (): void {
    $ticket = scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);
    $chunkQueries = 0;

    // The approaching pass runs the first "limit 500" chunk query and the breached pass the second.
    // Right after the breached pass has picked its candidates, an agent pauses the ticket.
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

it('processes more than one chunk', function (): void {
    $count = ScanSlaCommand::CHUNK_SIZE + 1;
    Ticket::factory()
        ->count($count)
        ->recycle(User::factory()->customer()->create())
        ->recycle(Category::factory()->create())
        ->create([
            'status' => TicketStatus::Open,
            'assigned_agent_id' => null,
            'sla_paused_at' => null,
            'escalation_level' => EscalationLevel::None,
            'escalated_at' => null,
            'sla_warning_at' => scanNow()->subHours(2),
            'sla_due_at' => scanNow()->subHour(),
        ]);

    $this->artisan('sla:scan')->assertSuccessful();

    expect(Ticket::query()->where('escalation_level', EscalationLevel::Breached->value)->count())->toBe($count);
    Event::assertDispatchedTimes(TicketSlaBreached::class, $count);
    expect(Event::dispatched(TicketSlaBreached::class)->map(fn (array $args): int => $args[0]->ticket->id)->unique())
        ->toHaveCount($count);
});

it('reports what it escalated', function (): void {
    scanTicket(['sla_warning_at' => scanNow()->subMinutes(10), 'sla_due_at' => scanNow()->addMinutes(20)]);
    scanTicket(['sla_warning_at' => scanNow()->subHours(2), 'sla_due_at' => scanNow()->subHour()]);

    $this->artisan('sla:scan')
        ->expectsOutputToContain('1 approaching, 1 breached')
        ->assertExitCode(0);
});

it('does not touch updated_at', function (): void {
    $ticket = scanTicket([
        'sla_warning_at' => scanNow()->subHours(2),
        'sla_due_at' => scanNow()->subHour(),
        'updated_at' => scanNow()->subDay(),
    ]);

    $this->artisan('sla:scan')->assertSuccessful();

    expect($ticket->fresh()->updated_at->equalTo(scanNow()->subDay()))->toBeTrue();
});
```

- [ ] **Step 2: Write the failing activity integration test.** Create `tests/Feature/Sla/ScanSlaActivityTest.php`. It runs the real `RecordTicketActivity` listener (no `Event::fake`) and fakes only notifications, so T10's listeners can't send anything once they are merged.

```php
<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

function scanActivityNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
}

/** @return Collection<int, Activity> */
function scanActivities(Ticket $ticket, ActivityType $type): Collection
{
    return $ticket->activities()->where('log_name', 'ticket')->where('event', $type->value)->get();
}

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(scanActivityNow());
});

it('records one sla_approaching and one escalated entry, caused by the system', function (): void {
    $base = ['status' => TicketStatus::InProgress, 'sla_paused_at' => null, 'escalation_level' => EscalationLevel::None, 'escalated_at' => null];
    $approaching = Ticket::factory()->create(array_merge($base, [
        'sla_warning_at' => scanActivityNow()->subMinutes(10),
        'sla_due_at' => scanActivityNow()->addMinutes(20),
    ]));
    $breached = Ticket::factory()->create(array_merge($base, [
        'sla_warning_at' => scanActivityNow()->subHours(2),
        'sla_due_at' => scanActivityNow()->subHour(),
    ]));

    $this->artisan('sla:scan')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();

    $approachingEntries = scanActivities($approaching, ActivityType::SlaApproaching);
    $escalatedEntries = scanActivities($breached, ActivityType::Escalated);

    expect($approachingEntries)->toHaveCount(1)
        ->and($approachingEntries->first()->causer_id)->toBeNull()
        ->and(scanActivities($approaching, ActivityType::Escalated))->toHaveCount(0)
        ->and($escalatedEntries)->toHaveCount(1)
        ->and($escalatedEntries->first()->causer_id)->toBeNull()
        ->and(scanActivities($breached, ActivityType::SlaApproaching))->toHaveCount(0);
});

it('adds the escalated entry when an approaching ticket later breaches', function (): void {
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'sla_paused_at' => null,
        'escalation_level' => EscalationLevel::None,
        'sla_warning_at' => scanActivityNow()->subMinutes(10),
        'sla_due_at' => scanActivityNow()->addMinutes(20),
    ]);

    $this->artisan('sla:scan')->assertSuccessful();
    $this->travelTo(scanActivityNow()->addMinutes(25));
    $this->artisan('sla:scan')->assertSuccessful();

    expect(scanActivities($ticket, ActivityType::SlaApproaching))->toHaveCount(1)
        ->and(scanActivities($ticket, ActivityType::Escalated))->toHaveCount(1);
});
```

- [ ] **Step 3: Run both tests and confirm they fail.**
  Command: `./vendor/bin/pest tests/Feature/Sla/ScanSlaCommandTest.php tests/Feature/Sla/ScanSlaActivityTest.php`
  Expected: FAIL. Every test that runs the command fails with `Symfony\Component\Console\Exception\CommandNotFoundException: The command "sla:scan" does not exist.` The two tests that reference `ScanSlaCommand::CHUNK_SIZE` fail with `Error: Class "App\Console\Commands\ScanSlaCommand" not found`.

- [ ] **Step 4: Implement.** Create `app/Console/Commands/ScanSlaCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EscalationLevel;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Ticket;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Escalates active, unpaused tickets (TD-044). Idempotent at the database level: each chunk is
 * re-checked under FOR UPDATE and changed with a conditional UPDATE, and events are dispatched
 * after commit for exactly the rows this run changed. Overlapping runs, retries or a second server
 * therefore never send a duplicate escalation.
 */
final class ScanSlaCommand extends Command
{
    /** Tickets locked and escalated per transaction; bounds memory and lock time (TD-101). */
    public const CHUNK_SIZE = 500;

    /** @var string */
    protected $signature = 'sla:scan';

    /** @var string */
    protected $description = 'Escalate active tickets whose SLA is approaching or breached (TD-044).';

    public function handle(): int
    {
        $runAt = now()->toImmutable()->utc()->startOfSecond();

        $approaching = $this->escalate(EscalationLevel::Approaching, $runAt);
        $breached = $this->escalate(EscalationLevel::Breached, $runAt);

        $this->components->info(sprintf(
            'SLA scan at %s: %d approaching, %d breached.',
            $runAt->toIso8601String(),
            $approaching,
            $breached,
        ));

        return self::SUCCESS;
    }

    private function escalate(EscalationLevel $level, CarbonImmutable $runAt): int
    {
        $escalated = 0;

        /** @param Collection<int, Ticket> $chunk */
        $escalateChunk = function (Collection $chunk) use ($level, $runAt, &$escalated): void {
            $ids = $this->claim($level, $runAt, $chunk->modelKeys());
            $this->dispatchFor($level, $ids);
            $escalated += count($ids);
        };

        $this->candidates($level, $runAt)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, $escalateChunk);

        return $escalated;
    }

    /**
     * Active, unpaused tickets below $level that have crossed its threshold. The two thresholds are
     * disjoint (due > now vs due <= now), so a ticket already past due skips "approaching" entirely.
     *
     * @return Builder<Ticket>
     */
    private function candidates(EscalationLevel $level, CarbonImmutable $runAt): Builder
    {
        $query = Ticket::query()->where('escalation_level', '<', $level->value);

        return match ($level) {
            EscalationLevel::Approaching => SlaQuery::whereApproaching($query, $runAt),
            EscalationLevel::Breached => SlaQuery::whereBreached($query, $runAt),
            EscalationLevel::None => throw new LogicException('Tickets are never escalated to level None.'),
        };
    }

    /**
     * Locks the chunk's rows that still qualify (locking reads see the latest committed data, so rows
     * escalated, paused or resolved meanwhile drop out), then raises their level.
     *
     * @param  array<int, mixed>  $candidateIds
     * @return list<int> the ids this run actually escalated
     */
    private function claim(EscalationLevel $level, CarbonImmutable $runAt, array $candidateIds): array
    {
        return DB::transaction(function () use ($level, $runAt, $candidateIds): array {
            /** @var list<int> $ids */
            $ids = $this->candidates($level, $runAt)
                ->whereKey($candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            if ($ids === []) {
                return [];
            }

            $values = ['escalation_level' => $level->value];

            if ($level === EscalationLevel::Breached) {
                $values['escalated_at'] = $runAt;
            }

            // Query builder on purpose: escalation is bookkeeping, so updated_at stays untouched.
            Ticket::query()
                ->whereKey($ids)
                ->where('escalation_level', '<', $level->value)
                ->toBase()
                ->update($values);

            return $ids;
        }, 3);
    }

    /** @param list<int> $ids */
    private function dispatchFor(EscalationLevel $level, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Bounded by CHUNK_SIZE. assignedAgent is eager-loaded for the notification listeners (T10).
        Ticket::query()
            ->with('assignedAgent')
            ->whereKey($ids)
            ->orderBy('id')
            ->get()
            ->each(static function (Ticket $ticket) use ($level): void {
                if ($level === EscalationLevel::Breached) {
                    TicketSlaBreached::dispatch($ticket);

                    return;
                }

                TicketSlaApproaching::dispatch($ticket);
            });
    }
}
```

- [ ] **Step 5: Run both tests and confirm they pass.**
  Command: `./vendor/bin/pest tests/Feature/Sla/ScanSlaCommandTest.php tests/Feature/Sla/ScanSlaActivityTest.php`
  Expected: PASS, 0 failures. If only `ScanSlaActivityTest` fails with `Failed asserting that actual size 0 matches expected size 1`, T05's `RecordTicketActivity` doesn't map the SLA events as described in §3 "Consumes". Stop and report; don't add activity writes to the command.

- [ ] **Step 6: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 7: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 8: Commit.**
  ```bash
  git add app/Console/Commands/ScanSlaCommand.php tests/Feature/Sla/ScanSlaCommandTest.php tests/Feature/Sla/ScanSlaActivityTest.php
  git commit -m "feat(escalation): add idempotent sla:scan escalation command" --trailer "Refs: T09.1" --trailer "Implements: TD-044"
  ```

### 9.2 Schedule registration (TD-044)

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Sla/ScanSlaScheduleTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

it('schedules sla:scan every minute, without overlapping, on one server', function (): void {
    // Loads routes/console.php if the test bootstrap hasn't already (idempotent).
    $this->app->make(ConsoleKernel::class)->bootstrap();

    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'sla:scan'))
        ->values();

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
```

- [ ] **Step 2: Run the test and confirm it fails.**
  Command: `./vendor/bin/pest tests/Feature/Sla/ScanSlaScheduleTest.php`
  Expected: FAIL with `Failed asserting that actual size 0 matches expected size 1.`

- [ ] **Step 3: Add the import.** In `routes/console.php`, add this line to the `use` block (keep T00's existing imports and commands):

```php
use Illuminate\Support\Facades\Schedule;
```

- [ ] **Step 4: Add the schedule line.** Put it in `routes/console.php`, not in `->withScheduling()` in `bootstrap/app.php`: T00 owns that file, and Laravel 13 defers `withScheduling()` callbacks until `Schedule` is first resolved. `routes/console.php` is loaded when the console kernel bootstraps, which the Step 1 test triggers explicitly. Append this as the last statement of `routes/console.php`:

```php
// TD-044: every minute; idempotent, and locked against overlapping runs and multiple servers.
Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 5: Run the test and confirm it passes.**
  Command: `./vendor/bin/pest tests/Feature/Sla/ScanSlaScheduleTest.php`
  Expected: PASS, 1 test, 0 failures.

- [ ] **Step 6: Check the scheduler sees it.**
  Command: `php artisan schedule:list`
  Expected: a row `* * * * *  php artisan sla:scan ... Next Due: <n> seconds from now`. In Laravel 13, `schedule:list` checks each `withoutOverlapping()` event's mutex in the cache store. With `CACHE_STORE=database`, the worktree database must be migrated first, or the command fails with a `QueryException` on `cache_locks`.

- [ ] **Step 7: Format.** Command: `./vendor/bin/pint --dirty`. Expected: exits 0.

- [ ] **Step 8: Static analysis.** Command: `./vendor/bin/phpstan analyse --memory-limit=1G`. Expected: `[OK] No errors`.

- [ ] **Step 9: Commit.**
  ```bash
  git add routes/console.php tests/Feature/Sla/ScanSlaScheduleTest.php
  git commit -m "feat(escalation): schedule sla:scan every minute" --trailer "Refs: T09.2" --trailer "Implements: TD-044"
  ```

## 6. Acceptance criteria

- [ ] `php artisan sla:scan` exists, exits 0 and prints the counts.
- [ ] Candidates are streamed with `chunkById(500)` on an `id`-only select. No unbounded `get()`; the only `get()` reloads at most one chunk's ids.
- [ ] Thresholds: `warning ≤ now < due` → level 1 with `TicketSlaApproaching`. `due ≤ now` → level 2 with `TicketSlaBreached` and `escalated_at = now`. Both boundaries are inclusive at `now`.
- [ ] A 0 → 2 jump dispatches only `TicketSlaBreached`.
- [ ] Running the scan twice dispatches each event once (database-level idempotency). A ticket paused or resolved between candidate selection and the lock isn't escalated.
- [ ] Paused and `Resolved`/`Closed` tickets are never escalated.
- [ ] A ticket whose level was lowered by `SlaCalculator` escalates again on the next scan.
- [ ] `activity_log` gets exactly one `sla_approaching` or `escalated` entry per escalation, with a null causer (through T05's listener; the command writes no activity itself).
- [ ] `routes/console.php` contains exactly `Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();`, and a test asserts it.
- [ ] No notifications are sent by this task's code (T10).
- [ ] `declare(strict_types=1);` everywhere; the command class is `final`.

## 7. Verification

Run the gate in the worktree:

```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
```

Expected: Pint `PASS`, PHPStan `[OK] No errors`, Pest all green with 0 failures. No `resources/js` changes, so the frontend gate is not needed.

## Contract notes for controller

1. **Exactness mechanism.** The brief suggested marking rows with `escalated_at = :runAt` and re-selecting them. This plan uses `SELECT … FOR UPDATE` re-check + conditional `UPDATE` instead, because `escalated_at` has one-second precision and two runs in the same second would double-dispatch. As a result `escalated_at` is set **only on breach**, matching spec §8. Please confirm.
2. **0 → 2 jump dispatches only `TicketSlaBreached`** (decided here and tested). T10's `SendSlaNotifications` shouldn't expect an approaching notification before every breach.
3. **At-most-once delivery.** A crash between commit and dispatch loses that event (TD-044 chose "never duplicate"). Worth a sentence in TD-044's trade-offs.
4. **Event payload.** Tickets are dispatched with only `assignedAgent` eager-loaded. With `preventLazyLoading` on, T10's listeners must `loadMissing()` any other relation they need (for example `customer`, `category`).
5. **Dependencies on T05 internals.** The activity test assumes `RecordTicketActivity` maps `TicketSlaApproaching` → `sla_approaching` and `TicketSlaBreached` → `escalated` with a null causer, and that `SlaQuery::whereApproaching/whereBreached` include the active-status and `sla_paused_at IS NULL` restrictions (README §3.4 comments). The lock-race test also assumes that the approaching pass runs before the breached pass and issues exactly one chunk query when it finds nothing.
6. **Factory states.** The tests override `status`, `escalation_level` and `escalated_at` on `approaching()`, `breached()` and `paused()`, because T01 may set a non-zero level in those states for demo realism. The states only need to place `sla_warning_at`/`sla_due_at` relative to `now()` (and, for `paused()`, set `sla_paused_at` with an active status).
