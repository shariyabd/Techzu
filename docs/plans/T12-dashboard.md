# T12 — Dashboard

## 1. Header

- **Objective:** give staff a dashboard (web and API) backed by exactly three indexed aggregate queries, cached for 60 seconds per scope, plus a "My tickets" strip for the signed-in staff member.
- **Depends on:** T05 (`SlaQuery` semantics, `TicketStatus::active()/finished()`, the `Ticket::visibleTo` and `slaState()` helpers), T04 (`Gate 'viewDashboard'`), T01 (models, factories, enums).
- **Wave:** 4b (parallel with T10, T11, T13).
- **Navigation:** after login and on `/`, T00's `home` route sends staff to `dashboard` and customers to `tickets.index`. The `dashboard` route stays 403 for customers.
- **TDs implemented:** TD-100 (three conditional aggregate queries, 60 s cache), TD-021 (index-backed predicates; adds `tickets(status, resolved_at)`), TD-023 (SLA state derived at query time with one `now`), TD-101 (no N+1, bounded lists), TD-011 (dashboard for agents and admins only).

## 2. Files

**Create**
- `database/migrations/2026_10_29_120000_add_status_resolved_at_index_to_tickets_table.php`
- `app/Services/Dashboard/DashboardStats.php`
- `app/Http/Resources/V1/DashboardResource.php`
- `app/Http/Resources/V1/DashboardTicketResource.php`
- `app/Http/Controllers/Web/DashboardController.php`
- `app/Http/Controllers/Api/V1/DashboardController.php`
- `routes/web/dashboard.php`
- `routes/api/v1/dashboard.php`
- `resources/js/types/dashboard.ts`
- `resources/js/components/dashboard/StatCard.vue`
- `resources/js/pages/dashboard/Index.vue`
- `resources/js/config/navigation/dashboard.ts`

**Test**
- `tests/Feature/Dashboard/DashboardStatsTest.php`
- `tests/Feature/Dashboard/DashboardHttpTest.php`
- `tests/Feature/Authorization/DashboardAuthorizationTest.php`

**Modify**
- `TECHNICAL_DECISIONS.md`: add one row to the TD-021 index table. CLAUDE.md's database rule requires a new index to be recorded there.

**Must not touch:** earlier migrations, `app/Models/*`, `app/Services/Sla/SlaQuery.php` (T05), `app/Providers/AppServiceProvider.php` (the `viewDashboard` gate is T04's), `routes/web.php`, `resources/js/config/navigation.ts`, and any starter-kit `pages/Dashboard.vue` left by T00 (T14 cleans up if needed).

## 3. Interfaces

### Consumes

```php
// T01
enum TicketStatus { public static function active(): array; public static function finished(): array; }
enum TicketPriority { public function rank(): int; public function label(): string; }
enum SlaState { public function label(): string; }
enum Role
Ticket::scopeVisibleTo(Builder $q, User $user): void
Ticket::slaState(?CarbonImmutable $now = null): SlaState
User::isStaff(): bool
// T04
Gate::define('viewDashboard', ...)    // admin + agent
// T05 — semantics mirrored inside the CASE expressions:
SlaQuery::whereBreached(Builder $q, CarbonImmutable $now): Builder     // active, not paused, sla_due_at <= now
SlaQuery::whereApproaching(Builder $q, CarbonImmutable $now): Builder  // active, not paused, warning <= now < due
```

### Produces (README §3, binding)

```php
// app/Services/Dashboard
final class DashboardStats {
    /** @return array{totals: array<string,int>, by_priority: list<array{priority:string,count:int}>, by_agent: list<array{agent_id:int,name:string,active:int,breached:int}>} */
    public function for(User $viewer): array;
}
// routes
web: dashboard      GET /dashboard       (can:viewDashboard)
api: api.v1.dashboard GET /api/v1/dashboard (can:viewDashboard)
// frontend
resources/js/config/navigation/dashboard.ts (default export NavItem[])
```

`totals` keys: `open` (active), `unassigned` (active, no agent), `critical` (active), `breached`, `approaching`, `resolved` (Resolved or Closed with `resolved_at` in the last 30 days).

`by_priority` always lists all four priorities, critical first (by `rank()` descending), with zero counts included.

`by_agent` lists every agent, including idle ones, plus any admin who holds active tickets. It is ordered by active count descending, then name.

Task-internal additions:
- `DashboardStats::myTickets(User $viewer, int $limit = 5): Collection<int, Ticket>`
- `DashboardStats::cacheKeyFor(User $viewer): string`
- `DashboardStats::CACHE_TTL_SECONDS = 60`
- `DashboardResource`, `DashboardTicketResource`

**Cache scope.** All staff share one key, `dashboard:staff`, because they see the same numbers. A non-staff viewer, who can only reach this service by calling it directly since the gate blocks them, gets `dashboard:customer:{id}`. Their figures are limited to their own tickets (`visibleTo`) and `by_agent` is empty.

**Cache payload.** The cached value is a plain array of ints and strings, never objects. Laravel 13's `config/cache.php` ships `'serializable_classes' => false`, so a cached model, Collection or Carbon instance would come back from the Redis or database store as `__PHP_Incomplete_Class` (upgrade guide, "Cache serializable_classes Configuration"). Keep `for()` returning arrays only; `myTickets()` is uncached, so its models never reach the cache.

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T12.1 Index migration and `DashboardStats` | migration, `TECHNICAL_DECISIONS.md` row, `DashboardStats.php`, `DashboardStatsTest.php` | — | T12.3 |
| T12.2 HTTP layer | 2 resources, 2 controllers, 2 route files, `DashboardHttpTest.php`, `DashboardAuthorizationTest.php` | T12.1, T12.3 | — |
| T12.3 Frontend | `types/dashboard.ts`, `StatCard.vue`, `pages/dashboard/Index.vue`, `navigation/dashboard.ts` | — (props fixed below) | T12.1 |

Mini-waves:
- **Wave A (parallel):** T12.1 ∥ T12.3.
- **Wave B:** T12.2. It needs the page file for `assertInertia`.

**Inertia props for `dashboard/Index`** (fixed here):
- `stats`: the `DashboardStats::for()` array.
- `myTickets`: a `DashboardTicket[]`, at most 5, being the viewer's active assigned tickets ordered by `sla_due_at`.

Both are closures, so the 60 s poll reloads only them.

---

## 5. Subtasks

### T12.1 — Index migration and `DashboardStats`

**Why the new index.** Query 1 must count "resolved in the last 30 days" in the same statement as the active totals. Its `WHERE` is `status IN (active) OR (status IN (resolved, closed) AND resolved_at >= ?)`. With `tickets(status, resolved_at)`, MySQL's range optimizer turns both branches into intervals on one index:
- the three active statuses, as whole prefixes
- the two finished statuses, as `resolved_at` ranges

So the query reads only active rows plus the last 30 days of finished ones, not the whole history. No TD-021 index serves `resolved_at`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Dashboard/DashboardStatsTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // Array store: a cache hit must cost zero queries, which a database cache store would not allow.
    config(['cache.default' => 'array']);
    Cache::flush();

    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $now = CarbonImmutable::now();

    $category = Category::factory()->create();
    $this->admin = User::factory()->admin()->create(['name' => 'Admin Zed']);
    $this->alpha = User::factory()->agent([$category->id])->create(['name' => 'Agent Alpha']);
    $this->bravo = User::factory()->agent([$category->id])->create(['name' => 'Agent Bravo']);
    $this->charlie = User::factory()->agent([$category->id])->create(['name' => 'Agent Charlie']);
    $this->customer = User::factory()->customer()->create();

    $make = function (array $attributes) use ($category): Ticket {
        return Ticket::factory()->create(array_merge([
            'category_id' => $category->id,
            'customer_id' => $this->customer->id,
            'assigned_agent_id' => null,
            'sla_paused_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ], $attributes));
    };

    // t1: open, unassigned, low, within
    $make(['status' => TicketStatus::Open, 'priority' => TicketPriority::Low,
        'sla_warning_at' => $now->addHours(2), 'sla_due_at' => $now->addHours(4)]);
    // t2: in progress, Alpha, critical, breached
    $make(['status' => TicketStatus::InProgress, 'priority' => TicketPriority::Critical, 'assigned_agent_id' => $this->alpha->id,
        'sla_warning_at' => $now->subHours(2), 'sla_due_at' => $now->subHour()]);
    // t3: waiting, Alpha, high, past due but paused, so not breached
    $make(['status' => TicketStatus::WaitingForCustomer, 'priority' => TicketPriority::High, 'assigned_agent_id' => $this->alpha->id,
        'sla_warning_at' => $now->subHours(3), 'sla_due_at' => $now->subHour(), 'sla_paused_at' => $now->subHours(5)]);
    // t4: open, Bravo, medium, approaching
    $make(['status' => TicketStatus::Open, 'priority' => TicketPriority::Medium, 'assigned_agent_id' => $this->bravo->id,
        'sla_warning_at' => $now->subMinutes(10), 'sla_due_at' => $now->addHour()]);
    // t5: open, Bravo, critical, within
    $make(['status' => TicketStatus::Open, 'priority' => TicketPriority::Critical, 'assigned_agent_id' => $this->bravo->id,
        'sla_warning_at' => $now->addHour(), 'sla_due_at' => $now->addHours(2)]);
    // t6: resolved 2 days ago (counted), past due but stopped
    $make(['status' => TicketStatus::Resolved, 'priority' => TicketPriority::Critical, 'assigned_agent_id' => $this->alpha->id,
        'sla_warning_at' => $now->subDays(4), 'sla_due_at' => $now->subDays(3), 'resolved_at' => $now->subDays(2)]);
    // t7: closed, resolved 40 days ago (not counted)
    $make(['status' => TicketStatus::Closed, 'priority' => TicketPriority::Low, 'assigned_agent_id' => $this->bravo->id,
        'sla_warning_at' => $now->subDays(45), 'sla_due_at' => $now->subDays(44), 'resolved_at' => $now->subDays(40), 'closed_at' => $now->subDays(39)]);
    // t8: closed, resolved yesterday (counted)
    $make(['status' => TicketStatus::Closed, 'priority' => TicketPriority::High, 'assigned_agent_id' => $this->alpha->id,
        'sla_warning_at' => $now->subDays(3), 'sla_due_at' => $now->subDays(2), 'resolved_at' => $now->subDay(), 'closed_at' => $now->subDay()]);

    // A cold-cache query count must not include loading the viewer's roles for isStaff().
    $this->admin->load('roles');
    $this->alpha->load('roles');
    $this->customer->load('roles');
});

it('computes the totals across a mixed set of tickets', function (): void {
    $stats = app(DashboardStats::class)->for($this->admin);

    expect($stats['totals'])->toBe([
        'open' => 5,
        'unassigned' => 1,
        'critical' => 2,
        'breached' => 1,
        'approaching' => 1,
        'resolved' => 2,
    ]);
});

it('uses the same SLA semantics as SlaQuery', function (): void {
    $now = CarbonImmutable::now();
    $stats = app(DashboardStats::class)->for($this->admin);

    expect($stats['totals']['breached'])->toBe(SlaQuery::whereBreached(Ticket::query(), $now)->count())
        ->and($stats['totals']['approaching'])->toBe(SlaQuery::whereApproaching(Ticket::query(), $now)->count());
});

it('lists every priority, critical first, counting active tickets only', function (): void {
    expect(app(DashboardStats::class)->for($this->admin)['by_priority'])->toBe([
        ['priority' => 'critical', 'count' => 2],
        ['priority' => 'high', 'count' => 1],
        ['priority' => 'medium', 'count' => 1],
        ['priority' => 'low', 'count' => 1],
    ]);
});

it('lists active and breached tickets per agent, including idle agents', function (): void {
    expect(app(DashboardStats::class)->for($this->admin)['by_agent'])->toBe([
        ['agent_id' => $this->alpha->id, 'name' => 'Agent Alpha', 'active' => 2, 'breached' => 1],
        ['agent_id' => $this->bravo->id, 'name' => 'Agent Bravo', 'active' => 2, 'breached' => 0],
        ['agent_id' => $this->charlie->id, 'name' => 'Agent Charlie', 'active' => 0, 'breached' => 0],
    ]);
});

it('includes an admin in by_agent only while they hold active tickets', function (): void {
    Ticket::query()->whereKey(Ticket::query()->whereNull('assigned_agent_id')->value('id'))
        ->update(['assigned_agent_id' => $this->admin->id]);

    $names = array_column(app(DashboardStats::class)->for($this->admin)['by_agent'], 'name');

    expect($names)->toContain('Admin Zed');
});

it('runs at most three queries on a cold cache', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(DashboardStats::class)->for($this->admin);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(3);
});

it('serves a cached result with zero queries', function (): void {
    app(DashboardStats::class)->for($this->admin);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(DashboardStats::class)->for($this->admin);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('shares one cache entry across staff for 60 seconds', function (): void {
    $first = app(DashboardStats::class)->for($this->admin);

    Ticket::factory()->create(['status' => TicketStatus::Open, 'priority' => TicketPriority::Low, 'assigned_agent_id' => null]);

    expect(app(DashboardStats::class)->for($this->alpha))->toBe($first)
        ->and(app(DashboardStats::class)->cacheKeyFor($this->alpha))->toBe('dashboard:staff');

    $this->travel(61)->seconds();

    expect(app(DashboardStats::class)->for($this->alpha)['totals']['open'])->toBe(6);
});

it('scopes a non-staff viewer to their own tickets and lists no agents', function (): void {
    $other = User::factory()->customer()->create();
    $other->load('roles');

    $stats = app(DashboardStats::class)->for($other);

    expect($stats['totals']['open'])->toBe(0)
        ->and($stats['by_agent'])->toBe([])
        ->and(app(DashboardStats::class)->cacheKeyFor($other))->toBe('dashboard:customer:'.$other->id);
});

it("returns the viewer's active assigned tickets, soonest deadline first", function (): void {
    $tickets = app(DashboardStats::class)->myTickets($this->alpha);

    expect($tickets)->toHaveCount(2)
        ->and($tickets->pluck('status')->all())->toBe([TicketStatus::InProgress, TicketStatus::WaitingForCustomer])
        ->and(app(DashboardStats::class)->myTickets($this->charlie))->toHaveCount(0);
});
```

  > The two `myTickets` rows for Alpha (t2 and t3) have the same `sla_due_at`. The query sorts by `sla_due_at`, then `id`, so t2 (inserted first) comes first.

- [ ] **Step 2: Run it and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Dashboard/DashboardStatsTest.php`
  Expected: FAIL with `Error: Class "App\Services\Dashboard\DashboardStats" not found`.

- [ ] **Step 3: Create the migration** `database/migrations/2026_10_29_120000_add_status_resolved_at_index_to_tickets_table.php`.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Serves the dashboard's "resolved in the last 30 days" count (TD-021, TD-100).
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['status', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['status', 'resolved_at']);
        });
    }
};
```

- [ ] **Step 4: Record the index in TD-021.** In `TECHNICAL_DECISIONS.md`, inside the TD-021 table, insert this row directly after the `tickets(created_at)` row:

```markdown
| `tickets(status, resolved_at)` | dashboard "resolved in the last 30 days" count (added in T12) |
```

- [ ] **Step 5: Create** `app/Services/Dashboard/DashboardStats.php`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Three aggregate queries, cached for 60 s per scope (TD-100).
 *
 * The SLA predicates repeat SlaQuery's semantics inside CASE expressions, because SlaQuery builds
 * WHERE clauses and a single conditional aggregate cannot use them. All three queries bind the same
 * $now, so the counts agree with one another. The test checks that they agree with SlaQuery.
 */
final class DashboardStats
{
    public const CACHE_TTL_SECONDS = 60;

    /**
     * @return array{totals: array<string,int>, by_priority: list<array{priority:string,count:int}>, by_agent: list<array{agent_id:int,name:string,active:int,breached:int}>}
     */
    public function for(User $viewer): array
    {
        return Cache::remember(
            $this->cacheKeyFor($viewer),
            self::CACHE_TTL_SECONDS,
            function () use ($viewer): array {
                $now = CarbonImmutable::now();

                return [
                    'totals' => $this->totals($viewer, $now),
                    'by_priority' => $this->byPriority($viewer),
                    'by_agent' => $viewer->isStaff() ? $this->byAgent($now) : [],
                ];
            },
        );
    }

    public function cacheKeyFor(User $viewer): string
    {
        return $viewer->isStaff() ? 'dashboard:staff' : 'dashboard:customer:'.$viewer->id;
    }

    /** Uncached on purpose: it's personal and cheap (index (assigned_agent_id, status), at most $limit rows). @return Collection<int, Ticket> */
    public function myTickets(User $viewer, int $limit = 5): Collection
    {
        return Ticket::query()
            ->select(['id', 'number', 'subject', 'status', 'priority', 'sla_due_at', 'sla_warning_at', 'sla_paused_at', 'created_at'])
            ->where('assigned_agent_id', $viewer->id)
            ->whereIn('status', self::values(TicketStatus::active()))
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** Query 1 of 3. @return array<string, int> */
    private function totals(User $viewer, CarbonImmutable $now): array
    {
        $active = self::values(TicketStatus::active());
        $finished = self::values(TicketStatus::finished());
        $activeIn = self::placeholders($active);
        $finishedIn = self::placeholders($finished);
        $resolvedSince = $now->subDays(30);

        $row = $this->visible($viewer)
            ->toBase()
            ->selectRaw(implode(', ', [
                "SUM(CASE WHEN status IN ({$activeIn}) THEN 1 ELSE 0 END) AS total_open",
                "SUM(CASE WHEN status IN ({$activeIn}) AND assigned_agent_id IS NULL THEN 1 ELSE 0 END) AS total_unassigned",
                "SUM(CASE WHEN status IN ({$activeIn}) AND priority = ? THEN 1 ELSE 0 END) AS total_critical",
                // = SlaQuery::whereBreached: active, not paused, sla_due_at <= now
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_due_at <= ? THEN 1 ELSE 0 END) AS total_breached",
                // = SlaQuery::whereApproaching: active, not paused, warning <= now < due
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_warning_at <= ? AND sla_due_at > ? THEN 1 ELSE 0 END) AS total_approaching",
                "SUM(CASE WHEN status IN ({$finishedIn}) AND resolved_at >= ? THEN 1 ELSE 0 END) AS total_resolved",
            ]), [
                ...$active,
                ...$active,
                ...$active, TicketPriority::Critical->value,
                ...$active, $now,
                ...$active, $now, $now,
                ...$finished, $resolvedSince,
            ])
            // Both branches are ranges on tickets(status, resolved_at), so the full history is never scanned.
            ->where(function ($query) use ($active, $finished, $resolvedSince): void {
                $query->whereIn('status', $active)
                    ->orWhere(function ($query) use ($finished, $resolvedSince): void {
                        $query->whereIn('status', $finished)->where('resolved_at', '>=', $resolvedSince);
                    });
            })
            ->first();

        return [
            'open' => (int) ($row->total_open ?? 0),
            'unassigned' => (int) ($row->total_unassigned ?? 0),
            'critical' => (int) ($row->total_critical ?? 0),
            'breached' => (int) ($row->total_breached ?? 0),
            'approaching' => (int) ($row->total_approaching ?? 0),
            'resolved' => (int) ($row->total_resolved ?? 0),
        ];
    }

    /** Query 2 of 3. Served by tickets(priority, status). @return list<array{priority:string,count:int}> */
    private function byPriority(User $viewer): array
    {
        $counts = $this->visible($viewer)
            ->toBase()
            ->select('priority')
            ->selectRaw('COUNT(*) AS priority_count')
            ->whereIn('status', self::values(TicketStatus::active()))
            ->groupBy('priority')
            ->get()
            ->pluck('priority_count', 'priority');

        $priorities = TicketPriority::cases();
        usort($priorities, static fn (TicketPriority $a, TicketPriority $b): int => $b->rank() <=> $a->rank());

        return array_map(
            static fn (TicketPriority $priority): array => [
                'priority' => $priority->value,
                'count' => (int) ($counts[$priority->value] ?? 0),
            ],
            $priorities,
        );
    }

    /**
     * Query 3 of 3. Starts from the agent/admin role rows, so the cost scales with staff, not users.
     * Tickets are LEFT JOINed through tickets(assigned_agent_id, status).
     *
     * @return list<array{agent_id:int,name:string,active:int,breached:int}>
     */
    private function byAgent(CarbonImmutable $now): array
    {
        $roles = (string) config('permission.table_names.roles', 'roles');
        $pivot = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $morphKey = (string) config('permission.column_names.model_morph_key', 'model_id');
        $active = self::values(TicketStatus::active());

        $rows = DB::table($roles)
            ->join($pivot, "{$pivot}.role_id", '=', "{$roles}.id")
            ->join('users', 'users.id', '=', "{$pivot}.{$morphKey}")
            ->leftJoin('tickets', function (JoinClause $join) use ($active): void {
                $join->on('tickets.assigned_agent_id', '=', 'users.id')
                    ->whereIn('tickets.status', $active);
            })
            ->where("{$pivot}.model_type", (new User())->getMorphClass())
            ->whereIn("{$roles}.name", [Role::Agent->value, Role::Admin->value])
            ->groupBy('users.id', 'users.name')
            // Agents always appear; admins only while they hold active tickets.
            ->havingRaw("COUNT(tickets.id) > 0 OR MAX(CASE WHEN {$roles}.name = ? THEN 1 ELSE 0 END) = 1", [Role::Agent->value])
            ->selectRaw(
                'users.id AS agent_id, users.name AS agent_name, COUNT(tickets.id) AS active_count, '
                .'SUM(CASE WHEN tickets.id IS NOT NULL AND tickets.sla_paused_at IS NULL AND tickets.sla_due_at <= ? THEN 1 ELSE 0 END) AS breached_count',
                [$now],
            )
            ->orderByDesc('active_count')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'agent_id' => (int) $row->agent_id,
            'name' => (string) $row->agent_name,
            'active' => (int) $row->active_count,
            'breached' => (int) $row->breached_count,
        ])->values()->all();
    }

    /** @return Builder<Ticket> */
    private function visible(User $viewer): Builder
    {
        return Ticket::query()->visibleTo($viewer);
    }

    /**
     * @param  list<TicketStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (TicketStatus $status): string => $status->value, $statuses);
    }

    /** @param list<string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
```

- [ ] **Step 6: Run it and expect PASS.**
  `php artisan migrate && ./vendor/bin/pest tests/Feature/Dashboard/DashboardStatsTest.php`
  Expected: the migration runs, then PASS (10 tests).

- [ ] **Step 7: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 8: Commit.**

```bash
git add database/migrations/2026_10_29_120000_add_status_resolved_at_index_to_tickets_table.php \
  TECHNICAL_DECISIONS.md app/Services/Dashboard/DashboardStats.php tests/Feature/Dashboard/DashboardStatsTest.php
git commit -m "feat(dashboard): add cached dashboard aggregate queries" --trailer "Refs: T12.1"
```

---
### T12.2 — HTTP layer (web + API)

- [ ] **Step 1: Write the failing HTTP test** `tests/Feature/Dashboard/DashboardHttpTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $now = CarbonImmutable::now();

    $this->agent = User::factory()->agent()->create(['name' => 'Agent Alpha']);

    $this->breached = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Critical,
        'assigned_agent_id' => $this->agent->id,
        'sla_warning_at' => $now->subHours(2),
        'sla_due_at' => $now->subHour(),
        'sla_paused_at' => null,
    ]);
    $this->within = Ticket::factory()->create([
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Low,
        'assigned_agent_id' => null,
        'sla_warning_at' => $now->addHours(3),
        'sla_due_at' => $now->addHours(4),
        'sla_paused_at' => null,
    ]);
});

it('renders the dashboard page with stats and the viewer\'s tickets', function (): void {
    $this->actingAs($this->agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Index')
            ->where('stats.totals.open', 2)
            ->where('stats.totals.unassigned', 1)
            ->where('stats.totals.breached', 1)
            ->has('stats.by_priority', 4)
            ->where('stats.by_agent.0.name', 'Agent Alpha')
            ->where('stats.by_agent.0.breached', 1)
            ->has('myTickets', 1)
            ->where('myTickets.0.number', $this->breached->number)
            ->where('myTickets.0.priority.value', 'critical')
            ->where('myTickets.0.sla.state', 'breached'));
});

it('returns the same figures from the API', function (): void {
    Sanctum::actingAs($this->agent, ['*']);

    $this->getJson(route('api.v1.dashboard'))
        ->assertOk()
        ->assertJsonPath('data.totals.open', 2)
        ->assertJsonPath('data.totals.breached', 1)
        ->assertJsonPath('data.by_priority.0', ['priority' => 'critical', 'count' => 1])
        ->assertJsonPath('data.by_agent.0.agent_id', $this->agent->id)
        ->assertJsonPath('data.my_tickets.0.number', $this->breached->number)
        ->assertJsonStructure(['data' => [
            'totals' => ['open', 'unassigned', 'critical', 'breached', 'approaching', 'resolved'],
            'by_priority' => [['priority', 'count']],
            'by_agent' => [['agent_id', 'name', 'active', 'breached']],
            'my_tickets' => [['number', 'subject', 'status' => ['value', 'label'], 'priority' => ['value', 'label'], 'sla' => ['state', 'label', 'due_at']]],
        ]]);
});
```

- [ ] **Step 2: Write the failing authorization test** `tests/Feature/Authorization/DashboardAuthorizationTest.php`. It uses T04's `Tests\Support\AuthorizationMatrix`: `/api/` URIs go as JSON with Sanctum, and web URIs go through the session.

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use Tests\Support\AuthorizationMatrix;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('applies the dashboard matrix on web and API', function (Role $role, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    $matrix->expectAccess('GET', route('dashboard'), $status);
    $matrix->expectAccess('GET', route('api.v1.dashboard'), $status);
})->with([
    'admin' => [Role::Admin, 200],
    'agent' => [Role::Agent, 200],
    'customer' => [Role::Customer, 403],
]);

it('rejects guests', function (): void {
    $matrix = AuthorizationMatrix::for($this)->asGuest();

    $matrix->expectAccess('GET', route('dashboard'), 302)->assertRedirect(route('login'));
    $matrix->expectAccess('GET', route('api.v1.dashboard'), 401);
});
```

- [ ] **Step 3: Run both and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Dashboard/DashboardHttpTest.php tests/Feature/Authorization/DashboardAuthorizationTest.php`
  Expected: FAIL with `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [dashboard] not defined.` T00 removes the starter kit's `dashboard` route, so T12 is its only definition.

- [ ] **Step 4: Create** `app/Http/Resources/V1/DashboardTicketResource.php`. It is a small, relation-free shape, so the strip needs no eager loading.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
final class DashboardTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->slaState();

        return [
            'number' => $this->number,
            'subject' => $this->subject,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'sla' => [
                'state' => $state->value,
                'label' => $state->label(),
                'due_at' => $this->sla_due_at?->toIso8601String(),
            ],
        ];
    }
}
```

- [ ] **Step 5: Create** `app/Http/Resources/V1/DashboardResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps DashboardStats::for() plus the viewer's ticket strip.
 *
 * @property array{stats: array<string, mixed>, my_tickets: Collection<int, Ticket>} $resource
 */
final class DashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource['stats'],
            'my_tickets' => DashboardTicketResource::collection($this->resource['my_tickets']),
        ];
    }
}
```

- [ ] **Step 6: Create** `app/Http/Controllers/Web/DashboardController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DashboardTicketResource;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    /** Authorized by the route's `can:viewDashboard` middleware (T04 gate). */
    public function __invoke(Request $request, DashboardStats $stats): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('dashboard/Index', [
            'stats' => fn (): array => $stats->for($viewer),
            'myTickets' => fn (): array => DashboardTicketResource::collection($stats->myTickets($viewer))->resolve($request),
        ]);
    }
}
```

- [ ] **Step 7: Create** `app/Http/Controllers/Api/V1/DashboardController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DashboardResource;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): DashboardResource
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return DashboardResource::make([
            'stats' => $stats->for($viewer),
            'my_tickets' => $stats->myTickets($viewer),
        ]);
    }
}
```

- [ ] **Step 8: Create** `routes/web/dashboard.php`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)
        ->name('dashboard')
        ->can('viewDashboard');
});
```

- [ ] **Step 9: Create** `routes/api/v1/dashboard.php`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('dashboard', DashboardController::class)
        ->name('dashboard')
        ->can('viewDashboard');
});
```

- [ ] **Step 10: Run both and expect PASS.**
  `./vendor/bin/pest tests/Feature/Dashboard tests/Feature/Authorization/DashboardAuthorizationTest.php`
  Expected: PASS (16 tests: 10 stats, 2 HTTP, 4 authorization).

- [ ] **Step 11: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 12: Commit.**

```bash
git add app/Http/Resources/V1/DashboardResource.php app/Http/Resources/V1/DashboardTicketResource.php \
  app/Http/Controllers/Web/DashboardController.php app/Http/Controllers/Api/V1/DashboardController.php \
  routes/web/dashboard.php routes/api/v1/dashboard.php \
  tests/Feature/Dashboard/DashboardHttpTest.php tests/Feature/Authorization/DashboardAuthorizationTest.php
git commit -m "feat(dashboard): add dashboard web route and API endpoint" --trailer "Refs: T12.2"
```

---

### T12.3 — Frontend

The page uses starter-kit `card` and `button` components. Tables are plain Tailwind markup. Ticket links use the T08 filter parameters from TD-080 (`unassigned`, `priority[]`, `sla_state`, `scope`).

- [ ] **Step 1: Create** `resources/js/types/dashboard.ts`.

```ts
export type PriorityValue = 'low' | 'medium' | 'high' | 'critical';

export interface DashboardTotals {
    open: number;
    unassigned: number;
    critical: number;
    breached: number;
    approaching: number;
    resolved: number;
}

export interface PriorityCount {
    priority: PriorityValue;
    count: number;
}

export interface AgentLoad {
    agent_id: number;
    name: string;
    active: number;
    breached: number;
}

export interface DashboardStats {
    totals: DashboardTotals;
    by_priority: PriorityCount[];
    by_agent: AgentLoad[];
}

export interface DashboardTicket {
    number: string;
    subject: string;
    status: { value: string; label: string };
    priority: { value: PriorityValue; label: string };
    sla: {
        state: 'within' | 'approaching' | 'breached' | 'paused' | 'stopped';
        label: string;
        due_at: string | null;
    };
}
```

- [ ] **Step 2: Create** `resources/js/components/dashboard/StatCard.vue`.

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Card, CardContent } from '@/components/ui/card';

const props = defineProps<{
    label: string;
    value: number;
    href: string;
    tone?: 'default' | 'warning' | 'danger' | 'success';
}>();

const valueClass = computed((): string => {
    switch (props.tone) {
        case 'danger':
            return 'text-red-600 dark:text-red-400';
        case 'warning':
            return 'text-amber-600 dark:text-amber-400';
        case 'success':
            return 'text-emerald-600 dark:text-emerald-400';
        default:
            return '';
    }
});
</script>

<template>
    <Link
        :href="href"
        class="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
        <Card class="h-full transition-colors hover:bg-muted/50">
            <CardContent class="flex flex-col gap-1 pt-6">
                <span class="text-sm text-muted-foreground">{{ label }}</span>
                <span
                    class="text-3xl font-semibold tabular-nums"
                    :class="valueClass"
                    >{{ value }}</span
                >
            </CardContent>
        </Card>
    </Link>
</template>
```

- [ ] **Step 3: Create** `resources/js/pages/dashboard/Index.vue`.

```vue
<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { DashboardStats, DashboardTicket } from '@/types/dashboard';

const props = defineProps<{
    stats: DashboardStats;
    myTickets: DashboardTicket[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
});

// The server caches for 60 s, so polling faster would only re-read the cache.
usePoll(60_000, { only: ['stats', 'myTickets'] });

const cards = computed(() => [
    {
        label: 'Open tickets',
        value: props.stats.totals.open,
        href: '/tickets?scope=active',
        tone: 'default' as const,
    },
    {
        label: 'Unassigned',
        value: props.stats.totals.unassigned,
        href: '/tickets?scope=active&unassigned=1',
        tone: 'warning' as const,
    },
    {
        label: 'Critical',
        value: props.stats.totals.critical,
        href: '/tickets?scope=active&priority[]=critical',
        tone: 'danger' as const,
    },
    {
        label: 'SLA breached',
        value: props.stats.totals.breached,
        href: '/tickets?scope=active&sla_state=breached',
        tone: 'danger' as const,
    },
    {
        label: 'Approaching SLA',
        value: props.stats.totals.approaching,
        href: '/tickets?scope=active&sla_state=approaching',
        tone: 'warning' as const,
    },
    {
        label: 'Resolved (30 days)',
        value: props.stats.totals.resolved,
        href: '/tickets?scope=resolved',
        tone: 'success' as const,
    },
]);

const totalActive = computed((): number =>
    props.stats.by_priority.reduce((sum, row) => sum + row.count, 0),
);

function share(count: number): number {
    return totalActive.value === 0
        ? 0
        : Math.round((count / totalActive.value) * 100);
}

function slaClass(state: DashboardTicket['sla']['state']): string {
    switch (state) {
        case 'breached':
            return 'text-red-600 dark:text-red-400';
        case 'approaching':
            return 'text-amber-600 dark:text-amber-400';
        default:
            return 'text-muted-foreground';
    }
}

function formatDue(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

const priorityLabels: Record<string, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <section aria-labelledby="my-tickets-heading">
            <Card>
                <CardHeader>
                    <CardTitle id="my-tickets-heading">My tickets</CardTitle>
                    <CardDescription
                        >Your active tickets, soonest deadline
                        first.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <p
                        v-if="myTickets.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nothing is assigned to you right now.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="ticket in myTickets"
                            :key="ticket.number"
                            class="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <Link
                                :href="`/tickets/${ticket.number}`"
                                class="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
                            >
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{ ticket.number }}</span
                                >
                                <span class="ml-2 font-medium">{{
                                    ticket.subject
                                }}</span>
                            </Link>
                            <span class="text-xs text-muted-foreground"
                                >{{ ticket.priority.label }} ·
                                {{ ticket.status.label }}</span
                            >
                            <span
                                class="text-xs font-medium"
                                :class="slaClass(ticket.sla.state)"
                            >
                                {{ ticket.sla.label }} · due
                                {{ formatDue(ticket.sla.due_at) }}
                            </span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </section>

        <section
            aria-label="Totals"
            class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6"
        >
            <StatCard
                v-for="card in cards"
                :key="card.label"
                :label="card.label"
                :value="card.value"
                :href="card.href"
                :tone="card.tone"
            />
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Active tickets by priority</CardTitle>
                </CardHeader>
                <CardContent>
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-4">Priority</th>
                                <th class="py-2 pr-4 text-right">Tickets</th>
                                <th class="py-2">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in stats.by_priority"
                                :key="row.priority"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">
                                    <Link
                                        :href="`/tickets?scope=active&priority[]=${row.priority}`"
                                        class="underline-offset-4 hover:underline"
                                    >
                                        {{ priorityLabels[row.priority] }}
                                    </Link>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ row.count }}
                                </td>
                                <td class="py-2">
                                    <div
                                        class="h-2 w-full rounded bg-muted"
                                        :aria-label="`${share(row.count)}%`"
                                    >
                                        <div
                                            class="h-full rounded bg-primary"
                                            :style="{
                                                width: `${share(row.count)}%`,
                                            }"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Active tickets by agent</CardTitle>
                    <CardDescription
                        >Includes agents with no active
                        tickets.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <p
                        v-if="stats.by_agent.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No agents yet.
                    </p>
                    <table v-else class="w-full text-left text-sm">
                        <thead
                            class="border-b text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-4">Agent</th>
                                <th class="py-2 pr-4 text-right">Active</th>
                                <th class="py-2 text-right">Breached</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in stats.by_agent"
                                :key="row.agent_id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">
                                    <Link
                                        :href="`/tickets?scope=active&assigned_agent_id=${row.agent_id}`"
                                        class="underline-offset-4 hover:underline"
                                    >
                                        {{ row.name }}
                                    </Link>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ row.active }}
                                </td>
                                <td
                                    class="py-2 text-right tabular-nums"
                                    :class="
                                        row.breached > 0
                                            ? 'font-semibold text-red-600 dark:text-red-400'
                                            : ''
                                    "
                                >
                                    {{ row.breached }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>

        <p class="text-xs text-muted-foreground">
            Figures refresh every minute.
        </p>
    </div>
</template>
```

- [ ] **Step 4: Create** `resources/js/config/navigation/dashboard.ts`.

```ts
import { LayoutGrid } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';

const items: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
        order: 10,
        visible: (page) => page.auth.can.viewDashboard,
    },
];

export default items;
```

- [ ] **Step 5: Type-check and build.**
  `npm run build && npm run check && npx vue-tsc --noEmit`
  Expected: no type errors, and Vite prints `✓ built in …`.

- [ ] **Step 6: Commit.**

```bash
git add resources/js/types/dashboard.ts resources/js/components/dashboard/StatCard.vue \
  resources/js/pages/dashboard/Index.vue resources/js/config/navigation/dashboard.ts
git commit -m "feat(dashboard): add dashboard Vue page and navigation entry" --trailer "Refs: T12.3"
```

---

## 6. Acceptance criteria

- [ ] `DashboardStats::for()` returns exactly the contract shape, `{totals, by_priority, by_agent}`, with the keys documented in §3.
- [ ] A cold call runs at most three queries: conditional `SUM(CASE…)` totals, the `GROUP BY priority` counts, and the agent join. A warm call runs zero.
- [ ] The breached and approaching predicates match `SlaQuery` (parity test) and use one bound `now` throughout.
- [ ] Results are cached for 60 s under `dashboard:staff`, shared by admins and agents, or `dashboard:customer:{id}` for a non-staff viewer.
- [ ] "Resolved" counts Resolved or Closed tickets with `resolved_at` in the last 30 days, using the new `tickets(status, resolved_at)` index. The index is recorded in TD-021.
- [ ] Web `dashboard` renders `dashboard/Index` with stat cards that link to the filtered ticket list, a by-priority table, a by-agent table, a "My tickets" strip, and a 60 s `usePoll`.
- [ ] API `api.v1.dashboard` returns the same figures plus `my_tickets` through `DashboardResource`.
- [ ] Admins and agents get 200, customers get 403, and guests get a login redirect or 401, on both web and API (DashboardAuthorizationTest, via `AuthorizationMatrix`).
- [ ] Customers never reach the dashboard through normal navigation: T00's `home` route redirects them to `tickets.index`, and the dashboard nav item is hidden without `auth.can.viewDashboard`.
- [ ] `navigation/dashboard.ts` shows "Dashboard" only when `auth.can.viewDashboard`.

## 7. Verification

```bash
./vendor/bin/pest tests/Feature/Dashboard tests/Feature/Authorization/DashboardAuthorizationTest.php
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
```

Expected:
- 16 dashboard tests pass.
- `pint --test` passes, and PHPStan prints `[OK] No errors`.
- The full suite has no failures.
- The build succeeds with no type errors.

Manual check:
1. Run `php artisan migrate:fresh --seed`.
2. Log in as `agent1@example.com`.
3. `/dashboard` shows non-zero totals and the agent's own tickets.
4. Clicking "SLA breached" opens the ticket list filtered by `sla_state=breached`.

## Contract notes for controller

1. **Resolved:** the `tickets(status, resolved_at)` index and its TD-021 row are approved. T12.1 Step 4 adds the row to `TECHNICAL_DECISIONS.md` when it is implemented.
2. **Extra public names.** These are T12-internal, used only by T12's own controllers, but not listed in §3.4 or §3.12:
   - `DashboardStats::myTickets()`
   - `DashboardStats::cacheKeyFor()`
   - `DashboardResource`
   - `DashboardTicketResource`

   `DashboardTicketResource` exists because `TicketListResource`'s fields aren't pinned in §3.12.
3. **"My tickets" audience.** The strip is shown to all staff. Admins can hold tickets by claiming them. Restricting it to agents would mean a role check in the controller, which CLAUDE.md forbids.
4. **Resolved:** T00's `home` route redirects customers to `tickets.index` and staff to `dashboard`. `dashboard` stays 403 for customers. T00's tests own the `home` redirect; DashboardAuthorizationTest covers the 403.
5. **`by_agent` scope.** It lists every agent, including idle ones, plus admins only while they hold active tickets.
