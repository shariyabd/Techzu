# T06 — Assignment (manual, claim, automatic)

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Run subtasks in the mini-wave order below.

## 1. Header

- **Objective:** agents can claim unassigned tickets without double assignment under concurrency, admins can assign any ticket and agents can hand off their own, and new tickets are auto-assigned by category, availability and load, through the web UI and `/api/v1`.
- **Depends on:** T05 (events, `TicketPageData`, `TicketResource`, `AssignmentPanel.vue` stub, `resources/js/types/tickets.ts`, shadcn `select`).
- **Wave:** 4a (parallel with T07, T08, T09).
- **TDs implemented:** TD-032, TD-050, TD-051, TD-052, TD-053, TD-072 (AutoAssignTicket job reliability), TD-110 (`TicketAlreadyClaimed`, `AgentNotEligible`), TD-130 (`tickets.auto_assign`).

## 2. Files

**Create**

| Path | Subtask |
|---|---|
| `app/Services/Assignment/UnassignedTicketAssigner.php` | 6.1 |
| `app/Actions/Assignment/ClaimTicket.php` | 6.1 |
| `tests/Feature/Assignment/ClaimTicketTest.php`, `tests/Feature/Assignment/ClaimRaceTest.php` | 6.1 |
| `app/Services/Assignment/AgentSelector.php`, `tests/Feature/Assignment/AgentSelectorTest.php` | 6.2 |
| `app/Actions/Assignment/AssignTicket.php`, `tests/Feature/Assignment/AssignTicketTest.php` | 6.3 |
| `app/Actions/Assignment/AssignTicketAutomatically.php`, `app/Jobs/AutoAssignTicket.php`, `app/Listeners/QueueAutoAssignment.php` | 6.4 |
| `tests/Feature/Assignment/AutoAssignTicketTest.php`, `tests/Feature/Assignment/QueueAutoAssignmentTest.php` | 6.4 |
| `app/Http/Requests/Assignment/AssignTicketRequest.php` | 6.5 |
| `app/Http/Controllers/Web/AssignmentController.php`, `app/Http/Controllers/Api/V1/AssignmentController.php` | 6.5 |
| `routes/web/assignment.php`, `routes/api/v1/assignment.php` | 6.5 |
| `tests/Feature/Http/AssignmentEndpointsTest.php` | 6.5 |
| `tests/Feature/Authorization/AssignmentAuthorizationTest.php` | 6.6 |

**Modify**

| Path | Change | Subtask |
|---|---|---|
| `config/tickets.php` | add `auto_assign` (README §4 allows T06) | 6.1 |
| `resources/js/components/tickets/AssignmentPanel.vue` | replace the T05 stub (README §4 allows T06) | 6.5 |

**Must not touch:** every T05 file other than `AssignmentPanel.vue` (in particular `tickets/Show.vue`, `TicketResource`, `TicketPageData`, `RecordTicketActivity`), `app/Policies/*`, `config/database.php`, `phpunit.xml`, `tests/Pest.php`, `tests/Support/*`, migrations, enums, `bootstrap/*`. If one of them needs a change, stop and report.

## 3. Interfaces

### Consumes

| From | Name |
|---|---|
| T01 | `User::scopeAvailableAgents`, `User::categories()`, `User::assignedTickets()`, `Ticket` casts, `TicketStatus::active()`, `AssignmentMethod`, `TicketAlreadyClaimed::for(string $ticketNumber)`, `AgentNotEligible` (constructed with a message; see Contract notes, item 2), factories `agent(array $categoryIds)`, `unavailable()`, `Ticket::factory()->assignedTo()/status()` |
| T04 | `TicketPolicy::view`, `claim`, `assign`; `tests/Support/AuthorizationMatrix.php` (`AuthorizationMatrix::for($this)->actingAsRole()`, `->expectAccess()`) |
| T05 | events `TicketAssigned`, `TicketCreated`, `TicketStatusChanged`; `RecordTicketActivity` (logs `claimed` / `assigned`); `App\Services\Tickets\TicketPageData::loadSummary()`; `App\Http\Resources\V1\TicketResource`; `resources/js/types/tickets.ts` (`TicketDetail`, `AgentOption`, `TicketCan`); Show page props `agents` (filled only when `can.assign`) |

### Produces (binding, README §3)

```php
// app/Actions/Assignment
ClaimTicket::handle(User $agent, Ticket $ticket): Ticket                 // throws TicketAlreadyClaimed (409)
AssignTicket::handle(User $actor, Ticket $ticket, User $agent): Ticket   // throws AgentNotEligible (422)
AssignTicketAutomatically::handle(Ticket $ticket): ?User

// app/Services/Assignment
final class AgentSelector { public function selectFor(Ticket $ticket, bool $lock = false): ?User; }

// app/Jobs
final class AutoAssignTicket implements ShouldQueue, ShouldBeUnique   // __construct(int $ticketId), uniqueId = ticketId, queue 'default'

// app/Listeners
final class QueueAutoAssignment   // handle(TicketCreated): dispatches AutoAssignTicket unless imported or tickets.auto_assign is false
```

T06-internal (no other task may depend on it): `App\Services\Assignment\UnassignedTicketAssigner::assignIfUnassigned(Ticket $ticket, User $agent, CarbonImmutable $now): bool`, the TD-051 conditional UPDATE shared by `ClaimTicket` and `AssignTicketAutomatically`.

Config: `config('tickets.auto_assign')` (bool, default `true`, env `TICKETS_AUTO_ASSIGN`).

Routes (README §3.10):

| Name | Method + URI | Payload | Success |
|---|---|---|---|
| `tickets.claim` | POST `/tickets/{ticket}/claim` | — | redirect back, flash `success` |
| `tickets.assign` | POST `/tickets/{ticket}/assign` | `agent_id` | redirect back, flash `success` |
| `api.v1.tickets.claim` | POST `/api/v1/tickets/{ticket}/claim` | — | 200 `TicketResource` |
| `api.v1.tickets.assign` | POST `/api/v1/tickets/{ticket}/assign` | `agent_id` | 200 `TicketResource` |

Errors: claim lost → 409 `{"message", "code": "ticket_already_claimed"}` (API) / redirect back with flash `error` (web). Ineligible target → 422 `agent_not_eligible`.

Events fired:

| Action | Events |
|---|---|
| ClaimTicket | `TicketAssigned(actor = agent, previousAgent = null, method = Claimed)`, plus `TicketStatusChanged(Open → InProgress)` when the claim moved an Open ticket |
| AssignTicket | `TicketAssigned(actor, agent, previousAgent, method = Manual)` (no status change) |
| AssignTicketAutomatically | `TicketAssigned(actor = null, agent, null, method = Automatic)`, plus `TicketStatusChanged(null actor, Open → InProgress)` when applicable |

Assignment semantics:
- Claim and auto-assign use the TD-051 statement, so an Open ticket becomes InProgress in the same atomic write. Manual assignment does not change the status: an admin routing a ticket is not the agent starting work on it.
- Every successful assignment sets `users.last_assigned_at = now` for the new agent (the TD-050 round-robin tie-break).
- An agent that is unavailable, or doesn't have the agent role, is never a valid target (`AgentNotEligible`). Category membership is a rule for auto-assignment only; an admin can route a ticket to any available agent.

## 4. Subtasks & mini-waves

| Subtask | Files (summary) | Depends on | Parallel-safe with |
|---|---|---|---|
| 6.1 Claim (assigner, ClaimTicket, race test, config) | `UnassignedTicketAssigner`, `ClaimTicket`, `config/tickets.php`, 2 tests | T05 merged | 6.2, 6.3 |
| 6.2 AgentSelector | `AgentSelector` + test | T05 merged | 6.1, 6.3 |
| 6.3 AssignTicket | `AssignTicket` + test | T05 merged | 6.1, 6.2 |
| 6.4 Auto-assignment | `AssignTicketAutomatically`, `Jobs\AutoAssignTicket`, `QueueAutoAssignment` + 2 tests | 6.1, 6.2 | 6.5 |
| 6.5 HTTP + UI | request, 2 controllers, 2 route files, `AssignmentPanel.vue` + test | 6.1, 6.3 | 6.4 |
| 6.6 Authorization matrix | `AssignmentAuthorizationTest` | 6.5 | — |

Mini-waves: **A** = 6.1 ∥ 6.2 ∥ 6.3 → **B** = 6.4 ∥ 6.5 → **C** = 6.6.

Fixed test clock: `2026-11-01 03:00:00 UTC` (Sunday 09:00 Asia/Dhaka).

---

## 5. Subtasks

### 6.1 Claim

**Files:** `config/tickets.php`, `app/Services/Assignment/UnassignedTicketAssigner.php`, `app/Actions/Assignment/ClaimTicket.php`, `tests/Feature/Assignment/ClaimTicketTest.php`, `tests/Feature/Assignment/ClaimRaceTest.php`.

Every assignment Action runs its transaction on `$ticket->getConnection()` and queries through `$ticket->newQuery()`. In production that is the default connection, so nothing changes. It also lets the race test pin each competing claim to its own real MySQL connection by loading the ticket through that connection (TD-032).

- [ ] **Step 1: add `auto_assign` to `config/tickets.php`.** Keep the existing `number_prefix` (T03) and `per_page` (T05) entries exactly as they are, and add:

```php
    // TD-050: route new tickets to the least-loaded available agent of their category.
    'auto_assign' => (bool) env('TICKETS_AUTO_ASSIGN', true),
```

Verify:
```bash
php artisan tinker --execute="var_dump(config('tickets.auto_assign'));"
```
Expected: `bool(true)`.

- [ ] **Step 2: write the failing test** `tests/Feature/Assignment/ClaimTicketTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Assignment\ClaimTicket;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->agent = User::factory()->agent()->create(['last_assigned_at' => null]);
});

it('claims an unassigned open ticket and moves it to in progress', function (): void {
    Event::fake([TicketAssigned::class, TicketStatusChanged::class]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    $result = app(ClaimTicket::class)->handle($this->agent, $ticket);

    expect($result->assigned_agent_id)->toBe($this->agent->id)
        ->and($result->status)->toBe(TicketStatus::InProgress)
        ->and($result->assigned_at?->equalTo($this->now))->toBeTrue()
        ->and($this->agent->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();

    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->ticket->is($ticket)
        && $e->actor?->is($this->agent) === true
        && $e->agent->is($this->agent)
        && $e->previousAgent === null
        && $e->method === AssignmentMethod::Claimed);
    Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e): bool => $e->from === TicketStatus::Open
        && $e->to === TicketStatus::InProgress);
});

it('keeps a non-open status when claiming', function (): void {
    Event::fake([TicketAssigned::class, TicketStatusChanged::class]);
    $ticket = Ticket::factory()->status(TicketStatus::WaitingForCustomer)->create(['assigned_agent_id' => null]);

    $result = app(ClaimTicket::class)->handle($this->agent, $ticket);

    expect($result->status)->toBe(TicketStatus::WaitingForCustomer);
    Event::assertDispatched(TicketAssigned::class, 1);
    Event::assertNotDispatched(TicketStatusChanged::class);
});

it('throws TicketAlreadyClaimed when the ticket already has an agent', function (): void {
    Event::fake([TicketAssigned::class]);
    $owner = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create();

    expect(fn () => app(ClaimTicket::class)->handle($this->agent, $ticket))
        ->toThrow(TicketAlreadyClaimed::class);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($owner->id)
        ->and($this->agent->fresh()?->last_assigned_at)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
});

it('decides on the database row, not on a stale model', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);
    $stale = Ticket::query()->findOrFail($ticket->id);
    $winner = User::factory()->agent()->create();

    app(ClaimTicket::class)->handle($winner, $ticket);

    expect($stale->assigned_agent_id)->toBeNull()
        ->and(fn () => app(ClaimTicket::class)->handle($this->agent, $stale))->toThrow(TicketAlreadyClaimed::class);
    expect($ticket->fresh()?->assigned_agent_id)->toBe($winner->id);
});

it('maps the exception to 409 ticket_already_claimed', function (): void {
    $exception = TicketAlreadyClaimed::for('TKT-2026-000001');

    expect($exception->status())->toBe(409)
        ->and($exception->errorCode())->toBe('ticket_already_claimed');
});
```

- [ ] **Step 3: run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Assignment/ClaimTicketTest.php
```
Expected: FAIL, `Target class [App\Actions\Assignment\ClaimTicket] does not exist.` (the last test passes: it covers T01's exception).

- [ ] **Step 4: create `app/Services/Assignment/UnassignedTicketAssigner.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * TD-051: a single conditional UPDATE. InnoDB takes the row lock and re-evaluates
 * `assigned_agent_id IS NULL` against the latest committed row, so among concurrent callers
 * exactly one gets one affected row. No PHP-side read decides the outcome.
 */
final class UnassignedTicketAssigner
{
    public function assignIfUnassigned(Ticket $ticket, User $agent, CarbonImmutable $now): bool
    {
        $affected = $ticket->newQuery()
            ->whereKey($ticket->getKey())
            ->whereNull('assigned_agent_id')
            ->update([
                'assigned_agent_id' => $agent->getKey(),
                'assigned_at' => $now,
                // Only enum constants are interpolated, never request data.
                'status' => DB::raw(sprintf(
                    "CASE WHEN status = '%s' THEN '%s' ELSE status END",
                    TicketStatus::Open->value,
                    TicketStatus::InProgress->value,
                )),
            ]);

        if ($affected !== 1) {
            return false;
        }

        $agent->newQuery()->whereKey($agent->getKey())->update(['last_assigned_at' => $now]);
        $agent->setAttribute('last_assigned_at', $now);
        $agent->syncOriginalAttribute('last_assigned_at');

        return true;
    }
}
```

- [ ] **Step 5: create `app/Actions/Assignment/ClaimTicket.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;

final class ClaimTicket
{
    public function __construct(
        private readonly UnassignedTicketAssigner $assigner,
    ) {}

    public function handle(User $agent, Ticket $ticket): Ticket
    {
        $now = CarbonImmutable::now();
        // Snapshot only used to describe the change in the timeline; the claim itself is decided by the UPDATE.
        $before = $ticket->status;

        return $ticket->getConnection()->transaction(function () use ($agent, $ticket, $now, $before): Ticket {
            if (! $this->assigner->assignIfUnassigned($ticket, $agent, $now)) {
                throw TicketAlreadyClaimed::for($ticket->number);
            }

            $ticket->refresh();

            TicketAssigned::dispatch($ticket, $agent, $agent, null, AssignmentMethod::Claimed);

            if ($before === TicketStatus::Open && $ticket->status === TicketStatus::InProgress) {
                TicketStatusChanged::dispatch($ticket, $agent, TicketStatus::Open, TicketStatus::InProgress);
            }

            return $ticket;
        });
    }
}
```

- [ ] **Step 6: run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Assignment/ClaimTicketTest.php
```
Expected: PASS (5 tests).

- [ ] **Step 7: write the race test** `tests/Feature/Assignment/ClaimRaceTest.php`.

Why it does not `uses(DatabaseTruncation::class)`: T01's `tests/Pest.php` applies `RefreshDatabase` to every file under `tests/Feature`, so the default connection is always inside an uncommitted transaction. Adding `DatabaseTruncation` would `TRUNCATE` inside that transaction (MySQL commits implicitly on DDL) and corrupt it. The test keeps the intent of TD-032 instead:
- It clones the default connection config into two extra connections, `race_a` and `race_b`, which RefreshDatabase does not wrap.
- It seeds committed rows through `race_a`.
- Each racer works on its own connection: its ticket and agent models are loaded through it, and the Actions follow the model's connection.
- `afterEach` deletes exactly the committed rows the test created, which is truncation scoped to this test.

Interleaving is forced deterministically, without processes or sleeps:
- **Test 1 (commit order):** B opens a transaction and claims (1 row). B commits. Then A claims on its own connection and gets 0 rows → `TicketAlreadyClaimed`.
- **Test 2 (lock wait):** B claims inside an open transaction and does not commit. A sets `innodb_lock_wait_timeout = 1` and claims. A's UPDATE must **block** on B's row lock, then fail with MySQL error 1205, which proves A cannot read the stale "unassigned" row. B commits; A retries and gets `TicketAlreadyClaimed`.
- Both tests assert one winner, one loser, and a single `claimed` activity.

```php
<?php

declare(strict_types=1);

use App\Actions\Assignment\ClaimTicket;
use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Exceptions\Domain\TicketAlreadyClaimed;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Notification::fake();

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.race_a', $base);
    config()->set('database.connections.race_b', $base);
    DB::purge('race_a');
    DB::purge('race_b');

    $a = DB::connection('race_a');
    $now = now();

    $userRow = fn (string $name): array => [
        'name' => $name,
        'email' => Str::uuid()->toString().'@race.test',
        'password' => bcrypt('password'),
        'is_available' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $this->raceIds = [
        'customer' => $a->table('users')->insertGetId($userRow('Race Customer')),
        'agentA' => $a->table('users')->insertGetId($userRow('Agent A')),
        'agentB' => $a->table('users')->insertGetId($userRow('Agent B')),
    ];
    $this->raceIds['category'] = $a->table('categories')->insertGetId([
        'name' => 'Race', 'slug' => 'race-'.Str::random(8), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->raceIds['ticket'] = $a->table('tickets')->insertGetId([
        'number' => 'TKT-2099-'.random_int(100000, 999999),
        'subject' => 'Race',
        'description' => 'Two agents, one ticket.',
        'priority' => 'high',
        'status' => TicketStatus::Open->value,
        'category_id' => $this->raceIds['category'],
        'customer_id' => $this->raceIds['customer'],
        'created_by_id' => $this->raceIds['customer'],
        'assigned_agent_id' => null,
        'sla_started_at' => $now,
        'sla_due_at' => $now->copy()->addHours(8),
        'sla_warning_at' => $now->copy()->addHours(6),
        'escalation_level' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

afterEach(function (): void {
    foreach (['race_a', 'race_b'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }

    $a = DB::connection('race_a');
    $a->table('tickets')->where('id', $this->raceIds['ticket'])->delete();
    $a->table('categories')->where('id', $this->raceIds['category'])->delete();
    $a->table('users')->whereIn('id', [$this->raceIds['customer'], $this->raceIds['agentA'], $this->raceIds['agentB']])->delete();

    DB::disconnect('race_a');
    DB::disconnect('race_b');
});

function racer(string $connection, int $ticketId, int $agentId): array
{
    return [
        Ticket::on($connection)->findOrFail($ticketId),
        User::on($connection)->findOrFail($agentId),
    ];
}

function claimedActivities(int $ticketId): int
{
    return Activity::query()
        ->where('subject_type', (new Ticket)->getMorphClass())
        ->where('subject_id', $ticketId)
        ->where('event', ActivityType::Claimed->value)
        ->count();
}

it('lets exactly one of two connections claim the ticket', function (): void {
    [$ticketA, $agentA] = racer('race_a', $this->raceIds['ticket'], $this->raceIds['agentA']);
    [$ticketB, $agentB] = racer('race_b', $this->raceIds['ticket'], $this->raceIds['agentB']);

    // Both racers loaded the ticket while it was unassigned.
    expect($ticketA->assigned_agent_id)->toBeNull()
        ->and($ticketB->assigned_agent_id)->toBeNull();

    DB::connection('race_b')->beginTransaction();
    app(ClaimTicket::class)->handle($agentB, $ticketB);
    DB::connection('race_b')->commit();

    expect(fn () => app(ClaimTicket::class)->handle($agentA, $ticketA))
        ->toThrow(TicketAlreadyClaimed::class);

    $row = DB::connection('race_a')->table('tickets')->where('id', $this->raceIds['ticket'])->first();
    expect($row->assigned_agent_id)->toBe($this->raceIds['agentB'])
        ->and($row->status)->toBe(TicketStatus::InProgress->value)
        ->and(claimedActivities($this->raceIds['ticket']))->toBe(1);
});

it('makes the second claim wait for the first one\'s row lock instead of reading a stale row', function (): void {
    [$ticketA, $agentA] = racer('race_a', $this->raceIds['ticket'], $this->raceIds['agentA']);
    [$ticketB, $agentB] = racer('race_b', $this->raceIds['ticket'], $this->raceIds['agentB']);

    DB::connection('race_b')->beginTransaction();
    app(ClaimTicket::class)->handle($agentB, $ticketB);   // holds the row lock, not committed

    DB::connection('race_a')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $started = microtime(true);
    try {
        app(ClaimTicket::class)->handle($agentA, $ticketA);
        $this->fail('The competing claim did not block on the row lock.');
    } catch (QueryException $e) {
        expect($e->errorInfo[1] ?? null)->toBe(1205);   // ER_LOCK_WAIT_TIMEOUT
    }
    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.9);

    DB::connection('race_b')->commit();

    expect(fn () => app(ClaimTicket::class)->handle($agentA, $ticketA))
        ->toThrow(TicketAlreadyClaimed::class);

    $row = DB::connection('race_a')->table('tickets')->where('id', $this->raceIds['ticket'])->first();
    expect($row->assigned_agent_id)->toBe($this->raceIds['agentB'])
        ->and(claimedActivities($this->raceIds['ticket']))->toBe(1);
});
```

- [ ] **Step 8: run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Assignment/ClaimRaceTest.php
```
Expected: PASS (2 tests, about 1 s for the second).

- [ ] **Step 9: prove the race test bites.** Temporarily delete the line `->whereNull('assigned_agent_id')` in `UnassignedTicketAssigner` and rerun. Expected: FAIL in test 1, `Failed asserting that ... throws TicketAlreadyClaimed` (agent A silently overwrote B). Revert:

```bash
git checkout app/Services/Assignment/UnassignedTicketAssigner.php
```

- [ ] **Step 10: run under parallel Pest** (per-process databases) to make sure the cloned connections follow the parallel database name

```bash
./vendor/bin/pest --parallel tests/Feature/Assignment
```
Expected: PASS.

- [ ] **Step 11: commit**

```bash
git add config/tickets.php app/Services/Assignment/UnassignedTicketAssigner.php app/Actions/Assignment/ClaimTicket.php tests/Feature/Assignment/ClaimTicketTest.php tests/Feature/Assignment/ClaimRaceTest.php
git commit -m "feat(assignment): add atomic ticket claim with race tests" --trailer "Refs: T06.1"
```

---
### 6.2 AgentSelector (parallel with 6.1, 6.3)

**Files:** `app/Services/Assignment/AgentSelector.php`, `tests/Feature/Assignment/AgentSelectorTest.php`.

Ordering (TD-050): candidates are users who pass `availableAgents()` (role agent + `is_available`) **and** are linked to the ticket's category. They are ordered by active ticket count (Open, InProgress, WaitingForCustomer) ascending, then `last_assigned_at` ascending with never-assigned first, then `id`. With `$lock = true` (inside the caller's transaction) the candidate rows are first locked `FOR UPDATE` in id order, so concurrent auto-assign jobs serialize and always take locks in the same sequence (TD-052).

- [ ] **Step 1: write the failing test** `tests/Feature/Assignment/AgentSelectorTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AgentSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->billing = Category::factory()->create();
    $this->technical = Category::factory()->create();
});

function loadAgent(User $agent, int $active, int $finished = 0): User
{
    if ($active > 0) {
        Ticket::factory()->count($active)->status(TicketStatus::InProgress)->assignedTo($agent)->create();
    }
    if ($finished > 0) {
        Ticket::factory()->count($finished)->status(TicketStatus::Closed)->assignedTo($agent)->create();
    }

    return $agent;
}

dataset('selection rules', [
    'only agents of the ticket category' => [function (): array {
        $other = User::factory()->agent([$this->technical->id])->create();
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 5);

        return [$this->billing, $match, [$other]];
    }],
    'unavailable agents are skipped' => [function (): array {
        $away = User::factory()->agent([$this->billing->id])->unavailable()->create();
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 3);

        return [$this->billing, $match, [$away]];
    }],
    'admins in the category are not agents' => [function (): array {
        $admin = User::factory()->admin()->create();
        $admin->categories()->attach($this->billing->id);
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);

        return [$this->billing, $match, [$admin]];
    }],
    'least active load wins' => [function (): array {
        $busy = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => null]), 3);
        $light = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now]), 1);

        return [$this->billing, $light, [$busy]];
    }],
    'finished tickets do not count as load' => [function (): array {
        $closer = loadAgent(User::factory()->agent([$this->billing->id])->create(), 1, 10);
        $other = loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);

        return [$this->billing, $closer, [$other]];
    }],
    'equal load: never-assigned first' => [function (): array {
        $recent = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subHour()]), 1);
        $never = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => null]), 1);

        return [$this->billing, $never, [$recent]];
    }],
    'equal load: oldest last assignment first' => [function (): array {
        $recent = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subHour()]), 1);
        $oldest = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDays(3)]), 1);

        return [$this->billing, $oldest, [$recent]];
    }],
    'full tie: lowest id' => [function (): array {
        $first = User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDay()]);
        $second = User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDay()]);

        return [$this->billing, $first, [$second]];
    }],
    'no eligible agent' => [function (): array {
        $other = User::factory()->agent([$this->technical->id])->create();

        return [$this->billing, null, [$other]];
    }],
]);

it('selects the agent required by TD-050', function (Closure $scenario): void {
    [$category, $expected] = $scenario->call($this);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $category->id, 'assigned_agent_id' => null]);

    $selected = app(AgentSelector::class)->selectFor($ticket);

    expect($selected?->id)->toBe($expected?->id);
})->with('selection rules');

it('returns the same agent when locking, and locks with FOR UPDATE', function (): void {
    $light = User::factory()->agent([$this->billing->id])->create();
    loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->billing->id, 'assigned_agent_id' => null]);

    DB::enableQueryLog();
    $selected = DB::transaction(fn () => app(AgentSelector::class)->selectFor($ticket, true));
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($selected?->id)->toBe($light->id)
        ->and(strtolower($sql))->toContain('for update');
});

it('returns null when locking finds no candidates', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->billing->id, 'assigned_agent_id' => null]);

    expect(DB::transaction(fn () => app(AgentSelector::class)->selectFor($ticket, true)))->toBeNull();
});
```

Each dataset closure returns `[category, expected agent or null, other agents that must lose]`; the third element documents the losers and is not asserted separately because the expected id already excludes them. The dataset takes `Closure $scenario` and calls it with `$scenario->call($this)` inside the test, so the closures run after `beforeEach` and can use `$this->billing`.

- [ ] **Step 2: run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Assignment/AgentSelectorTest.php
```
Expected: FAIL, `Target class [App\Services\Assignment\AgentSelector] does not exist.`

- [ ] **Step 3: create `app/Services/Assignment/AgentSelector.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AgentSelector
{
    /**
     * TD-050 ordering. With $lock = true the caller must be inside a transaction: candidate rows are
     * locked FOR UPDATE in id order first, so concurrent selections serialize on shared agents and
     * always acquire locks in the same order (TD-052, no deadlocks).
     */
    public function selectFor(Ticket $ticket, bool $lock = false): ?User
    {
        $candidates = User::query()
            ->availableAgents()
            ->whereHas('categories', static fn (Builder $q) => $q->whereKey($ticket->category_id));

        if ($lock) {
            $ids = (clone $candidates)
                ->orderBy('users.id')
                ->lockForUpdate()
                ->pluck('users.id')
                ->all();

            if ($ids === []) {
                return null;
            }

            $candidates = User::query()->whereKey($ids);
        }

        $active = array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::active());

        return $candidates
            ->select('users.*')
            ->withCount(['assignedTickets as active_tickets_count' => static fn (Builder $q) => $q->whereIn('status', $active)])
            ->orderBy('active_tickets_count')
            // Never-assigned agents first, then the longest-waiting one: a round-robin among equals.
            ->orderByRaw('users.last_assigned_at IS NOT NULL')
            ->orderBy('users.last_assigned_at')
            ->orderBy('users.id')
            ->first();
    }
}
```

- [ ] **Step 4: run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Assignment/AgentSelectorTest.php
```
Expected: PASS (11 tests).

- [ ] **Step 5: commit**

```bash
git add app/Services/Assignment/AgentSelector.php tests/Feature/Assignment/AgentSelectorTest.php
git commit -m "feat(assignment): select agents by category, availability and load" --trailer "Refs: T06.2"
```

---

### 6.3 AssignTicket (parallel with 6.1, 6.2)

**Files:** `app/Actions/Assignment/AssignTicket.php`, `tests/Feature/Assignment/AssignTicketTest.php`.

`AssignTicket` deliberately overrides an existing assignment (TD-051 trade-offs), so it does not use the `IS NULL` predicate. It reads and then writes, so it locks the ticket row inside its transaction. Authorization (admin any ticket, agent only a ticket assigned to them) is the policy's job at the HTTP layer. The Action enforces the target rule only.

- [ ] **Step 1: write the failing test** `tests/Feature/Assignment/AssignTicketTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicket;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentNotEligible;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([TicketAssigned::class]);
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create(['last_assigned_at' => null]);
});

it('assigns an unassigned ticket without changing its status', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    $result = app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($result->assigned_agent_id)->toBe($this->agent->id)
        ->and($result->status)->toBe(TicketStatus::Open)
        ->and($result->assigned_at?->equalTo($this->now))->toBeTrue()
        ->and($this->agent->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();

    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->actor?->is($this->admin) === true
        && $e->agent->is($this->agent)
        && $e->previousAgent === null
        && $e->method === AssignmentMethod::Manual);
});

it('reassigns and reports the previous agent', function (): void {
    $previous = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($previous)->create();

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->previousAgent?->is($previous) === true);
});

it('supports an agent handing off their own ticket', function (): void {
    $owner = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create();

    app(AssignTicket::class)->handle($owner, $ticket, $this->agent);

    expect($ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $e): bool => $e->actor?->is($owner) === true
        && $e->previousAgent?->is($owner) === true);
});

it('rejects targets that are not available agents', function (Closure $target): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    expect(fn () => app(AssignTicket::class)->handle($this->admin, $ticket, $target()))
        ->toThrow(AgentNotEligible::class);

    expect($ticket->fresh()?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
})->with([
    'unavailable agent' => [fn () => User::factory()->agent()->unavailable()->create()],
    'customer' => [fn () => User::factory()->customer()->create()],
    'admin' => [fn () => User::factory()->admin()->create()],
]);

it('maps AgentNotEligible to 422 agent_not_eligible', function (): void {
    $e = new AgentNotEligible('x');

    expect($e->status())->toBe(422)->and($e->errorCode())->toBe('agent_not_eligible');
});

it('is a no-op when the agent already owns the ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();

    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);

    Event::assertNotDispatched(TicketAssigned::class);
});

it('locks the ticket row while deciding', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);

    DB::enableQueryLog();
    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);
    $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    DB::disableQueryLog();

    expect($sql)->toContain('for update');
});
```

- [ ] **Step 2: run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Assignment/AssignTicketTest.php
```
Expected: FAIL, `Target class [App\Actions\Assignment\AssignTicket] does not exist.` (the 422-mapping test passes; it covers T01's exception).

- [ ] **Step 3: create `app/Actions/Assignment/AssignTicket.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Enums\AssignmentMethod;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentNotEligible;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;

final class AssignTicket
{
    public function handle(User $actor, Ticket $ticket, User $agent): Ticket
    {
        $now = CarbonImmutable::now();

        return $ticket->getConnection()->transaction(function () use ($actor, $ticket, $agent, $now): Ticket {
            $eligible = User::query()->availableAgents()->whereKey($agent->getKey())->exists();
            if (! $eligible) {
                throw new AgentNotEligible(sprintf('%s is not an available agent.', $agent->name));
            }

            $locked = $ticket->newQuery()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->setRawAttributes($locked->getAttributes(), true);

            $previousId = $ticket->assigned_agent_id;
            if ($previousId === $agent->getKey()) {
                return $ticket;
            }

            $ticket->assigned_agent_id = $agent->getKey();
            $ticket->assigned_at = $now;
            $ticket->save();
            $ticket->unsetRelation('assignedAgent');

            $agent->newQuery()->whereKey($agent->getKey())->update(['last_assigned_at' => $now]);
            $agent->setAttribute('last_assigned_at', $now);
            $agent->syncOriginalAttribute('last_assigned_at');

            $previous = $previousId === null ? null : User::query()->find($previousId);

            TicketAssigned::dispatch($ticket, $actor, $agent, $previous, AssignmentMethod::Manual);

            return $ticket;
        });
    }
}
```

- [ ] **Step 4: run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Assignment/AssignTicketTest.php
```
Expected: PASS (9 tests).

- [ ] **Step 5: commit**

```bash
git add app/Actions/Assignment/AssignTicket.php tests/Feature/Assignment/AssignTicketTest.php
git commit -m "feat(assignment): add admin assignment and agent handoff" --trailer "Refs: T06.3"
```

---
### 6.4 Auto-assignment (parallel with 6.5)

**Files:** `app/Actions/Assignment/AssignTicketAutomatically.php`, `app/Jobs/AutoAssignTicket.php`, `app/Listeners/QueueAutoAssignment.php`, `tests/Feature/Assignment/AutoAssignTicketTest.php`, `tests/Feature/Assignment/QueueAutoAssignmentTest.php`.

Flow: `TicketCreated` (dispatched after commit) → `QueueAutoAssignment` (sync, auto-discovered) → `AutoAssignTicket` job on queue `default` → `AssignTicketAutomatically`. The Action runs one transaction: `AgentSelector::selectFor($ticket, lock: true)` locks the candidate agents in id order, then the TD-051 conditional UPDATE assigns. If a human claimed the ticket in the meantime, the UPDATE affects 0 rows and the Action returns `null` quietly (TD-052). If no agent is eligible, it logs at `info` and the ticket stays in the unassigned queue (TD-050, TD-110).

Test environment note: `phpunit.xml` (T00) sets `TICKETS_AUTO_ASSIGN=false`, so earlier tests never auto-assign as a side effect. Every T06 test that relies on auto-assignment (`QueueAutoAssignmentTest`, `AutoAssignTicketTest`) sets `config()->set('tickets.auto_assign', true)` in its own `beforeEach`. The default of `true` in `config/tickets.php` applies outside tests.

- [ ] **Step 1: write the failing job/action test** `tests/Feature/Assignment/AutoAssignTicketTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignTicketAutomatically;
use App\Enums\ActivityType;
use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Jobs\AutoAssignTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    // phpunit.xml sets TICKETS_AUTO_ASSIGN=false; these tests exercise auto-assignment explicitly.
    config()->set('tickets.auto_assign', true);
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->category = Category::factory()->create();
});

function runAutoAssign(Ticket $ticket): void
{
    app()->call([new AutoAssignTicket($ticket->id), 'handle']);
}

it('assigns the least-loaded available agent of the category', function (): void {
    $busy = User::factory()->agent([$this->category->id])->create();
    Ticket::factory()->count(2)->status(TicketStatus::InProgress)->assignedTo($busy)->create();
    $free = User::factory()->agent([$this->category->id])->create(['last_assigned_at' => null]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($ticket);

    $fresh = $ticket->fresh();
    expect($fresh?->assigned_agent_id)->toBe($free->id)
        ->and($fresh?->status)->toBe(TicketStatus::InProgress)
        ->and($free->fresh()?->last_assigned_at?->equalTo($this->now))->toBeTrue();

    $activity = Activity::query()
        ->where('subject_id', $ticket->id)
        ->where('event', ActivityType::Assigned->value)
        ->sole();
    expect($activity->causer_id)->toBeNull()
        ->and($activity->properties->get('method'))->toBe(AssignmentMethod::Automatic->value);
});

it('leaves the ticket unassigned and logs when no agent is eligible', function (): void {
    Log::spy();
    User::factory()->agent([Category::factory()->create()->id])->create();
    User::factory()->agent([$this->category->id])->unavailable()->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($ticket);

    expect($ticket->fresh()?->assigned_agent_id)->toBeNull()
        ->and($ticket->fresh()?->status)->toBe(TicketStatus::Open);
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'no eligible agent')
            && ($context['ticket'] ?? null) === $ticket->number)
        ->once();
});

it('exits quietly when the ticket was claimed between selection and update', function (): void {
    Event::fake([TicketAssigned::class]);
    User::factory()->agent([$this->category->id])->create();
    $claimer = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);
    $stale = Ticket::query()->findOrFail($ticket->id);
    Ticket::query()->whereKey($ticket->id)->update(['assigned_agent_id' => $claimer->id]);

    $result = app(AssignTicketAutomatically::class)->handle($stale);

    expect($result)->toBeNull()
        ->and($ticket->fresh()?->assigned_agent_id)->toBe($claimer->id);
    Event::assertNotDispatched(TicketAssigned::class);
});

it('skips tickets that are already assigned, finished or deleted', function (): void {
    Event::fake([TicketAssigned::class]);
    User::factory()->agent([$this->category->id])->create();
    $owner = User::factory()->agent()->create();
    $assigned = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create(['category_id' => $this->category->id]);
    $resolved = Ticket::factory()->status(TicketStatus::Resolved)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);

    runAutoAssign($assigned);
    runAutoAssign($resolved);
    app()->call([new AutoAssignTicket(999_999), 'handle']);

    expect($assigned->fresh()?->assigned_agent_id)->toBe($owner->id)
        ->and($resolved->fresh()?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
});

it('is a unique, retrying job on the default queue', function (): void {
    $job = new AutoAssignTicket(42);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->queue)->toBe('default')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300]);
});

it('queues one job per ticket even if dispatched twice', function (): void {
    Queue::fake();

    AutoAssignTicket::dispatch(7);
    AutoAssignTicket::dispatch(7);
    AutoAssignTicket::dispatch(8);

    Queue::assertPushed(AutoAssignTicket::class, 2);
    Queue::assertPushed(AutoAssignTicket::class, fn (AutoAssignTicket $job): bool => $job->ticketId === 7);
});
```

- [ ] **Step 2: write the failing listener test** `tests/Feature/Assignment/QueueAutoAssignmentTest.php`

```php
<?php

declare(strict_types=1);

use App\Actions\Tickets\CreateTicket;
use App\Events\TicketCreated;
use App\Jobs\AutoAssignTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    // phpunit.xml sets TICKETS_AUTO_ASSIGN=false; this file opts in explicitly.
    config()->set('tickets.auto_assign', true);
});

it('queues auto-assignment for a new ticket', function (): void {
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);

    TicketCreated::dispatch($ticket, null);

    Queue::assertPushedOn('default', AutoAssignTicket::class, fn (AutoAssignTicket $job): bool => $job->ticketId === $ticket->id);
});

it('skips imported tickets', function (): void {
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);

    TicketCreated::dispatch($ticket, null, true);

    Queue::assertNotPushed(AutoAssignTicket::class);
});

it('skips when auto-assignment is switched off', function (): void {
    config()->set('tickets.auto_assign', false);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);

    TicketCreated::dispatch($ticket, null);

    Queue::assertNotPushed(AutoAssignTicket::class);
});

it('is triggered after CreateTicket commits', function (): void {
    $ticket = app(CreateTicket::class)->handle(User::factory()->customer()->create(), [
        'subject' => 'S',
        'description' => 'D',
        'priority' => 'low',
        'category_id' => Category::factory()->create()->id,
    ]);

    Queue::assertPushed(AutoAssignTicket::class, 1);
    Queue::assertPushed(AutoAssignTicket::class, fn (AutoAssignTicket $job): bool => $job->ticketId === $ticket->id);
});
```

- [ ] **Step 3: run both, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Assignment/AutoAssignTicketTest.php tests/Feature/Assignment/QueueAutoAssignmentTest.php
```
Expected: FAIL, `Class "App\Jobs\AutoAssignTicket" not found`.

- [ ] **Step 4: create `app/Actions/Assignment/AssignTicketAutomatically.php`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assignment\AgentSelector;
use App\Services\Assignment\UnassignedTicketAssigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class AssignTicketAutomatically
{
    public function __construct(
        private readonly AgentSelector $selector,
        private readonly UnassignedTicketAssigner $assigner,
    ) {}

    public function handle(Ticket $ticket): ?User
    {
        $now = CarbonImmutable::now();
        $before = $ticket->status;

        return $ticket->getConnection()->transaction(function () use ($ticket, $now, $before): ?User {
            $agent = $this->selector->selectFor($ticket, lock: true);

            if ($agent === null) {
                Log::info('Auto-assignment found no eligible agent; the ticket stays in the unassigned queue.', [
                    'ticket' => $ticket->number,
                    'category_id' => $ticket->category_id,
                ]);

                return null;
            }

            // 0 rows: someone claimed it after the job started. That is a valid outcome, not an error (TD-052).
            if (! $this->assigner->assignIfUnassigned($ticket, $agent, $now)) {
                return null;
            }

            $ticket->refresh();

            TicketAssigned::dispatch($ticket, null, $agent, null, AssignmentMethod::Automatic);

            if ($before === TicketStatus::Open && $ticket->status === TicketStatus::InProgress) {
                TicketStatusChanged::dispatch($ticket, null, TicketStatus::Open, TicketStatus::InProgress);
            }

            return $agent;
        });
    }
}
```

- [ ] **Step 5: create `app/Jobs/AutoAssignTicket.php`.** Laravel 13 also accepts queue settings as attributes (`Illuminate\Queue\Attributes\Tries`, `Backoff`, `Queue`). Keep the `$tries` / `$backoff` properties and `onQueue()`, because the Step 1 test reads `$job->tries`, `$job->backoff` and `$job->queue`. The job carries only an id and re-reads the ticket, so Laravel 13's `#[DeleteWhenMissingModels]` handling doesn't apply to it. A missing ticket is a quiet `return`.

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Assignment\AssignTicketAutomatically;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries only the id: the ticket is re-read when the job runs, so a claim made while the job
 * waited in the queue is seen.
 */
final class AutoAssignTicket implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $ticketId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(AssignTicketAutomatically $assignAutomatically): void
    {
        $ticket = Ticket::query()->find($this->ticketId);

        if ($ticket === null || $ticket->assigned_agent_id !== null || ! $ticket->isActive()) {
            return;
        }

        $assignAutomatically->handle($ticket);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Auto-assignment job failed.', ['ticket_id' => $this->ticketId, 'exception' => $e->getMessage()]);
    }
}
```

- [ ] **Step 6: create `app/Listeners/QueueAutoAssignment.php`**

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Jobs\AutoAssignTicket;
use Illuminate\Contracts\Config\Repository as Config;

final class QueueAutoAssignment
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function handle(TicketCreated $event): void
    {
        // Imported tickets are historical data and must not be routed to today's agents (TD-090).
        if ($event->imported || ! (bool) $this->config->get('tickets.auto_assign', false)) {
            return;
        }

        if ($event->ticket->assigned_agent_id !== null) {
            return;
        }

        AutoAssignTicket::dispatch($event->ticket->id);
    }
}
```

- [ ] **Step 7: confirm discovery**

```bash
php artisan event:list --event=TicketCreated
```
Expected: `App\Listeners\QueueAutoAssignment@handle` listed under `App\Events\TicketCreated` (next to `RecordTicketActivity@handleTicketCreated`).

- [ ] **Step 8: run both tests, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Assignment/AutoAssignTicketTest.php tests/Feature/Assignment/QueueAutoAssignmentTest.php
```
Expected: PASS (10 tests).

- [ ] **Step 9: run the full suite to catch side effects of auto-assignment on earlier tests**

```bash
./vendor/bin/pest --parallel
```
Expected: PASS. If a T05 test fails because a ticket it created is now auto-assigned, stop and report the test name to the controller (T06 does not edit T05 tests).

- [ ] **Step 10: commit**

```bash
git add app/Actions/Assignment/AssignTicketAutomatically.php app/Jobs/AutoAssignTicket.php app/Listeners/QueueAutoAssignment.php tests/Feature/Assignment/AutoAssignTicketTest.php tests/Feature/Assignment/QueueAutoAssignmentTest.php
git commit -m "feat(assignment): auto-assign new tickets via queued job" --trailer "Refs: T06.4"
```

---

### 6.5 HTTP endpoints and AssignmentPanel (parallel with 6.4)

**Files:** `app/Http/Requests/Assignment/AssignTicketRequest.php`, `app/Http/Controllers/Web/AssignmentController.php`, `app/Http/Controllers/Api/V1/AssignmentController.php`, `routes/web/assignment.php`, `routes/api/v1/assignment.php`, `resources/js/components/tickets/AssignmentPanel.vue`, `tests/Feature/Http/AssignmentEndpointsTest.php`.

Both endpoints check `view` first, so a customer gets 404 on a ticket they can't see (TD-012). `claim` has no payload and is authorized in the controller with `Gate::authorize`. `assign` uses `AssignTicketRequest`, which authorizes before validating.

- [ ] **Step 1: write the failing test** `tests/Feature/Http/AssignmentEndpointsTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));
    config()->set('tickets.auto_assign', false);
    $this->category = Category::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent([$this->category->id])->create();
    $this->colleague = User::factory()->agent([$this->category->id])->create();
    $this->customer = User::factory()->customer()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::Open)->create([
        'category_id' => $this->category->id,
        'customer_id' => $this->customer->id,
        'assigned_agent_id' => null,
    ]);
});

it('claims through the web and flashes success', function (): void {
    $this->actingAs($this->agent)
        ->from('/tickets/'.$this->ticket->number)
        ->post('/tickets/'.$this->ticket->number.'/claim')
        ->assertRedirect('/tickets/'.$this->ticket->number)
        ->assertSessionHas('success');

    expect($this->ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
});

it('flashes an error when the web claim loses', function (): void {
    $this->ticket->forceFill(['assigned_agent_id' => $this->colleague->id])->save();

    $this->actingAs($this->agent)
        ->from('/tickets/'.$this->ticket->number)
        ->post('/tickets/'.$this->ticket->number.'/claim')
        ->assertRedirect('/tickets/'.$this->ticket->number)
        ->assertSessionHas('error');

    expect($this->ticket->fresh()?->assigned_agent_id)->toBe($this->colleague->id);
});

it('claims through the API', function (): void {
    Sanctum::actingAs($this->agent);

    $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/claim')
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.status.value', 'in_progress');
});

it('returns 409 ticket_already_claimed when the API claim loses', function (): void {
    $this->ticket->forceFill(['assigned_agent_id' => $this->colleague->id])->save();
    Sanctum::actingAs($this->agent);

    $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/claim')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ticket_already_claimed')
        ->assertJsonStructure(['message', 'code']);
});

it('lets an admin assign through the API', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->agent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.status.value', 'open');
});

it('rejects an ineligible target with agent_not_eligible', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->customer->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'agent_not_eligible');
});

it('validates agent_id', function (array $payload): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agent_id');
})->with([
    'missing' => [[]],
    'unknown user' => [['agent_id' => 999_999]],
    'not an integer' => [['agent_id' => 'abc']],
]);

it('lets an admin assign through the web', function (): void {
    $this->actingAs($this->admin)
        ->from('/tickets/'.$this->ticket->number)
        ->post('/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->agent->id])
        ->assertRedirect('/tickets/'.$this->ticket->number)
        ->assertSessionHas('success');

    expect($this->ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
});

describe('handoff authorization', function (): void {
    it('lets the assigned agent hand off to a colleague', function (): void {
        $this->ticket->forceFill(['assigned_agent_id' => $this->agent->id])->save();
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->colleague->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $this->colleague->id);
    });

    it('forbids an agent from reassigning a colleague\'s ticket', function (): void {
        $this->ticket->forceFill(['assigned_agent_id' => $this->colleague->id])->save();
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->agent->id])->assertForbidden();
        expect($this->ticket->fresh()?->assigned_agent_id)->toBe($this->colleague->id);
    });

    it('forbids an agent from assigning an unassigned ticket (claim instead)', function (): void {
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->colleague->id])->assertForbidden();
    });

    it('forbids customers, hiding tickets they do not own', function (): void {
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/assign', ['agent_id' => $this->agent->id])->assertForbidden();
        $this->postJson('/api/v1/tickets/'.$this->ticket->number.'/claim')->assertForbidden();

        $foreign = Ticket::factory()->create();
        $this->postJson('/api/v1/tickets/'.$foreign->number.'/assign', ['agent_id' => $this->agent->id])->assertNotFound();
        $this->postJson('/api/v1/tickets/'.$foreign->number.'/claim')->assertNotFound();
    });
});
```

- [ ] **Step 2: run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Http/AssignmentEndpointsTest.php
```
Expected: FAIL, `Expected response status code [302] but received 404.` / `[200] but received 404` (routes missing).

- [ ] **Step 3: create `app/Http/Requests/Assignment/AssignTicketRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Assignment;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

final class AssignTicketRequest extends FormRequest
{
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $view = $gate->inspect('view', $this->ticket());

        return $view->denied() ? $view : $gate->inspect('assign', $this->ticket());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Eligibility (available agent) is a domain rule enforced by AssignTicket with a stable error code.
        return [
            'agent_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function targetAgent(): User
    {
        return User::query()->findOrFail((int) $this->validated('agent_id'));
    }

    public function ticket(): Ticket
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof Ticket) {
            throw new LogicException('AssignTicketRequest requires a bound {ticket} route parameter.');
        }

        return $ticket;
    }
}
```

- [ ] **Step 4: create `app/Http/Controllers/Web/AssignmentController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ClaimTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\AssignTicketRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AssignmentController extends Controller
{
    public function claim(Request $request, Ticket $ticket, ClaimTicket $claimTicket): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        Gate::authorize('claim', $ticket);

        // TicketAlreadyClaimed is rendered by the global handler as back()->with('error', ...).
        $claimTicket->handle($this->user($request), $ticket);

        return back()->with('success', "You claimed {$ticket->number}.");
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
        $agent = $request->targetAgent();
        $assignTicket->handle($this->user($request), $ticket, $agent);

        return back()->with('success', "{$ticket->number} assigned to {$agent->name}.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
```

- [ ] **Step 5: create `app/Http/Controllers/Api/V1/AssignmentController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ClaimTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\AssignTicketRequest;
use App\Http\Resources\V1\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketPageData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AssignmentController extends Controller
{
    public function __construct(
        private readonly TicketPageData $pages,
    ) {}

    public function claim(Request $request, Ticket $ticket, ClaimTicket $claimTicket): TicketResource
    {
        Gate::authorize('view', $ticket);
        Gate::authorize('claim', $ticket);

        $ticket = $claimTicket->handle($this->user($request), $ticket);

        return TicketResource::make($this->pages->loadSummary($ticket));
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): TicketResource
    {
        $ticket = $assignTicket->handle($this->user($request), $ticket, $request->targetAgent());

        return TicketResource::make($this->pages->loadSummary($ticket));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
```

- [ ] **Step 6: create `routes/web/assignment.php`**

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('tickets/{ticket}/claim', [AssignmentController::class, 'claim'])->name('tickets.claim');
    Route::post('tickets/{ticket}/assign', [AssignmentController::class, 'assign'])->name('tickets.assign');
});
```

- [ ] **Step 7: create `routes/api/v1/assignment.php`**

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('tickets/{ticket}/claim', [AssignmentController::class, 'claim'])->name('tickets.claim');
    Route::post('tickets/{ticket}/assign', [AssignmentController::class, 'assign'])->name('tickets.assign');
});
```

- [ ] **Step 8: confirm the routes**

```bash
php artisan route:list --path=claim --except-vendor && php artisan route:list --path=assign --except-vendor
```
Expected: `tickets.claim`, `api.v1.tickets.claim`, `tickets.assign`, `api.v1.tickets.assign`, all POST.

- [ ] **Step 9: run the HTTP test, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Http/AssignmentEndpointsTest.php
```
Expected: PASS (14 tests). If "returns 409" gets 403 instead, T04's `TicketPolicy::claim` rejects already-assigned tickets; stop and report (Contract notes, item 1).

- [ ] **Step 10: replace `resources/js/components/tickets/AssignmentPanel.vue`** (keeps the T05 props contract: `ticket`, `agents`, `can`)

```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { AgentOption, TicketCan, TicketDetail } from '@/types/tickets';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ ticket: TicketDetail; agents: AgentOption[]; can: TicketCan }>();

const page = usePage();
const selectedAgent = ref('');
const busy = ref(false);
const errors = ref<Record<string, string>>({});

// A lost claim (409) comes back as a redirect with flash.error and fresh props showing the real owner.
const flashError = computed<string | null>(() => {
    const flash = page.props.flash as { error?: string | null } | undefined;
    return flash?.error ?? null;
});

const isAssigned = computed(() => props.ticket.assigned_agent !== null);
const canClaim = computed(() => props.can.claim && !isAssigned.value);
const candidates = computed(() => props.agents.filter((agent) => agent.id !== props.ticket.assigned_agent?.id));
const assignLabel = computed(() => (isAssigned.value ? 'Hand off' : 'Assign'));

const visitOptions = {
    preserveScroll: true,
    onStart: () => {
        busy.value = true;
    },
    onFinish: () => {
        busy.value = false;
    },
};

const claim = (): void => {
    router.post(`/tickets/${props.ticket.number}/claim`, {}, visitOptions);
};

const assign = (): void => {
    if (selectedAgent.value === '') {
        return;
    }

    router.post(
        `/tickets/${props.ticket.number}/assign`,
        { agent_id: Number(selectedAgent.value) },
        {
            ...visitOptions,
            onSuccess: () => {
                selectedAgent.value = '';
                errors.value = {};
            },
            onError: (bag) => {
                errors.value = bag;
            },
        },
    );
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Assignment</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
            <p class="text-sm">
                <span class="text-muted-foreground">Assigned to</span>
                <span class="ml-1 font-medium" data-testid="assigned-agent">{{ ticket.assigned_agent?.name ?? 'Unassigned' }}</span>
            </p>

            <p v-if="flashError" class="text-sm text-destructive" role="alert">{{ flashError }}</p>

            <Button v-if="canClaim" class="w-full" :disabled="busy" @click="claim">Claim ticket</Button>

            <div v-if="can.assign && candidates.length > 0" class="grid gap-2">
                <Label for="assign-agent">{{ isAssigned ? 'Hand off to' : 'Assign to' }}</Label>
                <div class="flex gap-2">
                    <Select :model-value="selectedAgent" @update:model-value="(v) => (selectedAgent = String(v ?? ''))">
                        <SelectTrigger id="assign-agent" class="flex-1"><SelectValue placeholder="Choose an agent" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="agent in candidates" :key="agent.id" :value="String(agent.id)">{{ agent.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button :disabled="busy || selectedAgent === ''" @click="assign">{{ assignLabel }}</Button>
                </div>
                <InputError :message="errors.agent_id" />
            </div>

            <p v-else-if="can.assign" class="text-sm text-muted-foreground">No other available agents.</p>
        </CardContent>
    </Card>
</template>
```

- [ ] **Step 11: build and type-check.** Build first: the kit's Wayfinder Vite plugin generates `resources/js/{routes,actions,wayfinder}` during the build, and those folders are gitignored. On a fresh worktree, `vue-tsc` run first fails with `TS2307: Cannot find module '@/routes'` in the kit's own components.

```bash
npm run build && npx vue-tsc --noEmit
```
Expected: Vite prints `✓ built in …`; `vue-tsc` exits 0.

- [ ] **Step 12: check the Show page still renders with the real panel**

```bash
./vendor/bin/pest tests/Feature/Http/Web/TicketPagesTest.php
```
Expected: PASS (T05's page tests are unaffected; the panel's props are unchanged).

- [ ] **Step 13: commit**

```bash
git add app/Http/Requests/Assignment/AssignTicketRequest.php app/Http/Controllers/Web/AssignmentController.php app/Http/Controllers/Api/V1/AssignmentController.php routes/web/assignment.php routes/api/v1/assignment.php resources/js/components/tickets/AssignmentPanel.vue tests/Feature/Http/AssignmentEndpointsTest.php
git commit -m "feat(assignment): add claim and assign endpoints and panel" --trailer "Refs: T06.5"
```

---

### 6.6 Authorization matrix

**Files:** `tests/Feature/Authorization/AssignmentAuthorizationTest.php`. A characterization test over 6.5 using T04's helper (`$matrix->actingAsRole(Role)`, `$matrix->expectAccess($method, $uri, $status)`, no payload, JSON for `/api/`). With an empty payload, `assign` that passes authorization fails validation (302 web / 422 API), and a permitted `claim` on an unassigned ticket succeeds (302 / 200).

- [ ] **Step 1: write the test** `tests/Feature/Authorization/AssignmentAuthorizationTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Tests\Support\AuthorizationMatrix;

beforeEach(function (): void {
    config()->set('tickets.auto_assign', false);
});

dataset('assignment route matrix', function (): array {
    $rows = [
        // role, ownership, action, web status, api status
        ['customer', 'own', 'claim', 403, 403],
        ['customer', 'own', 'assign', 403, 403],
        ['customer', 'other', 'claim', 404, 404],
        ['customer', 'other', 'assign', 404, 404],
        ['agent', 'unassigned', 'claim', 302, 200],
        ['agent', 'unassigned', 'assign', 403, 403],
        ['agent', 'colleague', 'assign', 403, 403],
        ['agent', 'own', 'assign', 302, 422],
        ['admin', 'unassigned', 'claim', 302, 200],
        ['admin', 'unassigned', 'assign', 302, 422],
        ['admin', 'colleague', 'assign', 302, 422],
    ];

    $cases = [];
    foreach ($rows as [$role, $ownership, $action, $web, $api]) {
        $cases["web {$role} {$ownership} {$action}"] = [$role, $ownership, "/tickets/{n}/{$action}", $web];
        $cases["api {$role} {$ownership} {$action}"] = [$role, $ownership, "/api/v1/tickets/{n}/{$action}", $api];
    }

    return $cases;
});

it('enforces the assignment matrix', function (string $role, string $ownership, string $uri, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $matrix->actingAsRole(Role::from($role));

    $factory = Ticket::factory()->status(TicketStatus::Open);
    $ticket = match ($ownership) {
        'own' => $role === 'customer'
            ? $factory->create(['customer_id' => $user->id, 'assigned_agent_id' => null])
            : $factory->assignedTo($user)->create(),
        'colleague' => $factory->assignedTo(User::factory()->agent()->create())->create(),
        'unassigned', 'other' => $factory->create(['assigned_agent_id' => null]),
    };

    $matrix->expectAccess('POST', str_replace('{n}', $ticket->number, $uri), $status);
})->with('assignment route matrix');

it('requires authentication', function (string $uri, int $status): void {
    $ticket = Ticket::factory()->create();
    $uri = str_replace('{n}', $ticket->number, $uri);

    $response = str_starts_with($uri, '/api/') ? $this->postJson($uri) : $this->post($uri);

    $response->assertStatus($status);
})->with([
    ['/tickets/{n}/claim', 302],
    ['/tickets/{n}/assign', 302],
    ['/api/v1/tickets/{n}/claim', 401],
    ['/api/v1/tickets/{n}/assign', 401],
]);
```

- [ ] **Step 2: run it, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Authorization/AssignmentAuthorizationTest.php
```
Expected: PASS (22 matrix + 4 guest = 26 tests).

- [ ] **Step 3: prove it bites.** Temporarily remove the line `Gate::authorize('claim', $ticket);` from **both** assignment controllers and rerun. Expected: FAIL in `web customer own claim` / `api customer own claim` (`Expected response status code [403] but received 302/200`). Revert:

```bash
git checkout app/Http/Controllers/Web/AssignmentController.php app/Http/Controllers/Api/V1/AssignmentController.php
```

- [ ] **Step 4: commit**

```bash
git add tests/Feature/Authorization/AssignmentAuthorizationTest.php
git commit -m "test(auth): cover assignment routes in authorization matrix" --trailer "Refs: T06.6"
```

---

## 6. Acceptance criteria

- [ ] `ClaimTicket` uses exactly the TD-051 conditional UPDATE: 1 affected row assigns (and moves Open → InProgress), 0 rows throws `TicketAlreadyClaimed` (409, `ticket_already_claimed`), and the outcome never depends on a PHP-side read.
- [ ] With two real MySQL connections, exactly one claim wins, the loser gets `TicketAlreadyClaimed`, a claim made while the winner's transaction is open blocks on the row lock, and exactly one `claimed` activity exists.
- [ ] `AssignTicket` runs in a transaction with `lockForUpdate`, rejects unavailable agents and non-agents with `AgentNotEligible` (422), reports the previous agent, leaves the status unchanged, and fires `TicketAssigned(method = Manual)`.
- [ ] An agent can hand off only a ticket assigned to them; a colleague's ticket or an unassigned one gets 403; customers get 403 on their own tickets and 404 on others'.
- [ ] `AgentSelector` orders by category membership, availability, active load ascending, `last_assigned_at` ascending with nulls first, then id; with `$lock` it issues `FOR UPDATE` in id order.
- [ ] `AutoAssignTicket` is `ShouldQueue` + `ShouldBeUnique` (uniqueId = ticket id) on queue `default` with `tries = 3`, `backoff = [10, 60, 300]` and `failed()` logging. It assigns the least-loaded eligible agent, logs and leaves the ticket unassigned when none is eligible, and exits quietly when the ticket was claimed concurrently.
- [ ] `QueueAutoAssignment` dispatches the job after `TicketCreated` only for non-imported tickets and only when `tickets.auto_assign` is true.
- [ ] Every successful assignment updates `users.last_assigned_at`.
- [ ] `AssignmentPanel.vue` shows the owner, a claim button (only when claimable and unassigned), an assign/hand-off select limited to other available agents, and the flash error after a lost claim. Its props are unchanged from the T05 stub.
- [ ] Web and API routes `tickets.claim`, `tickets.assign`, `api.v1.tickets.claim`, `api.v1.tickets.assign` exist and are covered by the authorization matrix.

## 7. Verification

Run in the worktree:

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
php artisan event:list --event=TicketCreated
php artisan route:list --path=tickets --except-vendor
```

Expected:
- Pint: `PASS`.
- PHPStan: `[OK] No errors`.
- Pest: all tests pass, including `ClaimTicketTest`, `ClaimRaceTest`, `AgentSelectorTest`, `AssignTicketTest`, `AutoAssignTicketTest`, `QueueAutoAssignmentTest`, `AssignmentEndpointsTest`, `AssignmentAuthorizationTest`, and every T05 test.
- Build and vue-tsc: no errors.
- `event:list`: `QueueAutoAssignment@handle` under `TicketCreated`.
- `route:list`: the four claim/assign routes in addition to T05's.

Landing: rebase and fast-forward into `main` (README §2 step 9). No squash. Across the task's commits, the `Implements:` trailers cover TD-032, TD-050, TD-051, TD-052, TD-053, TD-072, TD-110, TD-130.

## Contract notes for controller

1. **Resolved:** `TicketPolicy::claim` ignores the assignee by design, and T05's `can.claim` adds the `assigned_agent_id === null` check for the UI. **`TicketPolicy::claim` (T04) must not check `assigned_agent_id`.** It has to allow staff to attempt any claim. If it rejects already-assigned tickets, a lost race returns 403 instead of the contracted 409 `ticket_already_claimed`, and the API test in 6.5 fails. The Action owns the "still unassigned" check, atomically (TD-051).
2. **`AgentNotEligible` has no named constructor in README §3.2.** T06 calls `new AgentNotEligible($message)`, which relies on the inherited `RuntimeException` constructor. If T01 adds a named constructor such as `AgentNotEligible::for(User $agent)`, switch to it.
3. **Resolved:** approved. **TD-051 changes the status outside `ChangeTicketStatus`.** This conflicts with CLAUDE.md rule 5, so the plan follows the TD:
   - The atomic claim UPDATE moves Open → InProgress.
   - To keep the timeline complete, `ClaimTicket` and `AssignTicketAutomatically` dispatch `TicketStatusChanged(Open → InProgress)` when that happened. This is not in README §3.5's event list for T06.
   - The pre-claim status comes from the in-memory snapshot. A concurrent status change between the read and the claim could mislabel that one entry, but it cannot corrupt state.
4. **Resolved:** the approach is approved. **The race test does not use `DatabaseTruncation` (TD-032).** T01's `tests/Pest.php` applies `RefreshDatabase` to all of `tests/Feature`, and the two traits conflict on MySQL. The race test instead commits through two dedicated cloned connections and deletes its own rows in `afterEach`. If the controller prefers literal `DatabaseTruncation`, T01's `Pest.php` must exclude a folder (for example `tests/Feature/Concurrency`) from `RefreshDatabase`.
5. **Assignment Actions transact on `$ticket->getConnection()` rather than `DB::transaction()`.** The two are identical in production. It is what lets the race test pin each racer to its own connection.
6. **Resolved:** `phpunit.xml` (T00) sets `TICKETS_AUTO_ASSIGN=false`. T06's auto-assignment tests opt in with `config()->set('tickets.auto_assign', true)`.
7. **T06 consumes the T05-internal `TicketPageData::loadSummary()`** to render `TicketResource` in the API controller. README §3 should list it if T05 keeps it public.
