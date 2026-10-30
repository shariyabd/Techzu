# P3 — Ticket operations

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Session steps (S0, S1) are done by the controller itself. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** add assignment (manual, claim, automatic), the ticket conversation (public replies, internal notes, reply attachments, secure downloads) and search and filtering to the backend and `/api/v1`, and expose the Inertia props that P5's UI consumes.
**Depends on:** P2 (and T00–T05 on `main`).
**Outcome:**
- Agents claim unassigned tickets safely under concurrency (409 for the loser).
- Admins assign any ticket, and agents hand off their own.
- New tickets are queued for auto-assignment by category, availability and load.
- Customers and staff post replies with attachments. Staff post internal notes that customers never see through any page, API response or activity entry.
- Attachments download only through a policy-checked stream.
- `GET /tickets` and `GET /api/v1/tickets` accept the TD-080 filters, keyword search and a whitelisted sort.
- No Vue file changes; P5 builds the UI from the props listed in "Produces for P5".

## Schema needs for P1
- `tickets(updated_at)` index. It serves `sort=updated_at` / `sort=-updated_at` (spec §12 whitelist), and `AddReply` touches `updated_at` on every reply. TD-021 has no index for it; add the row "`tickets(updated_at)` | sort by recent activity" to TD-021.

No other schema change: every other query below uses an index already listed in TD-021.

## Global constraints
- No `resources/js` changes. Every Vue task from T06.5, T07.5 and T08.5 moves to P5.
- Fixed test clock where time matters: `2026-11-01 03:00:00 UTC` (Sunday 09:00 Asia/Dhaka).
- `phpunit.xml` sets `TICKETS_AUTO_ASSIGN=false`. Only the auto-assignment tests opt in, with `config()->set('tickets.auto_assign', true)`.
- Tests that store files call `Storage::fake(config('attachments.disk'))` first.
- Implementers run only their own files: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest <files>`, then `./vendor/bin/pint <their PHP files>`.
- To keep the plan short, PHP blocks are compacted: whole-file blocks omit the `<?php` / `declare(strict_types=1);` header, test blocks omit their `use` imports, and blank lines inside bodies are dropped. Add the header and the imports (every class named is an existing `App\…`, `Database\…`, `Tests\…`, `Illuminate\…`, `Laravel\Sanctum\Sanctum`, `Spatie\Activitylog\Models\Activity` or `Inertia\Testing\AssertableInertia as Assert` class), and let Pint restore the spacing.
- Domain exceptions render through T00's handler: JSON `{message, code}` with `status()` for the API, `back()->with('error', …)` for the web. Controllers never catch them.
- Approved exception to CLAUDE.md rule 5 (TD-051): the claim and auto-assign UPDATE moves `open` → `in_progress` in the same atomic statement, and the Action then dispatches `TicketStatusChanged` so the timeline stays complete. No other status change happens outside `ChangeTicketStatus`.

## Waves
| Wave | Subtask | Model | Agent N | Runs in parallel with |
|---|---|---|---|---|
| W1 | P3.1 Assignment | opus | 1 | P3.2, P3.3 |
| W1 | P3.2 Conversation and attachments | sonnet | 2 | P3.1, P3.3 |
| W1 | P3.3 Search and filters | sonnet | 3 | P3.1, P3.2 |
| W2 | P3.4 Show-page props, authorization matrix, leakage tests | sonnet | 1 | — |

Shared existing files and their single owner:

| File | W1 owner | W2 owner |
|---|---|---|
| `config/tickets.php` | P3.1 | — |
| `app/Http/Controllers/Web/TicketController.php` | P3.3 (`index()` only) | P3.4 (`show()`, `create()`, drops `replyItems()`) |
| `app/Services/Tickets/TicketPageData.php` | P3.3 (`filterOptions()`) | P3.4 (`loadForShow()`, `attachmentRules()`) |
| `app/Services/Tickets/TicketListQuery.php`, `app/Http/Requests/Tickets/IndexTicketsRequest.php` | P3.3 | — |
| `tests/Feature/Performance/TicketQueryCountTest.php` | P3.3 | — |
| `tests/Support/RouteAudit.php` (P2.4) | — | P3.4 (`attachment` binding) |

No subtask touches `TicketResource`, `RecordTicketActivity`, the policies, the events, the models or any migration. P3.2 needs `TicketReplyResource` on the web show page; P3.4 wires it in W2.

---

## P3.1 — Assignment (claim, manual, automatic)

**Files:**
- Modify: `config/tickets.php`
- Create:
  - `app/Services/Assignment/UnassignedTicketAssigner.php`
  - `app/Services/Assignment/AgentSelector.php`
  - `app/Actions/Assignment/ClaimTicket.php`
  - `app/Actions/Assignment/AssignTicket.php`
  - `app/Actions/Assignment/AssignTicketAutomatically.php`
  - `app/Jobs/AutoAssignTicket.php`
  - `app/Listeners/QueueAutoAssignment.php`
  - `app/Http/Requests/Assignment/AssignTicketRequest.php`
  - `app/Http/Controllers/Web/AssignmentController.php`
  - `app/Http/Controllers/Api/V1/AssignmentController.php`
  - `routes/web/assignment.php`, `routes/api/v1/assignment.php`
  - `tests/Feature/Assignment/ClaimTicketTest.php`
  - `tests/Concurrency/ClaimTicketConcurrencyTest.php`
  - `tests/Feature/Assignment/AgentSelectorTest.php`
  - `tests/Feature/Assignment/AssignTicketTest.php`
  - `tests/Feature/Assignment/AutoAssignTicketTest.php`
  - `tests/Feature/Assignment/QueueAutoAssignmentTest.php`
  - `tests/Feature/Http/AssignmentEndpointsTest.php`

**Consumes:**
- `TicketPolicy::claim` (permission `tickets.claim` + active status; it deliberately ignores the assignee, so a lost race reaches the Action and returns 409), `TicketPolicy::assign` (`tickets.assign.any`, or `tickets.handoff.own` on one's own ticket). Both answer 404 for a ticket the user can't see.
- `TicketAlreadyClaimed::for(string $number)` (409 `ticket_already_claimed`), `new AgentNotEligible(string $message)` (422 `agent_not_eligible`).
- Events `TicketAssigned(Ticket, ?User $actor, User $agent, ?User $previousAgent, AssignmentMethod)`, `TicketStatusChanged(Ticket, ?User, from, to)`, `TicketCreated(Ticket, ?User, bool $imported)`. `RecordTicketActivity` already logs `claimed` (method Claimed) or `assigned` (Manual, Automatic).
- `User::scopeAvailableAgents`, `User::categories()`, `User::assignedTickets()`, `TicketStatus::active()`.
- `TicketPageData::loadSummary()`, `TicketResource` (already exposes `can.claim` = policy ∧ unassigned, and `can.assign`).
- The show page already passes `agents` (available agents, only when `can.assign`). P3.1 changes no page prop.

**Produces:**
```php
App\Actions\Assignment\ClaimTicket::handle(User $agent, Ticket $ticket): Ticket
App\Actions\Assignment\AssignTicket::handle(User $actor, Ticket $ticket, User $agent): Ticket
App\Actions\Assignment\AssignTicketAutomatically::handle(Ticket $ticket): ?User
App\Services\Assignment\AgentSelector::selectFor(Ticket $ticket, bool $lock = false): ?User
App\Jobs\AutoAssignTicket::__construct(int $ticketId)
App\Listeners\QueueAutoAssignment::handle(TicketCreated $event): void
config('tickets.auto_assign')
```
`UnassignedTicketAssigner::assignIfUnassigned(Ticket, User, CarbonImmutable): bool` is internal to this subtask.

| Route name | Method + URI | Payload | Success | Errors |
|---|---|---|---|---|
| `tickets.claim` | POST `/tickets/{ticket}/claim` | — | redirect back, flash `success` | flash `error` (lost claim) |
| `tickets.assign` | POST `/tickets/{ticket}/assign` | `agent_id` | redirect back, flash `success` | validation errors / flash `error` |
| `api.v1.tickets.claim` | POST `/api/v1/tickets/{ticket}/claim` | — | 200 `TicketResource` | 409 `ticket_already_claimed` |
| `api.v1.tickets.assign` | POST `/api/v1/tickets/{ticket}/assign` | `agent_id` | 200 `TicketResource` | 422 `agent_not_eligible` / validation |

Assignment semantics:
- Claim and auto-assign use the TD-051 conditional UPDATE, so `open` becomes `in_progress` in the same write. Manual assignment never changes the status.
- Every successful assignment sets `users.last_assigned_at = now` for the new agent (the TD-050 tie-break).
- A target that is unavailable or isn't an agent raises `AgentNotEligible`. Category membership applies only to auto-assignment.

### Steps

**1. Config.** Add this entry to `config/tickets.php`, keeping `number_prefix` and `per_page` as they are:

```php
    'auto_assign' => (bool) env('TICKETS_AUTO_ASSIGN', true),
```

**2. Write the failing claim test** `tests/Feature/Assignment/ClaimTicketTest.php`:

```php
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

```

**3. Create `app/Services/Assignment/UnassignedTicketAssigner.php`.** This is the TD-051 statement. InnoDB re-evaluates `assigned_agent_id IS NULL` under the row lock, so exactly one concurrent caller gets an affected row. Only enum constants go into the CASE expression. It queries through the model's own connection so the concurrency test can pin a racer to a second connection.

```php
namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

**4. Create `app/Actions/Assignment/ClaimTicket.php`.** It opens its transaction on `$ticket->getConnection()`, which is the default connection in production and lets the concurrency test run a racer on `mysql_concurrent`.

```php
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

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Assignment/ClaimTicketTest.php`. Expected: 3 passed.

**5. Write the concurrency test** `tests/Concurrency/ClaimTicketConcurrencyTest.php` (TD-032). `tests/Pest.php` gives this folder `DatabaseTruncation`, so factory rows are committed and visible to `concurrentConnection()`. The first claim holds its row lock inside an open transaction; the second claim, on its own connection with a stale "unassigned" model, must block on that lock (MySQL 1205 after 1 s) instead of overwriting it, and must lose with `TicketAlreadyClaimed` once the first commits.

```php
afterEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('blocks a competing claim on the row lock and rejects it after the first claim commits', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['assigned_agent_id' => null]);
    $first = User::factory()->agent()->create();
    $second = User::factory()->agent()->create();
    concurrentConnection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $staleTicket = Ticket::on('mysql_concurrent')->findOrFail($ticket->id);
    $secondAgent = User::on('mysql_concurrent')->findOrFail($second->id);
    DB::beginTransaction();
    app(ClaimTicket::class)->handle($first, $ticket);
    $blocked = null;
    try {
        app(ClaimTicket::class)->handle($secondAgent, $staleTicket);
    } catch (QueryException $exception) {
        $blocked = $exception;
    }
    DB::commit();
    expect($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and($staleTicket->assigned_agent_id)->toBeNull()
        ->and(fn () => app(ClaimTicket::class)->handle($secondAgent, $staleTicket))->toThrow(TicketAlreadyClaimed::class);
    $row = DB::table('tickets')->where('id', $ticket->id)->first();
    $claims = Activity::query()
        ->where('subject_type', $ticket->getMorphClass())
        ->where('subject_id', $ticket->id)
        ->where('event', ActivityType::Claimed->value)
        ->count();
    expect($row->assigned_agent_id)->toBe($first->id)
        ->and($row->status)->toBe(TicketStatus::InProgress->value)
        ->and($claims)->toBe(1);
});
```

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Concurrency/ClaimTicketConcurrencyTest.php`. Expected: 1 passed in about 1 s. Then prove it bites: delete `->whereNull('assigned_agent_id')` from the assigner, rerun, expect FAIL (no `TicketAlreadyClaimed`), and restore the line.

**6. Write the failing selector test** `tests/Feature/Assignment/AgentSelectorTest.php`. Each dataset closure returns `[category, expected agent or null]`; it takes `Closure $scenario` and is called with `$scenario->call($this)` so it runs after `beforeEach`.

```php
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
        User::factory()->agent([$this->technical->id])->create();
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 5);
        return [$this->billing, $match];
    }],
    'unavailable agents are skipped' => [function (): array {
        User::factory()->agent([$this->billing->id])->unavailable()->create();
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 3);
        return [$this->billing, $match];
    }],
    'admins in the category are not agents' => [function (): array {
        User::factory()->admin()->create()->categories()->attach($this->billing->id);
        $match = loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);
        return [$this->billing, $match];
    }],
    'least active load wins' => [function (): array {
        loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => null]), 3);
        $light = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now]), 1);
        return [$this->billing, $light];
    }],
    'finished tickets do not count as load' => [function (): array {
        $closer = loadAgent(User::factory()->agent([$this->billing->id])->create(), 1, 10);
        loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);
        return [$this->billing, $closer];
    }],
    'equal load: never-assigned first' => [function (): array {
        loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subHour()]), 1);
        $never = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => null]), 1);
        return [$this->billing, $never];
    }],
    'equal load: oldest last assignment first' => [function (): array {
        loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subHour()]), 1);
        $oldest = loadAgent(User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDays(3)]), 1);
        return [$this->billing, $oldest];
    }],
    'full tie: lowest id' => [function (): array {
        $first = User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDay()]);
        User::factory()->agent([$this->billing->id])->create(['last_assigned_at' => $this->now->subDay()]);
        return [$this->billing, $first];
    }],
    'no eligible agent' => [function (): array {
        User::factory()->agent([$this->technical->id])->create();
        return [$this->billing, null];
    }],
]);

it('selects the agent required by TD-050', function (Closure $scenario): void {
    [$category, $expected] = $scenario->call($this);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $category->id, 'assigned_agent_id' => null]);
    expect(app(AgentSelector::class)->selectFor($ticket)?->id)->toBe($expected?->id);
})->with('selection rules');

it('locks the candidate agents FOR UPDATE so concurrent auto-assignments serialize', function (): void {
    $light = User::factory()->agent([$this->billing->id])->create();
    loadAgent(User::factory()->agent([$this->billing->id])->create(), 2);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->billing->id, 'assigned_agent_id' => null]);
    DB::enableQueryLog();
    $selected = DB::transaction(fn () => app(AgentSelector::class)->selectFor($ticket, true));
    $sql = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    DB::disableQueryLog();
    expect($selected?->id)->toBe($light->id)
        ->and($sql)->toContain('for update');
});
```

**7. Create `app/Services/Assignment/AgentSelector.php`** (TD-050, TD-052). With `$lock = true` it first locks the candidate rows in id order inside the caller's transaction, so two auto-assign jobs serialize and never deadlock.

```php
namespace App\Services\Assignment;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AgentSelector
{
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
            ->orderByRaw('users.last_assigned_at IS NOT NULL')
            ->orderBy('users.last_assigned_at')
            ->orderBy('users.id')
            ->first();
    }
}
```

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Assignment/AgentSelectorTest.php`. Expected: 10 passed.

**8. Write the failing manual-assignment test** `tests/Feature/Assignment/AssignTicketTest.php`:

```php
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

it('lets an agent hand off their own ticket', function (): void {
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

it('is a no-op when the agent already owns the ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();
    app(AssignTicket::class)->handle($this->admin, $ticket, $this->agent);
    Event::assertNotDispatched(TicketAssigned::class);
});
```

**9. Create `app/Actions/Assignment/AssignTicket.php`.** Manual assignment overrides an existing owner on purpose (TD-051 trade-offs), so it reads under `lockForUpdate` instead of using the `IS NULL` predicate. Authorization is the policy's job at the HTTP layer; the Action enforces only the target rule.

```php
namespace App\Actions\Assignment;

use App\Enums\AssignmentMethod;
use App\Events\TicketAssigned;
use App\Exceptions\Domain\AgentNotEligible;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AssignTicket
{
    public function handle(User $actor, Ticket $ticket, User $agent): Ticket
    {
        $now = CarbonImmutable::now();
        return DB::transaction(function () use ($actor, $ticket, $agent, $now): Ticket {
            if (! User::query()->availableAgents()->whereKey($agent->getKey())->exists()) {
                throw new AgentNotEligible(sprintf('%s is not an available agent.', $agent->name));
            }
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
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

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Assignment/AssignTicketTest.php`. Expected: 7 passed.

**10. Write the failing auto-assignment tests.** `tests/Feature/Assignment/AutoAssignTicketTest.php`:

```php
beforeEach(function (): void {
    config()->set('tickets.auto_assign', true);
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->category = Category::factory()->create();
});

function runAutoAssign(int $ticketId): void
{
    app()->call([new AutoAssignTicket($ticketId), 'handle']);
}

it('assigns the least-loaded available agent of the category', function (): void {
    $busy = User::factory()->agent([$this->category->id])->create();
    Ticket::factory()->count(2)->status(TicketStatus::InProgress)->assignedTo($busy)->create();
    $free = User::factory()->agent([$this->category->id])->create(['last_assigned_at' => null]);
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);
    runAutoAssign($ticket->id);
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
    runAutoAssign($ticket->id);
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
    expect(app(AssignTicketAutomatically::class)->handle($stale))->toBeNull()
        ->and($ticket->fresh()?->assigned_agent_id)->toBe($claimer->id);
    Event::assertNotDispatched(TicketAssigned::class);
});

it('skips tickets that are already assigned, finished or deleted', function (): void {
    Event::fake([TicketAssigned::class]);
    User::factory()->agent([$this->category->id])->create();
    $owner = User::factory()->agent()->create();
    $assigned = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($owner)->create(['category_id' => $this->category->id]);
    $resolved = Ticket::factory()->status(TicketStatus::Resolved)->create(['category_id' => $this->category->id, 'assigned_agent_id' => null]);
    runAutoAssign($assigned->id);
    runAutoAssign($resolved->id);
    runAutoAssign(999_999);
    expect($assigned->fresh()?->assigned_agent_id)->toBe($owner->id)
        ->and($resolved->fresh()?->assigned_agent_id)->toBeNull();
    Event::assertNotDispatched(TicketAssigned::class);
});
```

`tests/Feature/Assignment/QueueAutoAssignmentTest.php`:

```php
beforeEach(function (): void {
    Queue::fake();
    config()->set('tickets.auto_assign', true);
});

it('queues auto-assignment on the default queue after CreateTicket commits', function (): void {
    $ticket = app(CreateTicket::class)->handle(User::factory()->customer()->create(), [
        'subject' => 'Printer',
        'description' => 'It jams.',
        'priority' => 'low',
        'category_id' => Category::factory()->create()->id,
    ]);
    Queue::assertPushed(AutoAssignTicket::class, 1);
    Queue::assertPushedOn('default', AutoAssignTicket::class, fn (AutoAssignTicket $job): bool => $job->ticketId === $ticket->id);
});

it('does not queue auto-assignment', function (bool $imported, bool $enabled): void {
    config()->set('tickets.auto_assign', $enabled);
    $ticket = Ticket::factory()->create(['assigned_agent_id' => null]);
    TicketCreated::dispatch($ticket, null, $imported);
    Queue::assertNotPushed(AutoAssignTicket::class);
})->with([
    'for imported tickets' => [true, true],
    'when switched off' => [false, false],
]);
```

**11. Create `app/Actions/Assignment/AssignTicketAutomatically.php`.** One transaction: lock the candidates, pick one, then the TD-051 UPDATE. Zero affected rows means a human claimed it first, which is a valid outcome (TD-052).

```php
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
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($ticket, $now, $before): ?User {
            $agent = $this->selector->selectFor($ticket, lock: true);
            if ($agent === null) {
                Log::info('Auto-assignment found no eligible agent; the ticket stays in the unassigned queue.', [
                    'ticket' => $ticket->number,
                    'category_id' => $ticket->category_id,
                ]);
                return null;
            }
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

**12. Create `app/Jobs/AutoAssignTicket.php`** (TD-072). It carries only the id and re-reads the ticket, so a claim made while the job waited is seen.

```php
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

**13. Create `app/Listeners/QueueAutoAssignment.php`** (auto-discovered). Imported tickets are historical data and are skipped (TD-090).

```php
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

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Assignment/AutoAssignTicketTest.php tests/Feature/Assignment/QueueAutoAssignmentTest.php`. Expected: 7 passed. `php artisan event:list --event=TicketCreated` lists `QueueAutoAssignment@handle`.

**14. Write the failing endpoint test** `tests/Feature/Http/AssignmentEndpointsTest.php`. It covers the HTTP mapping only; the rules live in the Action tests and authorization in P3.4's matrix.

```php
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));
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
    $this->show = '/tickets/'.$this->ticket->number;
});

it('claims through the web and flashes success', function (): void {
    $this->actingAs($this->agent)
        ->from($this->show)
        ->post($this->show.'/claim')
        ->assertRedirect($this->show)
        ->assertSessionHas('success');
    expect($this->ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
});

it('claims through the API and returns the ticket resource', function (): void {
    Sanctum::actingAs($this->agent);
    $this->postJson('/api/v1'.$this->show.'/claim')
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.status.value', 'in_progress');
});

it('returns 409 ticket_already_claimed when the API claim loses', function (): void {
    $this->ticket->forceFill(['assigned_agent_id' => $this->colleague->id])->save();
    Sanctum::actingAs($this->agent);
    $this->postJson('/api/v1'.$this->show.'/claim')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ticket_already_claimed');
});

it('lets an admin assign through the API without changing the status', function (): void {
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1'.$this->show.'/assign', ['agent_id' => $this->agent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.status.value', 'open');
});

it('returns 422 agent_not_eligible for a customer target', function (): void {
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1'.$this->show.'/assign', ['agent_id' => $this->customer->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'agent_not_eligible');
});

```

**15. Create `app/Http/Requests/Assignment/AssignTicketRequest.php`** (`final`, extends `FormRequest`):
- `authorize(): Response` returns `Gate::forUser($this->user())->inspect('assign', $this->ticket())`. `TicketPolicy::assign` already answers 404 for an unseen ticket.
- `rules()`: `'agent_id' => ['required', 'integer', Rule::exists('users', 'id')]`.
- `targetAgent(): User` returns `User::query()->findOrFail((int) $this->validated('agent_id'))`.
- `ticket(): Ticket` returns the bound `{ticket}` route parameter, throwing `LogicException` if it isn't a `Ticket` (same shape as T05's `ChangeTicketStatusRequest::ticket()`).

**16. Create the two controllers** (`final`, extend `App\Http\Controllers\Controller`, with T05's private `user(Request): User` helper that asserts `$request->user() instanceof User`). There is no claim FormRequest: claim has no payload, so the controller authorizes it with `Gate::authorize('claim', $ticket)`.

`app/Http/Controllers/Web/AssignmentController.php`:

```php
    public function claim(Request $request, Ticket $ticket, ClaimTicket $claimTicket): RedirectResponse
    {
        Gate::authorize('claim', $ticket);
        $claimTicket->handle($this->user($request), $ticket);
        return back()->with('success', "You claimed {$ticket->number}.");
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
        $agent = $request->targetAgent();
        $assignTicket->handle($this->user($request), $ticket, $agent);
        return back()->with('success', "{$ticket->number} assigned to {$agent->name}.");
    }
```

`app/Http/Controllers/Api/V1/AssignmentController.php` injects `TicketPageData $pages` in its constructor:

```php
    public function claim(Request $request, Ticket $ticket, ClaimTicket $claimTicket): TicketResource
    {
        Gate::authorize('claim', $ticket);
        return TicketResource::make($this->pages->loadSummary($claimTicket->handle($this->user($request), $ticket)));
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): TicketResource
    {
        $ticket = $assignTicket->handle($this->user($request), $ticket, $request->targetAgent());
        return TicketResource::make($this->pages->loadSummary($ticket));
    }
```

**17. Create the route files**, following `routes/web/tickets.php` and `routes/api/v1/tickets.php`:
- `routes/web/assignment.php`, inside `Route::middleware(['auth'])->group(...)`: `Route::post('tickets/{ticket}/claim', [AssignmentController::class, 'claim'])->name('tickets.claim')` and `Route::post('tickets/{ticket}/assign', [AssignmentController::class, 'assign'])->name('tickets.assign')`, using `App\Http\Controllers\Web\AssignmentController`.
- `routes/api/v1/assignment.php`, inside `Route::middleware('auth:sanctum')->group(...)`: the same two lines with `App\Http\Controllers\Api\V1\AssignmentController` (the loader adds the `api.v1.` name prefix).

**18. Run all of this subtask's tests:**

```bash
DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Assignment tests/Feature/Http/AssignmentEndpointsTest.php tests/Concurrency/ClaimTicketConcurrencyTest.php
./vendor/bin/pint config/tickets.php app/Services/Assignment app/Actions/Assignment app/Jobs app/Listeners/QueueAutoAssignment.php app/Http/Requests/Assignment app/Http/Controllers/Web/AssignmentController.php app/Http/Controllers/Api/V1/AssignmentController.php routes/web/assignment.php routes/api/v1/assignment.php tests/Feature/Assignment tests/Feature/Http/AssignmentEndpointsTest.php tests/Concurrency/ClaimTicketConcurrencyTest.php
```
Expected: 33 passed.

**Proposed commit:**
```
feat(assignment): add claim, manual assignment and auto-assignment

Refs: P3.1
Implements: TD-032, TD-050, TD-051, TD-052, TD-053, TD-072, TD-110, TD-130
```

---

## P3.2 — Conversation and attachments

**Files:**
- Create:
  - `app/Actions/Conversation/AddReply.php`
  - `app/Http/Requests/Conversation/StoreReplyRequest.php`
  - `app/Http/Requests/Conversation/IndexRepliesRequest.php`
  - `app/Services/Conversation/ConversationQuery.php`
  - `app/Http/Resources/V1/TicketReplyResource.php`
  - `app/Http/Controllers/Web/TicketReplyController.php`
  - `app/Http/Controllers/Api/V1/TicketReplyController.php`
  - `app/Http/Controllers/AttachmentDownloadController.php`
  - `routes/web/conversation.php`, `routes/api/v1/conversation.php`
  - `tests/Feature/Conversation/AddReplyTest.php`
  - `tests/Feature/Conversation/ReplyEndpointsTest.php`
  - `tests/Feature/Conversation/AttachmentDownloadTest.php`

**Consumes:**
- `TicketPolicy::reply` (permission + not Closed, 403 on Closed), `addInternalNote`, `viewInternal`, `view` (404 for an unseen ticket), `TicketReplyPolicy::view`, `AttachmentPolicy::view` (404 for an unseen ticket or an internal attachment the viewer can't see).
- `ChangeTicketStatus::handle(?User, Ticket, TicketStatus)` (resumes the SLA when leaving `waiting_for_customer`, TD-042).
- `AttachmentStorer::storeMany(Ticket, ?TicketReply, User, list<UploadedFile>, bool $isInternal)` (stores `attachments/{ticket_id}/{uuid}.{ext}` and cleans up its own partial writes) and `deleteFiles(Collection)`.
- `TicketReplied(Ticket, TicketReply, User)`; `RecordTicketActivity` logs it as `replied` or `internal_note` (staff-only).
- `TicketClosed` (422 `ticket_closed`), `AttachmentResource` (its `download_url` is `/attachments/{id}` or `/api/v1/attachments/{id}`), `UserSummaryResource`.
- `config('attachments.disk|extensions|max_kb|max_files')`, `config('tickets.per_page.default|max')`.

**Produces:**
```php
App\Actions\Conversation\AddReply::handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply
App\Http\Resources\V1\TicketReplyResource
App\Services\Conversation\ConversationQuery::paginateFor(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
```
`TicketReplyResource` emits `{id, body, is_internal, author: {id, name}, attachments: AttachmentResource[], created_at}`, the same shape as T05's `ReplyItem`. For an internal reply it throws `AuthorizationException` unless the request user may `view` it (the second leakage layer, TD-120). Callers set the `ticket` relation on each reply so that check never lazy-loads.

| Route name | Method + URI | Payload | Success |
|---|---|---|---|
| `tickets.replies.store` | POST `/tickets/{ticket}/replies` (`throttle:uploads`) | multipart `body`, `is_internal?`, `attachments[]?` | redirect to `tickets.show`, flash `success` |
| `attachments.download` | GET `/attachments/{attachment}` | — | streamed file |
| `api.v1.tickets.replies.index` | GET `/api/v1/tickets/{ticket}/replies` | `per_page?`, `page?` | paginated `TicketReplyResource`, oldest first |
| `api.v1.tickets.replies.store` | POST `/api/v1/tickets/{ticket}/replies` (`throttle:uploads`) | same as web | 201 `TicketReplyResource` |
| `api.v1.attachments.download` | GET `/api/v1/attachments/{attachment}` | — | streamed file |

Downloads respond with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. A Closed ticket answers 403 at the policy; a ticket closed between authorization and the locked re-read answers 422 `ticket_closed`.

There is no `ResumeSlaOnCustomerReply` listener: `AddReply` calls `ChangeTicketStatus(null, $ticket, InProgress)` inside its transaction.

### Steps

**1. Write the failing Action test** `tests/Feature/Conversation/AddReplyTest.php`:

```php
beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
});

it('stores a public staff reply with its attachments and fires TicketReplied', function (): void {
    Event::fake([TicketReplied::class]);
    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'See attached.', false, [
        UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        UploadedFile::fake()->image('screenshot.png'),
    ]);
    expect($reply->is_internal)->toBeFalse()
        ->and($reply->author_id)->toBe($this->agent->id)
        ->and(Attachment::query()->where('reply_id', $reply->id)->where('is_internal', false)->count())->toBe(2);
    Event::assertDispatched(TicketReplied::class, fn (TicketReplied $event): bool => $event->reply->is($reply)
        && $event->ticket->is($this->ticket)
        && $event->author->is($this->agent));
});

it('stores an internal note whose attachments are internal too', function (): void {
    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'Legacy plan.', true, [
        UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
    ]);
    expect($reply->is_internal)->toBeTrue()
        ->and(Attachment::query()->where('reply_id', $reply->id)->sole()->is_internal)->toBeTrue();
});

it('forces is_internal to false when a customer asks for an internal note', function (): void {
    $reply = app(AddReply::class)->handle($this->customer, $this->ticket, 'Let me sneak a note in.', true, [
        UploadedFile::fake()->create('mine.pdf', 2, 'application/pdf'),
    ]);
    expect($reply->is_internal)->toBeFalse()
        ->and(TicketReply::query()->where('is_internal', true)->exists())->toBeFalse()
        ->and(Attachment::query()->where('is_internal', true)->exists())->toBeFalse();
});

it('rejects replies on a closed ticket', function (User $author): void {
    $closed = Ticket::factory()->status(TicketStatus::Closed)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    expect(fn () => app(AddReply::class)->handle($author, $closed, 'Hello?', false))
        ->toThrow(TicketClosed::class);
    expect(TicketReply::query()->where('ticket_id', $closed->id)->exists())->toBeFalse();
})->with([
    'customer' => [fn (): User => $this->customer],
    'agent' => [fn (): User => $this->agent],
]);

it('deletes the stored files when the transaction fails after storing them', function (): void {
    Ticket::updating(function (): void {
        throw new RuntimeException('simulated failure');
    });
    expect(fn () => app(AddReply::class)->handle($this->agent, $this->ticket, 'Will roll back.', false, [
        UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'simulated failure');
    expect(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty()
        ->and(TicketReply::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('moves a waiting ticket to in progress on a customer reply and resumes the SLA', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'sla_started_at' => CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'),
        'sla_warning_at' => CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'),
        'sla_due_at' => CarbonImmutable::parse('2026-11-01 11:00:00', 'UTC'),
        'sla_paused_at' => null,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 04:00:00', 'UTC'));
    app(ChangeTicketStatus::class)->handle($this->agent, $ticket, TicketStatus::WaitingForCustomer);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    app(AddReply::class)->handle($this->customer, $ticket->fresh(), 'Here is the info.', false);
    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->sla_due_at->utc()->toDateTimeString())->toBe('2026-11-02 11:00:00');
});

it('keeps a waiting ticket waiting when staff reply', function (): void {
    $ticket = app(ChangeTicketStatus::class)->handle($this->agent, $this->ticket, TicketStatus::WaitingForCustomer);
    app(AddReply::class)->handle($this->agent, $ticket->fresh(), 'Any update?', false);
    expect($ticket->fresh()?->status)->toBe(TicketStatus::WaitingForCustomer);
});
```

**2. Create `app/Actions/Conversation/AddReply.php`.** It forces `is_internal` to false for anyone who can't add internal notes (TD-060), re-reads the ticket under `lockForUpdate` so a concurrent close can't slip between the policy check and the insert, stores files inside the transaction and deletes them if anything later fails (TD-053), and touches the ticket so "recently updated" reflects conversation activity.

```php
namespace App\Actions\Conversation;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Attachments\AttachmentStorer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AddReply
{
    public function __construct(
        private readonly AttachmentStorer $attachments,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketClosed
     */
    public function handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply
    {
        $isInternal = $isInternal && $author->can('addInternalNote', $ticket);
        /** @var Collection<int, Attachment> $stored */
        $stored = new Collection;
        try {
            return DB::transaction(function () use ($author, $ticket, $body, $isInternal, $files, &$stored): TicketReply {
                $current = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
                if ($current->status === TicketStatus::Closed) {
                    throw new TicketClosed(sprintf('Ticket %s is closed and no longer accepts replies.', $current->number));
                }
                $reply = new TicketReply(['body' => $body, 'is_internal' => $isInternal]);
                $reply->ticket()->associate($current);
                $reply->author()->associate($author);
                $reply->save();
                $stored = $this->attachments->storeMany($current, $reply, $author, $files, $isInternal);
                if (! $isInternal
                    && $author->id === $current->customer_id
                    && $current->status === TicketStatus::WaitingForCustomer) {
                    $current = $this->changeStatus->handle(null, $current, TicketStatus::InProgress);
                }
                $current->touch();
                TicketReplied::dispatch($current, $reply, $author);
                return $reply;
            });
        } catch (Throwable $exception) {
            $this->attachments->deleteFiles($stored);
            throw $exception;
        }
    }
}
```

Run: `DB_DATABASE=techzu_testing_agent2 ./vendor/bin/pest tests/Feature/Conversation/AddReplyTest.php`. Expected: 8 passed.

**3. Write the failing endpoint test** `tests/Feature/Conversation/ReplyEndpointsTest.php`:

```php
beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
});

it('stores a web reply and redirects to the ticket', function (): void {
    $this->actingAs($this->agent)
        ->post(route('tickets.replies.store', $this->ticket), ['body' => 'Hello from the web.'])
        ->assertRedirect(route('tickets.show', $this->ticket))
        ->assertSessionHas('success');
    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->sole()->body)->toBe('Hello from the web.');
});

it('stores an API reply and returns 201 with the reply resource', function (): void {
    Sanctum::actingAs($this->agent, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'Hello from the API.',
        'is_internal' => true,
        'attachments' => [UploadedFile::fake()->create('trace.txt', 2, 'text/plain')],
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Hello from the API.')
        ->assertJsonPath('data.is_internal', true)
        ->assertJsonPath('data.author.id', $this->agent->id)
        ->assertJsonPath('data.attachments.0.original_name', 'trace.txt');
});

it('returns 422 ticket_closed when the ticket closes between authorization and the write', function (): void {
    app()->resolving(AddReply::class, function (): void {
        DB::table('tickets')->where('id', $this->ticket->id)->update(['status' => TicketStatus::Closed->value]);
    });
    Sanctum::actingAs($this->agent, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), ['body' => 'Racing the close'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ticket_closed');
    expect(TicketReply::query()->where('ticket_id', $this->ticket->id)->exists())->toBeFalse();
});

it('rejects disallowed or oversized files without storing anything', function (UploadedFile $file): void {
    Sanctum::actingAs($this->agent, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $this->ticket), [
        'body' => 'With a bad file',
        'attachments' => [$file],
    ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
    expect(TicketReply::query()->count())->toBe(0)
        ->and(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty();
})->with([
    'windows executable' => [fn (): UploadedFile => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')],
    'php script' => [fn (): UploadedFile => UploadedFile::fake()->create('shell.php', 1, 'application/x-php')],
    'executable content behind a .png name' => [fn (): UploadedFile => UploadedFile::fake()->create('photo.png', 10, 'application/x-msdownload')],
    'pdf content behind an .exe name' => [fn (): UploadedFile => UploadedFile::fake()->create('invoice.exe', 10, 'application/pdf')],
    'one kilobyte over the limit' => [fn (): UploadedFile => UploadedFile::fake()->create('big.pdf', (int) config('attachments.max_kb') + 1, 'application/pdf')],
]);

it('lists replies oldest first with pagination meta', function (): void {
    TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->create(['body' => 'first', 'created_at' => now()->subHour()]);
    TicketReply::factory()->for($this->ticket)->for($this->customer, 'author')->create(['body' => 'second', 'created_at' => now()]);
    Sanctum::actingAs($this->agent, ['*']);
    $this->getJson(route('api.v1.tickets.replies.index', ['ticket' => $this->ticket, 'per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('data.0.body', 'first')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);
});
```

**4. Create the two FormRequests** in `app/Http/Requests/Conversation/` (`final`, extend `FormRequest`). Both have `ticket(): Ticket` (the bound `{ticket}`, asserted) and `actor(): User` (the request user, asserted).

`StoreReplyRequest` authorizes with `Gate::forUser($this->user())->inspect('reply', $this->ticket())` (404 for an unseen ticket, 403 on Closed). Both the sniffed MIME type (`types()`) and the client extension (`extensions()`) must be on the allowlist (TD-061):

```php
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('attachments.extensions', []);
        return [
            'body' => ['required', 'string', 'max:20000'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('attachments.max_files', 5)],
            'attachments.*' => [
                'required',
                File::types($extensions)->extensions($extensions)->max((int) config('attachments.max_kb', 10240)),
            ],
        ];
    }

    /** @return list<UploadedFile> */
    public function attachmentFiles(): array
    {
        return array_values(array_filter(
            Arr::wrap($this->file('attachments', [])),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }
```

It also has `replyBody(): string` (`$this->string('body')->toString()`) and `wantsInternal(): bool` (`$this->boolean('is_internal')`).

`IndexRepliesRequest` authorizes with `inspect('view', $this->ticket())`. Its rules are `per_page` (`sometimes`, integer, `min:1`, `max:` `config('tickets.per_page.max', 100)`) and `page` (`sometimes`, integer, `min:1`). `perPage(): int` returns `$this->integer('per_page', (int) config('tickets.per_page.default', 20))`.

**5. Create `app/Services/Conversation/ConversationQuery.php`.** It applies `visibleTo`, filters internal attachments for viewers who can't see them, loads column-limited relations, and sets the `ticket` relation on each reply so the resource's policy check never lazy-loads.

```php
namespace App\Services\Conversation;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConversationQuery
{
    /** @return LengthAwarePaginator<int, TicketReply> */
    public function paginateFor(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
    {
        $seesInternal = $viewer->can('viewInternal', $ticket);
        $paginator = $ticket->replies()
            ->visibleTo($viewer)
            ->with([
                'author:id,name',
                'attachments' => static function (HasMany $query) use ($seesInternal): void {
                    $query->select(['id', 'ticket_id', 'reply_id', 'original_name', 'mime_type', 'size_bytes', 'is_internal', 'created_at'])
                        ->orderBy('id');
                    if (! $seesInternal) {
                        $query->where('is_internal', false);
                    }
                },
            ])
            ->oldest()
            ->oldest('id')
            ->paginate(max(1, min($perPage, (int) config('tickets.per_page.max', 100))))
            ->withQueryString();
        $paginator->getCollection()->each(static fn (TicketReply $reply): TicketReply => $reply->setRelation('ticket', $ticket));
        return $paginator;
    }
}
```

**6. Create `app/Http/Resources/V1/TicketReplyResource.php`:**

```php
namespace App\Http\Resources\V1;

use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketReply */
final class TicketReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        if ($this->is_internal && (! $viewer instanceof User || $viewer->cannot('view', $this->resource))) {
            throw new AuthorizationException('This reply is not visible to you.');
        }
        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'author' => $this->whenLoaded('author', fn (User $author): array => UserSummaryResource::make($author)->resolve($request)),
            'attachments' => $this->whenLoaded('attachments', fn (Collection $attachments): array => AttachmentResource::collection($attachments)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**7. Create the two reply controllers** (`final`, extend `Controller`).

`app/Http/Controllers/Web/TicketReplyController.php`:

```php
    public function store(StoreReplyRequest $request, AddReply $addReply): RedirectResponse
    {
        $ticket = $request->ticket();
        $reply = $addReply->handle($request->actor(), $ticket, $request->replyBody(), $request->wantsInternal(), $request->attachmentFiles());
        return to_route('tickets.show', $ticket)
            ->with('success', $reply->is_internal ? 'Internal note added.' : 'Reply sent.');
    }
```

`app/Http/Controllers/Api/V1/TicketReplyController.php`:

```php
    public function index(IndexRepliesRequest $request, ConversationQuery $conversation): AnonymousResourceCollection
    {
        return TicketReplyResource::collection(
            $conversation->paginateFor($request->ticket(), $request->actor(), $request->perPage()),
        );
    }

    public function store(StoreReplyRequest $request, AddReply $addReply): JsonResponse
    {
        $reply = $addReply->handle($request->actor(), $request->ticket(), $request->replyBody(), $request->wantsInternal(), $request->attachmentFiles());
        $reply->load([
            'author:id,name',
            'attachments:id,ticket_id,reply_id,original_name,mime_type,size_bytes,is_internal,created_at',
        ]);
        return TicketReplyResource::make($reply)->response()->setStatusCode(201);
    }
```

`AddReply` associated the locked ticket with the reply, so the resource's policy check finds `reply->ticket` already set.

**8. Write the failing download test** `tests/Feature/Conversation/AttachmentDownloadTest.php`. Who may download what is covered by P3.4's matrix; this test proves the response is a safe streamed download.

```php
it('streams an attachment as a download with nosniff', function (): void {
    Storage::fake(config('attachments.disk'));
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create([
        'customer_id' => $customer->id,
        'created_by_id' => $customer->id,
    ]);
    $reply = app(AddReply::class)->handle($agent, $ticket, 'Public', false, [
        UploadedFile::fake()->create('report.pdf', 4, 'application/pdf'),
    ]);
    $attachment = Attachment::query()->where('reply_id', $reply->id)->sole();
    $response = $this->actingAs($customer)->get(route('attachments.download', $attachment));
    $response->assertOk()
        ->assertDownload('report.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});
```

**9. Create `app/Http/Controllers/AttachmentDownloadController.php`.** Web and API share this one invokable controller, because the response is identical. The attachment disposition plus `nosniff` stops the browser rendering user content inline (TD-061).

```php
namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
```

**10. Create the route files**, following `routes/web/tickets.php` and `routes/api/v1/tickets.php`. Reply posts accept uploads, so they use the existing named limiter `throttle:uploads` (AppServiceProvider), never an inline limit.
- `routes/web/conversation.php`, inside `Route::middleware('auth')->group(...)`:
  - `Route::post('tickets/{ticket}/replies', [Web\TicketReplyController::class, 'store'])->middleware('throttle:uploads')->name('tickets.replies.store')`
  - `Route::get('attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download')`
- `routes/api/v1/conversation.php`, inside `Route::middleware('auth:sanctum')->group(...)`:
  - `Route::get('tickets/{ticket}/replies', [Api\V1\TicketReplyController::class, 'index'])->name('tickets.replies.index')`
  - `Route::post('tickets/{ticket}/replies', [Api\V1\TicketReplyController::class, 'store'])->middleware('throttle:uploads')->name('tickets.replies.store')`
  - `Route::get('attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download')`

**11. Run all of this subtask's tests:**

```bash
DB_DATABASE=techzu_testing_agent2 ./vendor/bin/pest tests/Feature/Conversation
./vendor/bin/pint app/Actions/Conversation app/Http/Requests/Conversation app/Services/Conversation app/Http/Resources/V1/TicketReplyResource.php app/Http/Controllers/Web/TicketReplyController.php app/Http/Controllers/Api/V1/TicketReplyController.php app/Http/Controllers/AttachmentDownloadController.php routes/web/conversation.php routes/api/v1/conversation.php tests/Feature/Conversation
```
Expected: 18 passed. `php artisan route:list --name=replies` shows the three reply routes; `--name=attachments` shows both downloads.

**Proposed commit:**
```
feat(conversation): add replies, internal notes and secure downloads

Refs: P3.2
Implements: TD-024, TD-042, TD-053, TD-060, TD-061, TD-110, TD-120
```

---

## P3.3 — Search and filters

**Files:**
- Create:
  - `app/Services/Tickets/FulltextSearchTerm.php`
  - `app/Services/Tickets/TicketFilter.php`
  - `tests/Unit/Tickets/FulltextSearchTermTest.php`
  - `tests/Feature/Tickets/TicketFilterTest.php`
  - `tests/Feature/Tickets/TicketIndexFilteringTest.php`
  - `tests/Database/TicketKeywordSearchTest.php`
- Modify:
  - `app/Services/Tickets/TicketListQuery.php`: constructor gains `TicketFilter`, and `applyFilters()` delegates to it. Nothing else.
  - `app/Http/Requests/Tickets/IndexTicketsRequest.php`: `rules()` only.
  - `app/Services/Tickets/TicketPageData.php`: add `filterOptions()`. Existing methods are unchanged.
  - `app/Http/Controllers/Web/TicketController.php`: one line in `index()`.
  - `tests/Feature/Performance/TicketQueryCountTest.php`: the web index ceiling goes from 12 to 14, because `filterOptions` adds exactly two constant queries (categories and agents). The constancy assertion stays.

**Consumes:**
- T05's seam: `TicketListQuery::paginate(User, array $validated, CarbonImmutable $now)` already applies `visibleTo`, the scope's statuses, the column list, the eager loads, the visible-only `withCount('replies')` and the `per_page` clamp, then calls `applyFilters()`.
- `SlaQuery::whereState(Builder, SlaState, CarbonImmutable)`.
- `TicketPolicy::createOnBehalf` as the "staff" signal for the people filters (no role checks).
- `config('business_hours.timezone')` for date boundaries.

**Produces:**
```php
App\Services\Tickets\TicketFilter::apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder
App\Services\Tickets\TicketFilter::SORTS
App\Services\Tickets\TicketFilter::DEFAULT_SORT
App\Services\Tickets\FulltextSearchTerm::toBooleanMode(string $input): string
App\Services\Tickets\TicketPageData::filterOptions(User $viewer, string $scope): array
```
`SORTS` is `array<string, string>` (sort value to label); `DEFAULT_SORT` is `'-created_at'`.
Web `tickets/Index` gains the prop `filterOptions` (shape in "Produces for P5").

Query parameters, the same for `GET /tickets` and `GET /api/v1/tickets`:

| Param | Rule | Behaviour | Index (TD-021) |
|---|---|---|---|
| `scope`, `page`, `per_page` | unchanged (T05) | applied by `TicketListQuery` | — |
| `number` | string ≤ 32 | upper-cased prefix match, LIKE-escaped | `number` unique |
| `q` | string ≤ 200 | MySQL: `MATCH(subject, description) AGAINST(? IN BOOLEAN MODE)` on a sanitized term; other drivers: escaped `LIKE` | FULLTEXT |
| `status[]` | each `TicketStatus` | `whereIn`, narrowing inside the scope | `(status, …)` |
| `priority[]` | each `TicketPriority` | `whereIn` | `(priority, status)` |
| `category_id` | int, exists | equals | `(category_id, status)` |
| `assigned_agent_id` | int ≥ 1, no `exists` | equals | `(assigned_agent_id, status)` |
| `unassigned` | boolean | true → `assigned_agent_id IS NULL` | `(assigned_agent_id, status)` |
| `customer_id` | int ≥ 1, no `exists` | equals, after visibility | `(customer_id, created_at)` |
| `created_from` / `created_to` | `Y-m-d`, to ≥ from | whole days in the business timezone | `(created_at)` |
| `sla_state` | `SlaState` | `SlaQuery::whereState` | `(status, sla_due_at)`, `(status, sla_warning_at)` |
| `sort` | a key of `TicketFilter::SORTS` | default `-created_at`; `priority` by `rank()`; `id` breaks ties | `(created_at)`, `(updated_at)` from P1 |

`customer_id` and `assigned_agent_id` have no `exists` rule, so a customer can't probe which user ids exist. `status[]` narrows within the scope, so `scope=active&status[]=closed` returns an empty page, not an error.

### Steps

**1. Write the failing sanitizer test** `tests/Unit/Tickets/FulltextSearchTermTest.php`:

```php
it('turns user input into a safe boolean-mode expression', function (string $input, string $expected): void {
    expect(FulltextSearchTerm::toBooleanMode($input))->toBe($expected);
})->with([
    'plain words get a prefix wildcard' => ['printer jam', 'printer* jam*'],
    'words under 3 characters get no wildcard' => ['pc on fire', 'pc on fire*'],
    'required and excluded operators are stripped' => ['+printer -jam', 'printer* jam*'],
    'grouping and phrase quotes are stripped' => ['("printer jam")', 'printer* jam*'],
    'relevance and negation operators are stripped' => ['>urgent <later ~maybe', 'urgent* later* maybe*'],
    'user wildcards and proximity are stripped' => ['print* "a b" @3', 'print* a b 3'],
    'operators glued inside a word split it' => ['e-mail', 'e mail*'],
    'only operators leaves nothing' => ['+-<>()~*"@', ''],
    'whitespace is collapsed' => ["  printer \t\n jam  ", 'printer* jam*'],
    'multibyte length counts characters' => ['café crème', 'café* crème*'],
]);

it('keeps at most ten terms', function (): void {
    $input = implode(' ', array_map(fn (int $i): string => "word{$i}", range(1, 12)));
    expect(explode(' ', FulltextSearchTerm::toBooleanMode($input)))->toHaveCount(10);
});
```

**2. Create `app/Services/Tickets/FulltextSearchTerm.php`.** User input must never carry boolean-mode semantics (TD-120); words shorter than `innodb_ft_min_token_size` get no wildcard because a short prefix matches almost everything, and the term count is capped to bound one MATCH.

```php
namespace App\Services\Tickets;

final class FulltextSearchTerm
{
    private const array OPERATORS = ['+', '-', '<', '>', '(', ')', '~', '*', '"', '@'];

    private const int MIN_PREFIX_LENGTH = 3;

    private const int MAX_TERMS = 10;

    public static function toBooleanMode(string $input): string
    {
        $words = preg_split('/\s+/u', trim(str_replace(self::OPERATORS, ' ', $input)), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }
        return implode(' ', array_map(
            static fn (string $word): string => mb_strlen($word) >= self::MIN_PREFIX_LENGTH ? $word.'*' : $word,
            array_slice($words, 0, self::MAX_TERMS),
        ));
    }
}
```

Run: `DB_DATABASE=techzu_testing_agent3 ./vendor/bin/pest tests/Unit/Tickets/FulltextSearchTermTest.php`. Expected: 11 passed.

**3. Write the failing filter test** `tests/Feature/Tickets/TicketFilterTest.php`. It calls `TicketFilter` directly with the raw strings `$request->validated()` produces.

```php
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->people = [
        'c1' => User::factory()->customer()->create(),
        'c2' => User::factory()->customer()->create(),
        'a1' => User::factory()->agent()->create(),
        'a2' => User::factory()->agent()->create(),
    ];
    $this->categories = ['cat1' => Category::factory()->create(), 'cat2' => Category::factory()->create()];
    $make = fn (string $number, TicketStatus $status, TicketPriority $priority, string $customer, string $category, ?string $agent, string $createdAt, string $updatedAt, string $dueAt): Ticket => Ticket::factory()
        ->status($status)
        ->priority($priority)
        ->create([
            'number' => $number,
            'customer_id' => $this->people[$customer]->id,
            'created_by_id' => $this->people[$customer]->id,
            'category_id' => $this->categories[$category]->id,
            'assigned_agent_id' => $agent === null ? null : $this->people[$agent]->id,
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
            'updated_at' => CarbonImmutable::parse($updatedAt, 'UTC'),
            'sla_due_at' => CarbonImmutable::parse($dueAt, 'UTC'),
            'sla_warning_at' => CarbonImmutable::parse($dueAt, 'UTC')->subHour(),
            'sla_paused_at' => null,
        ]);
    $this->tickets = [
        't1' => $make('TKT-2026-000101', TicketStatus::Open, TicketPriority::Low, 'c1', 'cat1', 'a1', '2026-10-01 06:00:00', '2026-10-28 06:00:00', '2026-11-04 06:00:00'),
        't2' => $make('TKT-2026-000102', TicketStatus::InProgress, TicketPriority::High, 'c1', 'cat2', null, '2026-10-10 06:00:00', '2026-10-05 06:00:00', '2026-11-01 06:00:00'),
        't3' => $make('TKT-2026-000103', TicketStatus::WaitingForCustomer, TicketPriority::Critical, 'c2', 'cat1', 'a2', '2026-10-20 06:00:00', '2026-10-21 06:00:00', '2026-11-02 06:00:00'),
        't4' => $make('TKT-2026-000104', TicketStatus::Resolved, TicketPriority::Medium, 'c2', 'cat2', 'a1', '2026-10-25 06:00:00', '2026-10-26 06:00:00', '2026-11-03 06:00:00'),
    ];
    $this->numbers = fn (array $keys): array => array_map(fn (string $key): string => $this->tickets[$key]->number, $keys);
    $this->run = fn (array $filters, ?User $viewer = null): Builder => app(TicketFilter::class)
        ->apply(Ticket::query(), $filters, $viewer ?? $this->admin, CarbonImmutable::now());
});

it('applies each filter on its own and in combination', function (array $filters, array $expected): void {
    expect(($this->run)($filters)->pluck('number')->sort()->values()->all())->toBe(($this->numbers)($expected));
})->with([
    'no filters' => [fn (): array => [], ['t1', 't2', 't3', 't4']],
    'exact number' => [fn (): array => ['number' => 'TKT-2026-000102'], ['t2']],
    'number prefix' => [fn (): array => ['number' => 'TKT-2026-00010'], ['t1', 't2', 't3', 't4']],
    'number is case-insensitive' => [fn (): array => ['number' => 'tkt-2026-000103'], ['t3']],
    'number LIKE metacharacters are literal' => [fn (): array => ['number' => 'TKT-2026-0001_1'], []],
    'status (multiple)' => [fn (): array => ['status' => ['open', 'in_progress']], ['t1', 't2']],
    'priority' => [fn (): array => ['priority' => ['critical']], ['t3']],
    'category id as a query-string string' => [fn (): array => ['category_id' => (string) $this->categories['cat1']->id], ['t1', 't3']],
    'assigned agent' => [fn (): array => ['assigned_agent_id' => (string) $this->people['a1']->id], ['t1', 't4']],
    'unassigned as "1"' => [fn (): array => ['unassigned' => '1'], ['t2']],
    'unassigned "0" is ignored' => [fn (): array => ['unassigned' => '0'], ['t1', 't2', 't3', 't4']],
    'customer' => [fn (): array => ['customer_id' => (string) $this->people['c2']->id], ['t3', 't4']],
    'created from' => [fn (): array => ['created_from' => '2026-10-10'], ['t2', 't3', 't4']],
    'created to' => [fn (): array => ['created_to' => '2026-10-10'], ['t1', 't2']],
    'created range' => [fn (): array => ['created_from' => '2026-10-02', 'created_to' => '2026-10-24'], ['t2', 't3']],
    'status + category' => [fn (): array => ['status' => ['open', 'in_progress'], 'category_id' => $this->categories['cat1']->id], ['t1']],
    'customer + status + date' => [fn (): array => ['customer_id' => $this->people['c1']->id, 'status' => ['in_progress'], 'created_from' => '2026-10-05'], ['t2']],
    'unassigned + agent never matches' => [fn (): array => ['unassigned' => '1', 'assigned_agent_id' => $this->people['a1']->id], []],
    'keyword of only operators is ignored' => [fn (): array => ['q' => '+-()"'], ['t1', 't2', 't3', 't4']],
]);

it('sorts by whitelisted columns with id as the tie-breaker', function (string $sort, array $expected): void {
    expect(($this->run)(['sort' => $sort])->pluck('number')->all())->toBe(($this->numbers)($expected));
})->with([
    'default is newest first' => ['-created_at', ['t4', 't3', 't2', 't1']],
    'created ascending' => ['created_at', ['t1', 't2', 't3', 't4']],
    'priority by rank descending' => ['-priority', ['t3', 't2', 't4', 't1']],
    'priority by rank ascending' => ['priority', ['t1', 't4', 't2', 't3']],
    'due soonest first' => ['sla_due_at', ['t2', 't3', 't4', 't1']],
    'recently updated first' => ['-updated_at', ['t1', 't4', 't3', 't2']],
]);

it('treats created dates as whole days in the business timezone', function (): void {
    $late = Ticket::factory()->create([
        'number' => 'TKT-2026-000199',
        'created_at' => CarbonImmutable::parse('2026-10-09 19:00:00', 'UTC'),
    ]);
    expect(($this->run)(['created_from' => '2026-10-10', 'created_to' => '2026-10-10'])->pluck('number')->all())
        ->toContain($late->number)
        ->and(($this->run)(['created_to' => '2026-10-09'])->pluck('number')->all())
        ->not->toContain($late->number);
});

it('applies visibility before every other filter', function (): void {
    $customer = $this->people['c1'];
    expect(($this->run)(['customer_id' => (string) $this->people['c2']->id], $customer)->count())->toBe(0)
        ->and(($this->run)([], $customer)->pluck('number')->sort()->values()->all())->toBe(($this->numbers)(['t1', 't2']));
});
```

**4. Create `app/Services/Tickets/TicketFilter.php`.** It applies `visibleTo` first, so no later filter can widen what a customer sees (TD-012); this repeats `TicketListQuery`'s scope harmlessly and keeps the class safe on its own. Sorting is a whitelist with bound CASE values for priority (TD-120), and `id` keeps offset pagination stable.

```php
namespace App\Services\Tickets;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class TicketFilter
{
    public const array SORTS = [
        '-created_at' => 'Newest first',
        'created_at' => 'Oldest first',
        '-updated_at' => 'Recently updated',
        'updated_at' => 'Least recently updated',
        'sla_due_at' => 'Due soonest',
        '-sla_due_at' => 'Due latest',
        '-priority' => 'Highest priority',
        'priority' => 'Lowest priority',
    ];

    public const string DEFAULT_SORT = '-created_at';

    private const array FULLTEXT_DRIVERS = ['mysql', 'mariadb'];

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder
    {
        $query->visibleTo($viewer);
        $this->number($query, $filters['number'] ?? null);
        $this->keyword($query, $filters['q'] ?? null);
        $this->whereInList($query, 'tickets.status', $filters['status'] ?? null);
        $this->whereInList($query, 'tickets.priority', $filters['priority'] ?? null);
        $this->whereEquals($query, 'tickets.category_id', $filters['category_id'] ?? null);
        $this->whereEquals($query, 'tickets.assigned_agent_id', $filters['assigned_agent_id'] ?? null);
        $this->whereEquals($query, 'tickets.customer_id', $filters['customer_id'] ?? null);
        $this->createdBetween($query, $filters['created_from'] ?? null, $filters['created_to'] ?? null);
        if (filter_var($filters['unassigned'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('tickets.assigned_agent_id');
        }
        $slaState = is_string($filters['sla_state'] ?? null) ? SlaState::tryFrom($filters['sla_state']) : null;
        if ($slaState !== null) {
            SlaQuery::whereState($query, $slaState, $now);
        }
        $sort = $filters['sort'] ?? null;
        $this->sort($query, is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : self::DEFAULT_SORT);
        return $query;
    }

    /** @param  Builder<Ticket>  $query */
    private function number(Builder $query, mixed $number): void
    {
        if (! is_string($number) || trim($number) === '') {
            return;
        }
        $query->where('tickets.number', 'like', $this->escapeLike(Str::upper(trim($number))).'%');
    }

    /** @param  Builder<Ticket>  $query */
    private function keyword(Builder $query, mixed $input): void
    {
        if (! is_string($input) || trim($input) === '') {
            return;
        }
        if (in_array($query->getConnection()->getDriverName(), self::FULLTEXT_DRIVERS, true)) {
            $expression = FulltextSearchTerm::toBooleanMode($input);
            if ($expression !== '') {
                $query->whereFullText(['tickets.subject', 'tickets.description'], $expression, ['mode' => 'boolean']);
            }
            return;
        }
        $needle = '%'.$this->escapeLike(trim($input)).'%';
        $query->where(static function (Builder $where) use ($needle): void {
            $where->where('tickets.subject', 'like', $needle)->orWhere('tickets.description', 'like', $needle);
        });
    }

    /** @param  Builder<Ticket>  $query */
    private function whereInList(Builder $query, string $column, mixed $values): void
    {
        if (! is_array($values)) {
            return;
        }
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''));
        if ($values !== []) {
            $query->whereIn($column, $values);
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function whereEquals(Builder $query, string $column, mixed $value): void
    {
        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };
        if ($id !== null) {
            $query->where($column, $id);
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function createdBetween(Builder $query, mixed $from, mixed $to): void
    {
        $timezone = (string) $this->config->get('business_hours.timezone', 'UTC');
        if (is_string($from) && $from !== '') {
            $query->where('tickets.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }
        if (is_string($to) && $to !== '') {
            $query->where('tickets.created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function sort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if ($column === 'priority') {
            $cases = [];
            $bindings = [];
            foreach (TicketPriority::cases() as $priority) {
                $cases[] = 'when ? then ?';
                $bindings[] = $priority->value;
                $bindings[] = $priority->rank();
            }
            $query->orderByRaw('case tickets.priority '.implode(' ', $cases).' end '.$direction, $bindings);
        } else {
            $query->orderBy('tickets.'.$column, $direction);
        }
        $query->orderBy('tickets.id', $direction);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
```

Run: `DB_DATABASE=techzu_testing_agent3 ./vendor/bin/pest tests/Feature/Tickets/TicketFilterTest.php`. Expected: 27 passed.

**5. Write the failing seam test** `tests/Feature/Tickets/TicketIndexFilteringTest.php`. It goes through T05's real controllers.

```php
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
    $this->other = User::factory()->customer()->create();
    $this->own = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id, 'created_by_id' => $this->customer->id]);
    $this->foreign = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->other->id, 'created_by_id' => $this->other->id]);
});

it('never lets a customer widen visibility through customer_id', function (): void {
    Sanctum::actingAs($this->customer, ['*']);
    $this->getJson(route('api.v1.tickets.index', ['customer_id' => $this->other->id]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($this->customer)
        ->get(route('tickets.index', ['customer_id' => $this->other->id]))
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Index')->has('tickets.data', 0));
});

it('rejects a sort column outside the whitelist', function (string $sort): void {
    Sanctum::actingAs($this->admin, ['*']);
    $this->getJson(route('api.v1.tickets.index', ['sort' => $sort]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
})->with(['password', 'customer_id', 'created_at; drop table tickets', '--created_at', 'subject']);

it('filters by SLA state using the current time', function (): void {
    $now = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
    $this->travelTo($now);
    $breached = Ticket::factory()->status(TicketStatus::Open)->create(['sla_warning_at' => $now->subHours(3), 'sla_due_at' => $now->subHour()]);
    Ticket::factory()->status(TicketStatus::InProgress)->create(['sla_warning_at' => $now->addHours(2), 'sla_due_at' => $now->addHours(4)]);
    Sanctum::actingAs($this->admin, ['*']);
    $response = $this->getJson(route('api.v1.tickets.index', ['sla_state' => 'breached']))->assertOk();
    expect(collect($response->json('data'))->pluck('number')->all())->toBe([$breached->number]);
});

it('passes the validated filters and the filter options to the index page', function (): void {
    $agent = User::factory()->agent()->create();
    $this->actingAs($this->admin)
        ->get(route('tickets.index', ['status' => ['open'], 'sort' => '-priority', 'q' => 'printer']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Index')
            ->where('filters.status', ['open'])
            ->where('filters.sort', '-priority')
            ->where('filters.q', 'printer')
            ->has('filterOptions.statuses', 3)
            ->has('filterOptions.priorities', 4)
            ->has('filterOptions.slaStates', 5)
            ->has('filterOptions.sorts', 8)
            ->has('filterOptions.categories')
            ->where('filterOptions.agents.0.id', $agent->id)
            ->where('filterOptions.showPeopleFilters', true));
});

it('gives customers no agent list and no people filters', function (): void {
    User::factory()->agent()->create();
    $this->actingAs($this->customer)
        ->get(route('tickets.index', ['scope' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('filterOptions.statuses', 2)
            ->where('filterOptions.agents', [])
            ->where('filterOptions.showPeopleFilters', false));
});

```

**6. Modify `IndexTicketsRequest::rules()`.** Replace only `rules()`, keeping T05's first three rules verbatim. Add the imports `App\Enums\SlaState`, `App\Enums\TicketPriority`, `App\Enums\TicketStatus` and `App\Services\Tickets\TicketFilter`.

```php
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::in(['active', 'resolved'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tickets.per_page.max', 100)],
            'number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', 'array'],
            'priority.*' => ['string', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('categories', 'id')],
            'assigned_agent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unassigned' => ['sometimes', 'boolean'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => array_values(array_filter([
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                $this->filled('created_from') ? 'after_or_equal:created_from' : null,
            ])),
            'sla_state' => ['sometimes', 'nullable', Rule::enum(SlaState::class)],
            'sort' => ['sometimes', Rule::in(array_keys(TicketFilter::SORTS))],
        ];
    }
```

**7. Modify `TicketListQuery`.** Change the constructor and the `applyFilters()` body only; `TicketFilter` is in the same namespace.

```php
    public function __construct(
        private readonly Config $config,
        private readonly TicketFilter $filter,
    ) {}
```

```php
    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $validated
     * @return Builder<Ticket>
     */
    private function applyFilters(Builder $query, array $validated, User $viewer, CarbonImmutable $now): Builder
    {
        return $this->filter->apply($query, $validated, $viewer, $now);
    }
```

**8. Add `TicketPageData::filterOptions()`** after `formOptions()`, with the imports `App\Enums\Role` and `App\Enums\SlaState` (`Ticket`, `User` and `TicketStatus` are already imported). Statuses are the current scope's only, because `status[]` narrows within the scope. Agents include unavailable ones, because the filter is "assigned to", not "assignable to".

```php
    /** @return array{statuses: list<array{value: string, label: string}>, priorities: list<array{value: string, label: string}>, slaStates: list<array{value: string, label: string}>, sorts: list<array{value: string, label: string}>, categories: list<array{id: int, name: string}>, agents: list<array{id: int, name: string}>, showPeopleFilters: bool} */
    public function filterOptions(User $viewer, string $scope): array
    {
        $isStaff = $viewer->can('createOnBehalf', Ticket::class);
        $form = $this->formOptions();
        return [
            'statuses' => array_map(
                static fn (TicketStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                $scope === 'resolved' ? TicketStatus::finished() : TicketStatus::active(),
            ),
            'priorities' => $form['priorities'],
            'slaStates' => array_map(
                static fn (SlaState $s): array => ['value' => $s->value, 'label' => $s->label()],
                SlaState::cases(),
            ),
            'sorts' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(TicketFilter::SORTS),
                array_values(TicketFilter::SORTS),
            ),
            'categories' => $form['categories'],
            'agents' => $isStaff
                ? User::query()
                    ->role(Role::Agent->value)
                    ->orderBy('users.name')
                    ->limit(500)
                    ->get(['users.id', 'users.name'])
                    ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
                    ->values()
                    ->all()
                : [],
            'showPeopleFilters' => $isStaff,
        ];
    }
```

**9. Modify `Web\TicketController::index()`.** Add one entry to the props array, after `'filters'`:

```php
            'filterOptions' => $this->pages->filterOptions($this->user($request), $request->scope()),
```

**10. Modify `tests/Feature/Performance/TicketQueryCountTest.php`.** In `renders the web ticket index with a constant number of queries`, change `->toBeLessThanOrEqual(12)` to `->toBeLessThanOrEqual(14)`. Nothing else.

**11. Write the FULLTEXT test** `tests/Database/TicketKeywordSearchTest.php` (TD-031). InnoDB FULLTEXT only sees committed rows, so this file can't use `RefreshDatabase`'s wrapping transaction. It uses `DatabaseMigrations` in `tests/Database`, a suite `tests/Pest.php` doesn't configure, so the file declares its own `uses()`.

```php
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
    $this->printer = Ticket::factory()->status(TicketStatus::Open)->create([
        'subject' => 'Printer jammed on floor three',
        'description' => 'Paper stuck in tray two.',
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $this->invoice = Ticket::factory()->status(TicketStatus::Open)->create([
        'subject' => 'Invoice duplicated',
        'description' => 'The printer driver also crashed after the update.',
    ]);
    $this->vpn = Ticket::factory()->status(TicketStatus::Open)->create([
        'subject' => 'Cannot reach VPN',
        'description' => 'Connection drops every hour.',
    ]);
    $this->search = fn (string $q, ?User $viewer = null): array => app(TicketFilter::class)
        ->apply(Ticket::query(), ['q' => $q], $viewer ?? $this->admin, CarbonImmutable::now())
        ->pluck('number')
        ->sort()
        ->values()
        ->all();
    $this->numbers = fn (Ticket ...$tickets): array => collect($tickets)->pluck('number')->sort()->values()->all();
});

it('finds keyword matches in subject or description', function (): void {
    expect(($this->search)('printer'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('invoice'))->toBe(($this->numbers)($this->invoice));
});

it('matches word prefixes', function (): void {
    expect(($this->search)('print'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('conn'))->toBe(($this->numbers)($this->vpn));
});

it('neutralises boolean operators and unbalanced quotes typed by the user', function (): void {
    expect(($this->search)('+printer -invoice'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('"printer (jam'))->toBe(($this->numbers)($this->printer, $this->invoice))
        ->and(($this->search)('*@~<>'))->toHaveCount(3);
});

it('still applies visibility to keyword results', function (): void {
    expect(($this->search)('printer', $this->customer))->toBe(($this->numbers)($this->printer));
});
```

**12. Run all of this subtask's tests plus the T05 tests the seam touches:**

```bash
DB_DATABASE=techzu_testing_agent3 ./vendor/bin/pest tests/Unit/Tickets tests/Feature/Tickets tests/Database/TicketKeywordSearchTest.php tests/Feature/Http/Web tests/Feature/Http/Api tests/Feature/Performance/TicketQueryCountTest.php
./vendor/bin/pint app/Services/Tickets app/Http/Requests/Tickets/IndexTicketsRequest.php app/Http/Controllers/Web/TicketController.php tests/Unit/Tickets tests/Feature/Tickets tests/Database tests/Feature/Performance/TicketQueryCountTest.php
```
Expected: all pass. (`tests/Feature/Http/AssignmentEndpointsTest.php` belongs to P3.1 and isn't run here.) If a T05 test fails, the seam changed beyond steps 6–10: revert to exactly those edits; don't change T05's tests.

**Proposed commit:**
```
feat(search): add ticket filters, keyword search and whitelisted sort

Refs: P3.3
Implements: TD-012, TD-021, TD-031, TD-080, TD-120
```

---

## P3.4 — Show-page props, authorization matrix and leakage tests (W2)

**Files:**
- Modify:
  - `app/Http/Controllers/Web/TicketController.php`: in `show()`, `replies` comes from `TicketReplyResource` and `attachmentRules` is added; in `create()`, `attachmentRules` is added; the private `replyItems()` method and the `TicketReply` import are removed.
  - `app/Services/Tickets/TicketPageData.php`: `loadForShow()` sets the `ticket` relation on every loaded reply; new `attachmentRules()`.
  - `tests/Support/RouteAudit.php` (created by P2.4): add the `attachment` entry to `bindings()`. Nothing else changes; no P3 route goes into `PUBLIC` or `AUTHENTICATED_ONLY`.
- Create:
  - `tests/Feature/Authorization/AssignmentRoutesAuthorizationTest.php`
  - `tests/Feature/Authorization/ConversationRoutesAuthorizationTest.php`
  - `tests/Feature/Security/InternalNoteLeakageTest.php`

**Consumes:** every route, Action and resource from P3.1 and P3.2; P2.4's route audit (`Tests\Support\RouteAudit::bindings()` and `tests/Feature/Authorization/RouteProtectionTest.php`, which sends every application route as a guest, expecting 302 to `login` on the web or 401 on the API, and as a signed-in user with no role, expecting 403 or 404, and throws for a route parameter without a binding); `Tests\Support\AuthorizationMatrix` (`for($this)`, `actingAsRole()`, `asGuest()`, `expectAccess($method, $uri, $status, $payload)`, which sends `/api/` URIs as JSON through Sanctum and the rest through the web session).

**Produces:** the final `tickets/Show` and `tickets/Create` props listed in "Produces for P5".

### Steps

**1. Write the authorization matrices.** With an empty payload, a permitted `assign` fails validation (302 web, 422 API), and a permitted `claim` on an unassigned ticket succeeds (302, 200). `tests/Feature/Authorization/AssignmentRoutesAuthorizationTest.php`:

```php
dataset('assignment route matrix', function (): array {
    $rows = [
        ['customer', 'own', 'claim', 403, 403],
        ['customer', 'own', 'assign', 403, 403],
        ['customer', 'other', 'claim', 404, 404],
        ['customer', 'other', 'assign', 404, 404],
        ['agent', 'unassigned', 'claim', 302, 200],
        ['agent', 'unassigned', 'assign', 403, 403],
        ['agent', 'colleague', 'claim', 302, 409],
        ['agent', 'colleague', 'assign', 403, 403],
        ['agent', 'own', 'assign', 302, 422],
        ['agent', 'resolved', 'claim', 403, 403],
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
            ? $factory->create(['customer_id' => $user->id, 'created_by_id' => $user->id, 'assigned_agent_id' => null])
            : $factory->assignedTo($user)->create(),
        'colleague' => $factory->assignedTo(User::factory()->agent()->create())->create(),
        'resolved' => Ticket::factory()->status(TicketStatus::Resolved)->create(['assigned_agent_id' => null]),
        'unassigned', 'other' => $factory->create(['assigned_agent_id' => null]),
    };
    $matrix->expectAccess('POST', str_replace('{n}', $ticket->number, $uri), $status);
})->with('assignment route matrix');

it('requires authentication on the assignment routes', function (string $uri, int $status): void {
    $ticket = Ticket::factory()->create();
    $uri = str_replace('{n}', $ticket->number, $uri);
    (str_starts_with($uri, '/api/') ? $this->postJson($uri) : $this->post($uri))->assertStatus($status);
})->with([
    ['/tickets/{n}/claim', 302],
    ['/tickets/{n}/assign', 302],
    ['/api/v1/tickets/{n}/claim', 401],
    ['/api/v1/tickets/{n}/assign', 401],
]);
```

`tests/Feature/Authorization/ConversationRoutesAuthorizationTest.php`:

```php
beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
});

it('enforces the conversation matrix', function (?Role $role, string $relation, string $method, string $target, bool $closed, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $actor = $role === null ? null : $matrix->actingAsRole($role);
    if ($actor === null) {
        $matrix->asGuest();
    }
    $owner = $relation === 'own' && $actor !== null ? $actor : User::factory()->customer()->create();
    $writer = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($writer)->create([
        'customer_id' => $owner->id,
        'created_by_id' => $owner->id,
    ]);
    $public = app(AddReply::class)->handle($writer, $ticket, 'Public', false, [UploadedFile::fake()->create('public.pdf', 2, 'application/pdf')]);
    $internal = app(AddReply::class)->handle($writer, $ticket, 'Internal', true, [UploadedFile::fake()->create('internal.pdf', 2, 'application/pdf')]);
    $publicFile = Attachment::query()->where('reply_id', $public->id)->sole();
    $internalFile = Attachment::query()->where('reply_id', $internal->id)->sole();
    if ($closed) {
        $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();
    }
    $uri = match ($target) {
        'web.reply' => route('tickets.replies.store', $ticket),
        'api.reply' => route('api.v1.tickets.replies.store', $ticket),
        'api.replies' => route('api.v1.tickets.replies.index', $ticket),
        'web.public_file' => route('attachments.download', $publicFile),
        'api.public_file' => route('api.v1.attachments.download', $publicFile),
        'web.internal_file' => route('attachments.download', $internalFile),
        'api.internal_file' => route('api.v1.attachments.download', $internalFile),
    };
    $matrix->expectAccess($method, $uri, $status, $method === 'POST' ? ['body' => 'matrix reply'] : []);
})->with([
    'guest · web reply' => [null, 'other', 'POST', 'web.reply', false, 302],
    'customer · own · web reply' => [Role::Customer, 'own', 'POST', 'web.reply', false, 302],
    'customer · other · web reply' => [Role::Customer, 'other', 'POST', 'web.reply', false, 404],
    'agent · web reply' => [Role::Agent, 'other', 'POST', 'web.reply', false, 302],
    'admin · web reply' => [Role::Admin, 'other', 'POST', 'web.reply', false, 302],
    'admin · web reply · closed' => [Role::Admin, 'other', 'POST', 'web.reply', true, 403],
    'guest · api reply' => [null, 'other', 'POST', 'api.reply', false, 401],
    'customer · own · api reply' => [Role::Customer, 'own', 'POST', 'api.reply', false, 201],
    'customer · other · api reply' => [Role::Customer, 'other', 'POST', 'api.reply', false, 404],
    'agent · api reply' => [Role::Agent, 'other', 'POST', 'api.reply', false, 201],
    'customer · own · api reply · closed' => [Role::Customer, 'own', 'POST', 'api.reply', true, 403],
    'agent · api reply · closed' => [Role::Agent, 'other', 'POST', 'api.reply', true, 403],
    'guest · api replies' => [null, 'other', 'GET', 'api.replies', false, 401],
    'customer · own · api replies' => [Role::Customer, 'own', 'GET', 'api.replies', false, 200],
    'customer · other · api replies' => [Role::Customer, 'other', 'GET', 'api.replies', false, 404],
    'agent · api replies' => [Role::Agent, 'other', 'GET', 'api.replies', false, 200],
    'guest · web public file' => [null, 'other', 'GET', 'web.public_file', false, 302],
    'customer · own · web public file' => [Role::Customer, 'own', 'GET', 'web.public_file', false, 200],
    'customer · other · web public file' => [Role::Customer, 'other', 'GET', 'web.public_file', false, 404],
    'agent · web public file' => [Role::Agent, 'other', 'GET', 'web.public_file', false, 200],
    'guest · api public file' => [null, 'other', 'GET', 'api.public_file', false, 401],
    'customer · own · api public file' => [Role::Customer, 'own', 'GET', 'api.public_file', false, 200],
    'customer · other · api public file' => [Role::Customer, 'other', 'GET', 'api.public_file', false, 404],
    'customer · own · web internal file' => [Role::Customer, 'own', 'GET', 'web.internal_file', false, 404],
    'customer · own · api internal file' => [Role::Customer, 'own', 'GET', 'api.internal_file', false, 404],
    'agent · web internal file' => [Role::Agent, 'other', 'GET', 'web.internal_file', false, 200],
    'admin · api internal file' => [Role::Admin, 'other', 'GET', 'api.internal_file', false, 200],
]);
```

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Authorization/AssignmentRoutesAuthorizationTest.php tests/Feature/Authorization/ConversationRoutesAuthorizationTest.php`. Expected: 57 passed (26 + 4 + 27). Prove it bites: remove `Gate::authorize('claim', $ticket);` from both assignment controllers, expect the `customer own claim` rows to fail, and restore the lines.

**2. Write the failing leakage test** `tests/Feature/Security/InternalNoteLeakageTest.php`. It seeds one public reply and one internal note through the real `AddReply`, then checks every surface a customer can reach. These tests guard behaviour that already exists (T05's scopes and P3.2's code), so they pass before and after step 3; step 3 adds the resource's fail-closed check to the web path, which the last test proves on its own.

```php
beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    app(AddReply::class)->handle($this->agent, $this->ticket, 'PUBLIC-ANSWER', false);
    $this->internal = app(AddReply::class)->handle($this->agent, $this->ticket, 'SECRET-INTERNAL-NOTE', true, [
        UploadedFile::fake()->create('secret-file.pdf', 2, 'application/pdf'),
    ]);
});

it('shows internal notes on the show page and the replies API only to staff', function (string $viewer, int $expected): void {
    $user = $viewer === 'customer' ? $this->customer : $this->agent;
    $this->actingAs($user)
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Show')->has('replies', $expected));
    Sanctum::actingAs($user, ['*']);
    $this->getJson(route('api.v1.tickets.replies.index', $this->ticket))
        ->assertOk()
        ->assertJsonCount($expected, 'data');
})->with([
    'customer' => ['customer', 1],
    'agent' => ['agent', 2],
]);

it('never sends a customer internal content from any page or endpoint', function (string $guard, string $routeName): void {
    $uri = route($routeName, in_array($routeName, ['tickets.index', 'api.v1.tickets.index'], true) ? [] : $this->ticket);
    if ($guard === 'api') {
        Sanctum::actingAs($this->customer, ['*']);
        $response = $this->getJson($uri);
    } else {
        $response = $this->actingAs($this->customer)->get($uri);
    }
    $response->assertOk()
        ->assertDontSee('SECRET-INTERNAL-NOTE')
        ->assertDontSee('secret-file.pdf')
        ->assertDontSee('internal_note');
})->with([
    'web ticket page' => ['web', 'tickets.show'],
    'web ticket list' => ['web', 'tickets.index'],
    'api ticket' => ['api', 'api.v1.tickets.show'],
    'api ticket list' => ['api', 'api.v1.tickets.index'],
    'api replies' => ['api', 'api.v1.tickets.replies.index'],
    'api activities' => ['api', 'api.v1.tickets.activities'],
]);

it('counts only public replies in a customer ticket list', function (): void {
    Sanctum::actingAs($this->customer, ['*']);
    $this->getJson(route('api.v1.tickets.index'))
        ->assertOk()
        ->assertJsonPath('data.0.replies_count', 1);
});

it('refuses to serialize an internal reply for a customer even when a query forgets visibleTo', function (): void {
    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $this->customer);
    $this->internal->setRelation('ticket', $this->ticket);
    expect(fn () => TicketReplyResource::make($this->internal)->toArray($request))
        ->toThrow(AuthorizationException::class);
});
```

Run: `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Security/InternalNoteLeakageTest.php`. Expected: 10 passed. Every test guards existing behaviour; if one fails, it has found a real leak: stop and report which surface.

**3. Wire `TicketReplyResource` and `attachmentRules` into the pages.** In `TicketPageData::loadForShow()`, keep the `load([...])` call exactly as it is, but don't return its result directly: after it, set the parent on each reply so the resource's policy check never lazy-loads, then return the ticket.

```php
        $ticket->replies->each(static fn (TicketReply $reply): TicketReply => $reply->setRelation('ticket', $ticket));
        return $ticket;
```

Add `App\Models\TicketReply` to the imports, and add this method after `filterOptions()`. The client hints in P5 read these values instead of duplicating the config (TD-130).

```php
    /** @return array{extensions: list<string>, max_kb: int, max_files: int} */
    public function attachmentRules(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('attachments.extensions', []);
        return [
            'extensions' => array_values($extensions),
            'max_kb' => (int) config('attachments.max_kb', 10240),
            'max_files' => (int) config('attachments.max_files', 5),
        ];
    }
```

In `Web\TicketController`:
- `create()`: add `'attachmentRules' => $this->pages->attachmentRules(),` to the props.
- `show()`: replace `'replies' => $this->replyItems($ticket, $request),` with `'replies' => TicketReplyResource::collection($ticket->replies)->resolve($request),` and add `'attachmentRules' => $this->pages->attachmentRules(),` after `'options'`.
- Delete the private `replyItems()` method, remove the `App\Models\TicketReply` import and add `App\Http\Resources\V1\TicketReplyResource`.

**4. Register the new route parameter with the route audit.** P3 adds one new parameter, `{attachment}` (`attachments.download`, `api.v1.attachments.download`); `{ticket}` already has a binding. Add this entry to the array returned by `RouteAudit::bindings()`, with the import `App\Models\Attachment`:

```php
            'attachment' => static fn (): string => (string) Attachment::factory()->create()->id,
```

Every new P3 route already denies a user with no role, so no audit list changes:
- `claim` goes through `TicketPolicy::claim`, and `assign` through `AssignTicketRequest::authorize()` (`TicketPolicy::assign`). Both answer 404, because a user with no role can't see the ticket.
- `replies.store` and `replies.index` are authorized by their FormRequests before validation runs (404). `throttle:uploads` doesn't change the status.
- `attachments.download` goes through `AttachmentPolicy::view`, which answers 404 before the controller touches the disk.

If the audit reports a P3 route with any other status, fix that route's authorization; never add it to `AUTHENTICATED_ONLY`.

**5. Run this subtask's tests and the T05 page tests it touches:**

```bash
DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Authorization/AssignmentRoutesAuthorizationTest.php tests/Feature/Authorization/ConversationRoutesAuthorizationTest.php tests/Feature/Authorization/RouteProtectionTest.php tests/Feature/Security/InternalNoteLeakageTest.php tests/Feature/Http/Web/TicketPagesTest.php tests/Feature/Performance/TicketQueryCountTest.php
./vendor/bin/pint app/Http/Controllers/Web/TicketController.php app/Services/Tickets/TicketPageData.php tests/Support/RouteAudit.php tests/Feature/Authorization tests/Feature/Security
```
Expected: all pass. `RouteProtectionTest` covers the nine new routes with no offenders, `TicketPagesTest` still sees the same reply shape, and the show-page query count stays constant.

**Proposed commit:**
```
test(auth): cover assignment and conversation routes and note leakage

Refs: P3.4
Implements: TD-012, TD-060, TD-120
```

---

## Produces for P5

All URIs bind tickets by `number`. Wayfinder exposes the routes as `@/routes/...` (by name) and `@/actions/App/Http/Controllers/...` (by controller): `Web/AssignmentController` (`claim`, `assign`), `Web/TicketReplyController` (`store`) and `AttachmentDownloadController`. Domain errors arrive on the web as `flash.error`; validation errors arrive in the form's error bag.

**`tickets/Show` props** (`Web\TicketController@show`):

| Prop | Type (`resources/js/types/tickets.ts` where it exists) | Notes |
|---|---|---|
| `ticket` | `TicketDetail` | includes `can: TicketCan` |
| `replies` | `ReplyItem[]` | `TicketReplyResource`, oldest first, internal notes only for staff |
| `attachments` | `AttachmentItem[]` | ticket-level files (no `reply_id`), visible only |
| `activities` | `ActivityItem[]` | visible only, newest first, at most 100 |
| `agents` | `AgentOption[]` | available agents; `[]` unless `can.assign` |
| `can` | `TicketCan` | `claim` is true only when the policy allows and the ticket is unassigned; `assign` covers admin assignment and the assignee's hand-off |
| `options` | `TicketFormOptions` | unchanged from T05 |
| `attachmentRules` | `{ extensions: string[]; max_kb: number; max_files: number }` | new type for P5 (`AttachmentRules`) |

**`tickets/Create` props:** T05's `options` and `canCreateOnBehalf`, plus `attachmentRules` (same type as above).

**`tickets/Index` props** (`Web\TicketController@index`):

| Prop | Type | Notes |
|---|---|---|
| `tickets` | `Paginated<TicketListItem>` | links keep the query string |
| `scope` | `'active' \| 'resolved'` | |
| `filters` | `Record<string, unknown>` | the validated query: `number?`, `q?`, `status?: string[]`, `priority?: string[]`, `category_id?`, `assigned_agent_id?`, `unassigned?`, `customer_id?`, `created_from?`, `created_to?`, `sla_state?`, `sort?`, `scope?`, `page?`, `per_page?`; ids and booleans arrive as query-string strings |
| `filterOptions` | `TicketFilterOptions` (new type for P5) | `{ statuses: Option[]; priorities: Option[]; slaStates: Option[]; sorts: Option[]; categories: { id: number; name: string }[]; agents: AgentOption[]; showPeopleFilters: boolean }`. `statuses` holds only the current scope's statuses. `agents` is `[]` and `showPeopleFilters` false for customers. The default sort is `-created_at`. |

The filter bar syncs with `router.get('/tickets', query, { preserveState: true, replace: true })`. An invalid filter comes back as a validation error on the web (redirect with errors) and 422 on the API.

**Form endpoints:**

| Action | Request | Result |
|---|---|---|
| Claim | POST `/tickets/{number}/claim` | back, `flash.success`; lost race → `flash.error` |
| Assign / hand off | POST `/tickets/{number}/assign` `{ agent_id }` | back, `flash.success`; ineligible → `flash.error` |
| Reply / internal note | POST `/tickets/{number}/replies` multipart `{ body, is_internal?, attachments[]? }` | redirect to the ticket, `flash.success` ("Reply sent." or "Internal note added."); `is_internal` is ignored for customers; errors on `body`, `attachments`, `attachments.N` |
| Download | GET `AttachmentItem.download_url` | file download (`Content-Disposition: attachment`) |

**API (`/api/v1`, Sanctum):** `POST tickets/{number}/claim` → 200 `TicketResource` or 409 `{message, code: "ticket_already_claimed"}`; `POST tickets/{number}/assign {agent_id}` → 200 `TicketResource` or 422 `agent_not_eligible`; `GET tickets/{number}/replies` → paginated `TicketReplyResource`; `POST tickets/{number}/replies` → 201 `TicketReplyResource` (422 `ticket_closed` on a close race); `GET attachments/{id}` → file; `GET tickets` accepts every filter above.

## Acceptance criteria

1. `ClaimTicket` assigns through one conditional UPDATE (`assigned_agent_id IS NULL`): one affected row assigns, sets `assigned_at` and moves `open` to `in_progress`; zero rows throws `TicketAlreadyClaimed` (409 `ticket_already_claimed`). The result never depends on a PHP-side read.
2. On two real MySQL connections, a claim made while another claim's transaction is open blocks on the row lock, then loses with `TicketAlreadyClaimed` after the first commits. Exactly one `claimed` activity exists.
3. `AssignTicket` runs in a transaction with `lockForUpdate`, rejects unavailable users and non-agents with `AgentNotEligible` (422), reports the previous agent, leaves the status unchanged, is a no-op for the current owner, and fires `TicketAssigned(method = Manual)`.
4. `AgentSelector` returns available agents of the ticket's category ordered by active load, then `last_assigned_at` with never-assigned first, then id; with `$lock` it locks the candidates `FOR UPDATE` in id order.
5. `AutoAssignTicket` is `ShouldQueue` + `ShouldBeUnique` (unique id = ticket id) on queue `default`, with `tries = 3`, `backoff = [10, 60, 300]` and a logging `failed()`. It assigns the least-loaded eligible agent, logs and leaves the ticket unassigned when none is eligible, and exits quietly for a ticket that was claimed, finished or deleted.
6. `QueueAutoAssignment` dispatches the job after `TicketCreated` commits, never for imported tickets, and only when `tickets.auto_assign` is true (default true; false in `phpunit.xml`).
7. Every successful claim, assignment and auto-assignment updates `users.last_assigned_at`.
8. A customer reply is always public, on the web and the API, whatever `is_internal` says. Internal notes' attachments are internal.
9. A reply, its attachments and any status change commit together; a failure after files are stored leaves no files, no reply and no attachment rows.
10. A public reply by the ticket's customer on a `waiting_for_customer` ticket moves it to `in_progress` through `ChangeTicketStatus(null, …)` and shifts the SLA due time by the paused business time; a staff reply doesn't change the status.
11. Replies on a Closed ticket answer 403 at the policy; a ticket closed between authorization and the write answers 422 `ticket_closed`, and nothing is stored.
12. Attachment rules come from `config/attachments.php`: a disallowed type (by content or by name) or an oversized file → 422, nothing stored.
13. Downloads go through `AttachmentPolicy::view` and stream with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. Customers get 404 for internal attachments and for other customers' tickets.
14. Customers never receive an internal note's body, its attachment name or an `internal_note` activity from the web show page, the web list, the API ticket, the API list, the API replies index or the API activities; `replies_count` counts only public replies for them. `TicketReplyResource` refuses to serialize an internal reply for a viewer who can't view it, and the web show page uses it.
15. `TicketFilter::apply(Builder, array, User, CarbonImmutable)` applies visibility first; a customer passing another customer's `customer_id` gets an empty list on the web and the API.
16. Every TD-080 filter works alone and combined with query-string values; `number` is a case-insensitive, LIKE-escaped prefix match; dates are whole business-timezone days.
17. `q` uses `MATCH … AGAINST (? IN BOOLEAN MODE)` on MySQL with `+-<>()~*"@` stripped, a `*` on words of 3+ characters and at most 10 terms; it is proven on committed rows in `tests/Database`. Other drivers use an escaped `LIKE`.
18. `sort` accepts only the keys of `TicketFilter::SORTS`; anything else → 422. The default stays newest first; `priority` sorts by rank; `id` breaks ties.
19. The web ticket index still runs a constant number of queries, at most 14 (`TicketQueryCountTest`).
20. `tickets/Index` passes `filterOptions`, and `tickets/Show` and `tickets/Create` pass `attachmentRules`, with exactly the shapes in "Produces for P5". No file under `resources/js` changes.
21. Routes `tickets.claim`, `tickets.assign`, `tickets.replies.store`, `attachments.download`, `api.v1.tickets.claim`, `api.v1.tickets.assign`, `api.v1.tickets.replies.index`, `api.v1.tickets.replies.store` and `api.v1.attachments.download` exist and are covered by the authorization matrices, including guests.
22. `RouteAudit::bindings()` has an `attachment` entry, and P2.4's `RouteProtectionTest` passes: every new route turns guests away (302 to `login` on the web, 401 on the API) and answers 403 or 404 to a signed-in user with no role. No P3 route is added to `RouteAudit::PUBLIC` or `AUTHENTICATED_ONLY`.
