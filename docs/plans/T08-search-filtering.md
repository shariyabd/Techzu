# T08 — Search & filtering

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. Work test-first. Run the gate before each commit.

## 1. Header

- **Objective:** give the web and API ticket lists one reusable, index-backed `TicketFilter` covering ticket number, keyword (FULLTEXT), status, priority, category, agent, unassigned, customer, created date range, SLA state and a whitelisted sort, and plug it into T05's `TicketListQuery` seam. The query string drives a synced filter bar.
- **Depends on:** T05 (`TicketListQuery`, `TicketPageData`, `IndexTicketsRequest`, `SlaQuery`, `TicketListResource`, the `TicketFilterBar.vue` stub, `tickets/Index.vue`, `config('tickets.per_page')`), and transitively T00 (the `Database` PHPUnit suite, `SharedProps`), T01 (models, scopes, factories, the FULLTEXT index) and T04 (`TicketPolicy::viewAny`, `createOnBehalf`).
- **Wave:** 4a (parallel with T06, T07, T09).
- **TDs implemented:**
  - TD-080: TicketFilter, FULLTEXT boolean mode, LIKE fallback, a validated `IndexTicketsRequest`, offset pagination.
  - TD-120: sanitized boolean-mode operators, whitelisted sort.
  - TD-012: visibility applied first, so a customer can't widen the list through `customer_id`.
  - TD-021: every filter uses an existing index.
  - TD-101: no N+1 with filters active.
  - TD-031: FULLTEXT is tested on MySQL with committed rows.

## 2. Files

**Create**

| Path | Purpose |
|---|---|
| `app/Services/Tickets/TicketFilter.php` | the query object (README §3.4) |
| `app/Services/Tickets/FulltextSearchTerm.php` | pure boolean-mode sanitizer |
| `resources/js/types/ticket-filters.ts` | `TicketFilterOptions` |
| `tests/Unit/Tickets/FulltextSearchTermTest.php` | sanitizer (no DB) |
| `tests/Feature/Tickets/TicketFilterTest.php` | each filter as a dataset, combined filters, sort, timezone boundary, LIKE fallback |
| `tests/Feature/Tickets/TicketSlaFilterTest.php` | SLA-state filters with `travelTo` |
| `tests/Feature/Tickets/TicketIndexFilteringTest.php` | HTTP through T05's controllers: validation, visibility bypass, eager loading, N+1 guard, filter options |
| `tests/Database/TicketKeywordSearchTest.php` | FULLTEXT on real committed MySQL rows (`DatabaseMigrations`, T00's `Database` suite) |

**Modify (T05's seam, exactly as T05 §3 "Seam for T08" defines it)**

| Path | Change |
|---|---|
| `app/Services/Tickets/TicketListQuery.php` | add `private readonly TicketFilter $filter` to the constructor; replace the body of `applyFilters()` with `return $this->filter->apply($query, $validated, $viewer, $now);`; update its docblock |
| `app/Http/Requests/Tickets/IndexTicketsRequest.php` | `rules()` only: add the TD-080 filter rules, keeping `scope`, `page` and `per_page` unchanged |
| `resources/js/components/tickets/TicketFilterBar.vue` | replace the T05 stub; props `scope` and `filters` unchanged, plus an optional `options` |
| `app/Services/Tickets/TicketPageData.php` | add one method, `filterOptions(User $viewer): array`; existing methods are unchanged (see Contract note 2) |
| `resources/js/pages/tickets/Index.vue` | two prop lines and one binding (T08.5 Step 4) |
| `app/Http/Controllers/Web/TicketController.php` | **one line only** in `index()`: `'filterOptions' => $this->pages->filterOptions($this->user($request)),` (T08.5 Step 4b; allowed by README §4) |

**Must not touch:** `app/Http/Controllers/Web/TicketController.php` beyond the one `filterOptions` line, `app/Http/Controllers/Api/V1/TicketController.php`, `routes/*`, `app/Services/Sla/SlaQuery.php`, `app/Models/*`, `app/Http/Resources/V1/TicketListResource.php`, every other method in `TicketListQuery`, `TicketPageData` and `IndexTicketsRequest`, `resources/js/types/tickets.ts`, `tests/Pest.php`, `phpunit.xml`, migrations.

## 3. Interfaces

### Consumes

```php
// T05 — the seam
final class App\Services\Tickets\TicketListQuery {
    public function paginate(User $viewer, array $validated, CarbonImmutable $now): LengthAwarePaginator;
    // already applies: select(LIST_COLUMNS), visibleTo($viewer), the scope's statuses (active|resolved, default active),
    // with(['category:id,name','customer:id,name','assignedAgent:id,name']), withCount(visible replies),
    // per_page clamped to config('tickets.per_page.max'), withQueryString()
    private function applyFilters(Builder $query, array $validated, User $viewer, CarbonImmutable $now): Builder; // T08 replaces the body
}
App\Http\Requests\Tickets\IndexTicketsRequest   // authorize(): Gate inspect viewAny; rules(): scope, page, per_page; scope(): string
// Both controllers call $tickets->paginate($user, $request->validated(), CarbonImmutable::now());
// the web index renders tickets/Index with props tickets, scope, filters ((object) $validated).
App\Services\Tickets\TicketPageData::formOptions()   // categories list reused by filterOptions()
App\Services\Sla\SlaQuery::whereState(Builder $q, SlaState $state, CarbonImmutable $now): Builder
resources/js/components/tickets/TicketFilterBar.vue (stub)  props { scope: 'active' | 'resolved'; filters: Record<string, unknown> }
resources/js/types/tickets.ts: Option, TicketStatusValue, TicketPriorityValue, SlaStateValue
// T00
resources/js/types/shared.ts: SharedProps (auth.can.createOnBehalf)
phpunit.xml testsuite "Database" → tests/Database
// T01
Ticket::scopeVisibleTo, tickets FULLTEXT(subject, description), indexes per TD-021; factories
// T04
TicketPolicy::createOnBehalf(User)   // the staff signal for filter options (no role checks)
```

### Produces (README §3.4, exactly)

```php
namespace App\Services\Tickets;

final class TicketFilter
{
    /** @param array<string,mixed> $filters validated */
    public function apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder;
}
```

Task-internal:
- `FulltextSearchTerm::toBooleanMode(string): string`
- `TicketFilter::SORTS`, `TicketFilter::DEFAULT_SORT`, `TicketFilter::sortOptions(): list<string>`
- `TicketPageData::filterOptions(User): array{categories, agents, showPeopleFilters}`
- TS type `TicketFilterOptions`

**Division of work with `TicketListQuery`.** `TicketListQuery` keeps visibility, scope, column lists, eager loading, `withCount` and pagination. `TicketFilter` adds the TD-080 predicates and owns the order. `TicketFilter::apply()` also re-applies `visibleTo($viewer)` first: it's idempotent here, and it keeps the class safe when it's reused without `TicketListQuery`. `TicketFilter` takes the request's raw validated values (query-string strings) and normalizes them itself, because T08 changes only `rules()` on the request.

**Query parameters** (`IndexTicketsRequest::rules()`, the same for `GET /tickets` and `GET /api/v1/tickets`):

| Param | Rule | Behaviour | Index (TD-021) |
|---|---|---|---|
| `scope` (T05, unchanged) | `active\|resolved` | applied by `TicketListQuery`, default `active` | `(status, …)` |
| `page`, `per_page` (T05, unchanged) | int; `per_page` ≤ `tickets.per_page.max` | `TicketListQuery` | — |
| `number` | string ≤ 32 | upper-cased prefix match, LIKE-escaped | `number` unique |
| `q` | string ≤ 200 | MySQL/MariaDB: `MATCH(subject, description) AGAINST(? IN BOOLEAN MODE)` on a sanitized term; other drivers: escaped `LIKE` | FULLTEXT |
| `status[]` | each `TicketStatus` | `whereIn`, narrowing inside the scope | `(status, …)` |
| `priority[]` | each `TicketPriority` | `whereIn` | `(priority, status)` |
| `category_id` | int ≥ 1, exists | equals | `(category_id, status)` |
| `assigned_agent_id` | int ≥ 1 | equals | `(assigned_agent_id, status)` |
| `unassigned` | boolean | when true, `assigned_agent_id IS NULL` | `(assigned_agent_id, status)` |
| `customer_id` | int ≥ 1 | equals, AND-ed after visibility | `(customer_id, created_at)` |
| `created_from` / `created_to` | `Y-m-d`, to ≥ from | whole days in the business timezone, converted to UTC | `(created_at)` |
| `sla_state` | `SlaState` | `SlaQuery::whereState` | `(status, sla_due_at)`, `(status, sla_warning_at)` |
| `sort` | `created_at, updated_at, sla_due_at, priority`, optionally prefixed with `-` | default `-created_at` (T05's order); `priority` by `rank()`; `id` breaks ties | `(created_at)` |

`customer_id` and `assigned_agent_id` deliberately have no `exists` rule, so a customer can't probe which user ids exist (a 422 versus a 200 would tell them).

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T08.1 Sanitizer | `FulltextSearchTerm.php`, `tests/Unit/Tickets/FulltextSearchTermTest.php` | — | — |
| T08.2 TicketFilter | `TicketFilter.php`, `tests/Feature/Tickets/TicketFilterTest.php`, `tests/Feature/Tickets/TicketSlaFilterTest.php` | T08.1 | — |
| T08.3 Seam wiring | `TicketListQuery.php` (constructor + `applyFilters`), `IndexTicketsRequest.php` (`rules`), `TicketPageData.php` (`filterOptions`), `tests/Feature/Tickets/TicketIndexFilteringTest.php` | T08.2 | T08.4 |
| T08.4 FULLTEXT on MySQL | `tests/Database/TicketKeywordSearchTest.php` | T08.2 | T08.3, T08.5 |
| T08.5 Filter bar UI | `types/ticket-filters.ts`, `TicketFilterBar.vue`, `pages/tickets/Index.vue` | T08.3 (`filterOptions` shape) | T08.4 |

Mini-waves: **A** T08.1 → **B** T08.2 → **C** T08.3 ∥ T08.4 → **D** T08.5, which may start alongside T08.4 once T08.3 has committed.

---

## 5. Subtasks

### T08.1 — FULLTEXT boolean-mode sanitizer

- [ ] **Step 1: Write the failing test** `tests/Unit/Tickets/FulltextSearchTermTest.php`

```php
<?php

declare(strict_types=1);

use App\Services\Tickets\FulltextSearchTerm;

it('turns user input into a safe boolean-mode expression', function (string $input, string $expected): void {
    expect(FulltextSearchTerm::toBooleanMode($input))->toBe($expected);
})->with([
    'plain words get a prefix wildcard' => ['printer jam', 'printer* jam*'],
    'words under 3 characters get no wildcard' => ['pc on fire', 'pc on fire*'],
    'exactly 3 characters get a wildcard' => ['vpn', 'vpn*'],
    'required and excluded operators are stripped' => ['+printer -jam', 'printer* jam*'],
    'grouping and phrase quotes are stripped' => ['("printer jam")', 'printer* jam*'],
    'relevance and negation operators are stripped' => ['>urgent <later ~maybe', 'urgent* later* maybe*'],
    'user wildcards and proximity are stripped' => ['print* "a b" @3', 'print* a b 3'],
    'operators glued inside a word split it' => ['e-mail', 'e mail*'],
    'only operators leaves nothing' => ['+-<>()~*"@', ''],
    'whitespace is collapsed' => ["  printer \t\n jam  ", 'printer* jam*'],
    'multibyte length counts characters' => ['café crème', 'café* crème*'],
    'empty input' => ['', ''],
]);

it('keeps at most ten terms', function (): void {
    $input = implode(' ', array_map(fn (int $i): string => "word{$i}", range(1, 12)));

    expect(explode(' ', FulltextSearchTerm::toBooleanMode($input)))->toHaveCount(10);
});
```

- [ ] **Step 2: Run it, expect FAIL**

```bash
./vendor/bin/pest tests/Unit/Tickets/FulltextSearchTermTest.php
```
Expected: FAIL with `Error: Class "App\Services\Tickets\FulltextSearchTerm" not found`.

- [ ] **Step 3: Implement** `app/Services/Tickets/FulltextSearchTerm.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Tickets;

final class FulltextSearchTerm
{
    /** Every InnoDB boolean-mode operator; user input must never carry query semantics (TD-120). */
    private const OPERATORS = ['+', '-', '<', '>', '(', ')', '~', '*', '"', '@'];

    /** Matches innodb_ft_min_token_size: a shorter word with `*` would match almost everything. */
    private const MIN_PREFIX_LENGTH = 3;

    /** Bounds the cost of one MATCH and keeps pasted paragraphs from producing huge expressions. */
    private const MAX_TERMS = 10;

    public static function toBooleanMode(string $input): string
    {
        $clean = str_replace(self::OPERATORS, ' ', $input);
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        $terms = array_map(
            static fn (string $word): string => mb_strlen($word) >= self::MIN_PREFIX_LENGTH ? $word.'*' : $word,
            array_slice($words, 0, self::MAX_TERMS),
        );

        return implode(' ', $terms);
    }
}
```

- [ ] **Step 4: Run it, expect PASS**

```bash
./vendor/bin/pest tests/Unit/Tickets/FulltextSearchTermTest.php
```
Expected: PASS, 13 tests.

- [ ] **Step 5: Style and static analysis**

```bash
./vendor/bin/pint app/Services/Tickets/FulltextSearchTerm.php tests/Unit/Tickets && ./vendor/bin/phpstan analyse --memory-limit=1G app/Services/Tickets
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Tickets/FulltextSearchTerm.php tests/Unit/Tickets/FulltextSearchTermTest.php
git commit -m "feat(search): sanitize FULLTEXT boolean-mode input" --trailer "Refs: T08.1"
```

---


### T08.2 — TicketFilter

`TicketFilter` is tested directly on `Ticket::query()`, with the same raw values that `$request->validated()` produces (query-string strings for ids and booleans). The HTTP path through T05's seam is tested in T08.3.

- [ ] **Step 1: Write the failing test** `tests/Feature/Tickets/TicketFilterTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));

    $this->admin = User::factory()->admin()->create();
    $this->people = [
        'c1' => User::factory()->customer()->create(),
        'c2' => User::factory()->customer()->create(),
        'a1' => User::factory()->agent()->create(),
        'a2' => User::factory()->agent()->create(),
    ];
    $this->categories = [
        'cat1' => Category::factory()->create(),
        'cat2' => Category::factory()->create(),
    ];

    $make = function (string $number, TicketStatus $status, TicketPriority $priority, string $customer, string $category, ?string $agent, string $createdAt, string $updatedAt, string $dueAt): Ticket {
        return Ticket::factory()->status($status)->priority($priority)->create([
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
    };

    // Created at 12:00 Asia/Dhaka on each day.
    $this->tickets = [
        't1' => $make('TKT-2026-000101', TicketStatus::Open, TicketPriority::Low, 'c1', 'cat1', 'a1', '2026-10-01 06:00:00', '2026-10-28 06:00:00', '2026-11-04 06:00:00'),
        't2' => $make('TKT-2026-000102', TicketStatus::InProgress, TicketPriority::High, 'c1', 'cat2', null, '2026-10-10 06:00:00', '2026-10-05 06:00:00', '2026-11-01 06:00:00'),
        't3' => $make('TKT-2026-000103', TicketStatus::WaitingForCustomer, TicketPriority::Critical, 'c2', 'cat1', 'a2', '2026-10-20 06:00:00', '2026-10-21 06:00:00', '2026-11-02 06:00:00'),
        't4' => $make('TKT-2026-000104', TicketStatus::Resolved, TicketPriority::Medium, 'c2', 'cat2', 'a1', '2026-10-25 06:00:00', '2026-10-26 06:00:00', '2026-11-03 06:00:00'),
    ];

    $this->numbers = fn (array $keys): array => collect($keys)->map(fn (string $key): string => $this->tickets[$key]->number)->all();

    $this->run = fn (array $filters, ?User $viewer = null): Builder => app(TicketFilter::class)
        ->apply(Ticket::query(), $filters, $viewer ?? $this->admin, CarbonImmutable::now());
});

it('applies each filter on its own', function (array $filters, array $expected): void {
    $numbers = ($this->run)($filters)->pluck('number')->sort()->values()->all();

    expect($numbers)->toBe(($this->numbers)($expected));
})->with([
    'no filters' => [fn (): array => [], ['t1', 't2', 't3', 't4']],
    'exact number' => [fn (): array => ['number' => 'TKT-2026-000102'], ['t2']],
    'number prefix' => [fn (): array => ['number' => 'TKT-2026-00010'], ['t1', 't2', 't3', 't4']],
    'number is case-insensitive' => [fn (): array => ['number' => 'tkt-2026-000103'], ['t3']],
    'number LIKE metacharacters are literal' => [fn (): array => ['number' => 'TKT-2026-0001_1'], []],
    'status (multiple)' => [fn (): array => ['status' => ['open', 'in_progress']], ['t1', 't2']],
    'priority' => [fn (): array => ['priority' => ['critical']], ['t3']],
    'category' => [fn (): array => ['category_id' => $this->categories['cat1']->id], ['t1', 't3']],
    'category id as a query-string string' => [fn (): array => ['category_id' => (string) $this->categories['cat1']->id], ['t1', 't3']],
    'assigned agent' => [fn (): array => ['assigned_agent_id' => (string) $this->people['a1']->id], ['t1', 't4']],
    'unassigned' => [fn (): array => ['unassigned' => true], ['t2']],
    'unassigned as "1"' => [fn (): array => ['unassigned' => '1'], ['t2']],
    'unassigned "0" is ignored' => [fn (): array => ['unassigned' => '0'], ['t1', 't2', 't3', 't4']],
    'customer' => [fn (): array => ['customer_id' => (string) $this->people['c2']->id], ['t3', 't4']],
    'created from' => [fn (): array => ['created_from' => '2026-10-10'], ['t2', 't3', 't4']],
    'created to' => [fn (): array => ['created_to' => '2026-10-10'], ['t1', 't2']],
    'created range' => [fn (): array => ['created_from' => '2026-10-02', 'created_to' => '2026-10-24'], ['t2', 't3']],
]);

it('combines filters with AND', function (array $filters, array $expected): void {
    $numbers = ($this->run)($filters)->pluck('number')->sort()->values()->all();

    expect($numbers)->toBe(($this->numbers)($expected));
})->with([
    'status + category' => [fn (): array => ['status' => ['open', 'in_progress'], 'category_id' => $this->categories['cat1']->id], ['t1']],
    'agent + priority' => [fn (): array => ['assigned_agent_id' => $this->people['a1']->id, 'priority' => ['medium']], ['t4']],
    'customer + status + date' => [fn (): array => ['customer_id' => $this->people['c1']->id, 'status' => ['in_progress'], 'created_from' => '2026-10-05'], ['t2']],
    'unassigned + agent never matches' => [fn (): array => ['unassigned' => '1', 'assigned_agent_id' => $this->people['a1']->id], []],
]);

it('sorts by whitelisted columns', function (string $sort, array $expected): void {
    $numbers = ($this->run)(['sort' => $sort])->pluck('number')->all();

    expect($numbers)->toBe(($this->numbers)($expected));
})->with([
    'default is newest first' => ['-created_at', ['t4', 't3', 't2', 't1']],
    'created ascending' => ['created_at', ['t1', 't2', 't3', 't4']],
    'priority by rank descending' => ['-priority', ['t3', 't2', 't4', 't1']],
    'priority by rank ascending' => ['priority', ['t1', 't4', 't2', 't3']],
    'due soonest first' => ['sla_due_at', ['t2', 't3', 't4', 't1']],
    'recently updated first' => ['-updated_at', ['t1', 't4', 't3', 't2']],
]);

it('treats created dates as whole days in the business timezone', function (): void {
    // 2026-10-09 19:00 UTC is 2026-10-10 01:00 in Asia/Dhaka.
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

it('falls back to LIKE on drivers without FULLTEXT', function (): void {
    config(['database.connections.fulltext_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $query = app(TicketFilter::class)->apply(Ticket::on('fulltext_probe'), ['q' => '50%_off'], $this->admin, CarbonImmutable::now());

    expect(strtolower($query->toSql()))->toContain('like')->not->toContain('match')
        ->and($query->getBindings())->toContain('%50\%\_off%');
})->skip(fn (): bool => ! extension_loaded('pdo_sqlite'), 'pdo_sqlite is not installed');

it('ignores a keyword that is only operators', function (): void {
    expect(($this->run)(['q' => '+-()"'])->count())->toBe(4);
});
```


- [ ] **Step 2: Write the failing test** `tests/Feature/Tickets/TicketSlaFilterTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $now = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
    $this->travelTo($now);
    $this->admin = User::factory()->admin()->create();

    $make = fn (string $number, TicketStatus $status, string $warning, string $due, ?string $paused = null): Ticket => Ticket::factory()
        ->status($status)
        ->create([
            'number' => $number,
            'sla_warning_at' => $now->modify($warning),
            'sla_due_at' => $now->modify($due),
            'sla_paused_at' => $paused === null ? null : $now->modify($paused),
        ]);

    $this->tickets = [
        'within' => $make('TKT-2026-000201', TicketStatus::InProgress, '+2 hours', '+4 hours'),
        'approaching' => $make('TKT-2026-000202', TicketStatus::InProgress, '-1 hour', '+1 hour'),
        'breached' => $make('TKT-2026-000203', TicketStatus::Open, '-3 hours', '-1 hour'),
        // Past its due time, but paused: paused wins, so it isn't reported as breached.
        'paused' => $make('TKT-2026-000204', TicketStatus::WaitingForCustomer, '-3 hours', '-1 hour', '-30 minutes'),
        // Past its due time, but finished: stopped wins.
        'stopped' => $make('TKT-2026-000205', TicketStatus::Resolved, '-3 hours', '-1 hour'),
    ];
});

it('filters by SLA state over HTTP using the current time', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->getJson(route('api.v1.tickets.index', ['sla_state' => 'breached', 'scope' => 'active']))->assertOk();

    expect(collect($response->json('data'))->pluck('number')->all())->toBe([$this->tickets['breached']->number]);
});
```

- [ ] **Step 3: Run both, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Tickets/TicketFilterTest.php tests/Feature/Tickets/TicketSlaFilterTest.php
```
Expected: FAIL with `Illuminate\Contracts\Container\BindingResolutionException: Target class [App\Services\Tickets\TicketFilter] does not exist.` (`TicketSlaFilterTest` holds only the HTTP test, which still fails after Step 4; it passes in T08.3.)


- [ ] **Step 4: Implement** `app/Services/Tickets/TicketFilter.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class TicketFilter
{
    /** Sortable columns. Anything else is rejected by IndexTicketsRequest (TD-120). */
    public const SORTS = ['created_at', 'updated_at', 'sla_due_at', 'priority'];

    public const DEFAULT_SORT = '-created_at';

    private const FULLTEXT_DRIVERS = ['mysql', 'mariadb'];

    /**
     * @return list<string>
     */
    public static function sortOptions(): array
    {
        $options = [];
        foreach (self::SORTS as $column) {
            $options[] = $column;
            $options[] = '-'.$column;
        }

        return $options;
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string,mixed>  $filters  validated (raw query-string values; normalized here)
     * @return Builder<Ticket>
     */
    public function apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder
    {
        // First, so no later filter (customer_id included) can widen what a customer may see (TD-012).
        // Idempotent when TicketListQuery already applied it; required when TicketFilter is used on its own.
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
        $this->sort($query, is_string($sort) && $sort !== '' ? $sort : self::DEFAULT_SORT);

        return $query;
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function number(Builder $query, mixed $number): void
    {
        if (! is_string($number) || trim($number) === '') {
            return;
        }

        // A prefix search on the unique index; an exact number is its own prefix.
        $query->where('tickets.number', 'like', $this->escapeLike(Str::upper(trim($number))).'%');
    }

    /**
     * @param  Builder<Ticket>  $query
     */
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

        // Non-MySQL drivers (local tooling only): a full scan, acceptable off the production path (TD-004).
        $needle = '%'.$this->escapeLike(trim($input)).'%';
        $query->where(static function (Builder $where) use ($needle): void {
            $where->where('tickets.subject', 'like', $needle)->orWhere('tickets.description', 'like', $needle);
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     */
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

    /**
     * @param  Builder<Ticket>  $query
     */
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

    /**
     * @param  Builder<Ticket>  $query
     */
    private function createdBetween(Builder $query, mixed $from, mixed $to): void
    {
        // The user means calendar days where the business operates, not UTC days.
        $timezone = (string) config('business_hours.timezone');

        if (is_string($from) && $from !== '') {
            $query->where('tickets.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }

        if (is_string($to) && $to !== '') {
            $query->where('tickets.created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function sort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTS, true)) {
            [$column, $direction] = ['created_at', 'desc'];
        }

        if ($column === 'priority') {
            // Sort by business rank, not alphabetically; values are bound, the direction is from a fixed set.
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

        // A unique tie-breaker keeps offset pagination stable.
        $query->orderBy('tickets.id', $direction);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
```

- [ ] **Step 5: Run both, expect PASS except the HTTP test**

```bash
./vendor/bin/pest tests/Feature/Tickets/TicketFilterTest.php tests/Feature/Tickets/TicketSlaFilterTest.php
```
Expected: 31 passed (or 30 passed and 1 skipped without `pdo_sqlite`), 1 failed: `filters by SLA state over HTTP using the current time`. T05's `applyFilters()` doesn't call `TicketFilter` yet; T08.3 fixes that.

- [ ] **Step 6: Style and static analysis**

```bash
./vendor/bin/pint app/Services/Tickets tests/Feature/Tickets && ./vendor/bin/phpstan analyse --memory-limit=1G app/Services/Tickets
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit.** The HTTP test is committed in T08.3, so the branch stays green.

```bash
git add app/Services/Tickets/TicketFilter.php tests/Feature/Tickets/TicketFilterTest.php
git commit -m "feat(search): add ticket filter with keyword, SLA state and sort" --trailer "Refs: T08.2"
```

---

### T08.3 — Plug TicketFilter into T05's seam

- [ ] **Step 1: Write the failing test** `tests/Feature/Tickets/TicketIndexFilteringTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Tickets\TicketListQuery;
use App\Services\Tickets\TicketPageData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();
    $this->other = User::factory()->customer()->create();

    $this->own = Ticket::factory()->status(TicketStatus::Open)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $this->foreign = Ticket::factory()->status(TicketStatus::Open)->create([
        'customer_id' => $this->other->id,
        'created_by_id' => $this->other->id,
    ]);
});

it('never lets a customer widen visibility through customer_id', function (): void {
    Sanctum::actingAs($this->customer, ['*']);

    $this->getJson(route('api.v1.tickets.index', ['customer_id' => $this->other->id]))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->customer)
        ->get(route('tickets.index', ['customer_id' => $this->other->id]))
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Index')->has('tickets.data', 0));

    $this->getJson(route('api.v1.tickets.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $this->own->number);
});

it('lets staff filter by customer', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.tickets.index', ['customer_id' => $this->other->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $this->foreign->number);
});

it('rejects a sort column outside the whitelist', function (string $sort): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.tickets.index', ['sort' => $sort]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
})->with(['password', 'customer_id', 'created_at; drop table tickets', '--created_at', 'subject']);

it('redirects back with errors for an invalid web sort', function (): void {
    $this->actingAs($this->admin)
        ->from(route('tickets.index'))
        ->get(route('tickets.index', ['sort' => 'password']))
        ->assertRedirect(route('tickets.index'))
        ->assertSessionHasErrors('sort');
});

it('validates the other filters', function (array $query, string $field): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.tickets.index', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown status' => [['status' => ['lost']], 'status.0'],
    'status not a list' => [['status' => 'open'], 'status'],
    'unknown priority' => [['priority' => ['urgent']], 'priority.0'],
    'unknown sla state' => [['sla_state' => 'late'], 'sla_state'],
    'bad date' => [['created_from' => '29/10/2026'], 'created_from'],
    'reversed range' => [['created_from' => '2026-10-10', 'created_to' => '2026-10-01'], 'created_to'],
    'missing category' => [['category_id' => 999999], 'category_id'],
    'keyword too long' => [['q' => str_repeat('a', 201)], 'q'],
]);

it('re-hydrates the filter bar from the validated filters prop', function (): void {
    $this->actingAs($this->admin)
        ->get(route('tickets.index', ['status' => ['open'], 'sort' => '-priority', 'q' => 'printer']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Index')
            ->where('filters.status', ['open'])
            ->where('filters.sort', '-priority')
            ->where('filters.q', 'printer'));
});

it('keeps the query string in pagination links', function (): void {
    Ticket::factory()->count(3)->status(TicketStatus::Open)->create();
    Sanctum::actingAs($this->admin, ['*']);

    $next = $this->getJson(route('api.v1.tickets.index', ['per_page' => 2, 'status' => ['open']]))->json('links.next');

    expect(urldecode((string) $next))->toContain('status[0]=open')->toContain('per_page=2');
});

it('keeps column-limited eager loading and a visible-only reply count with filters active', function (): void {
    $agent = User::factory()->agent()->create();
    TicketReply::factory()->for($this->own)->for($agent, 'author')->create();
    TicketReply::factory()->for($this->own)->for($agent, 'author')->internal()->create();

    $filters = ['number' => $this->own->number, 'sort' => '-priority'];
    $forStaff = app(TicketListQuery::class)->paginate($this->admin, $filters, CarbonImmutable::now())->items()[0];
    $forCustomer = app(TicketListQuery::class)->paginate($this->customer, $filters, CarbonImmutable::now())->items()[0];

    expect($forStaff->relationLoaded('category'))->toBeTrue()
        ->and($forStaff->relationLoaded('assignedAgent'))->toBeTrue()
        ->and(array_keys($forStaff->customer->getAttributes()))->toBe(['id', 'name'])
        ->and($forStaff->replies_count)->toBe(2)
        ->and($forCustomer->replies_count)->toBe(1);
});

it('runs a constant number of queries for 5 and 50 tickets with filters active', function (): void {
    $agent = User::factory()->agent()->create();
    $seed = function (int $count) use ($agent): void {
        Ticket::factory()->count($count)->status(TicketStatus::InProgress)->assignedTo($agent)->create()
            ->each(function (Ticket $ticket) use ($agent): void {
                TicketReply::factory()->count(2)->for($ticket)->for($agent, 'author')->create();
            });
    };
    $query = ['per_page' => 100, 'status' => ['open', 'in_progress'], 'sort' => '-priority', 'created_from' => '2020-01-01'];
    $measure = function () use ($query): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('api.v1.tickets.index', $query))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    Sanctum::actingAs($this->admin, ['*']);
    $seed(3); // plus the 2 tickets from beforeEach = 5
    $measure(); // warm the permission cache so both measured runs start equal
    $five = $measure();

    $seed(45);
    $fifty = $measure();

    expect($fifty)->toBe($five);
});

it('builds filter options, with people filters for staff only', function (): void {
    $agent = User::factory()->agent()->create(['name' => 'Agent Zed']);
    $pages = app(TicketPageData::class);

    $staff = $pages->filterOptions($this->admin);
    $customer = $pages->filterOptions($this->customer);

    expect($staff['showPeopleFilters'])->toBeTrue()
        ->and(collect($staff['agents'])->pluck('id')->all())->toContain($agent->id)
        ->and($staff['categories'])->toBeArray()
        ->and($customer['showPeopleFilters'])->toBeFalse()
        ->and($customer['agents'])->toBe([]);
});
```

- [ ] **Step 2: Run it, expect FAIL**

```bash
./vendor/bin/pest tests/Feature/Tickets/TicketIndexFilteringTest.php tests/Feature/Tickets/TicketSlaFilterTest.php
```
Expected: FAIL, in four ways:
- `never lets a customer widen visibility` fails at `assertJsonCount(0, 'data')`, because the unvalidated `customer_id` isn't applied.
- The sort rows and the new validation rows fail with `Expected response status code [422] but received 200.`
- `builds filter options` fails with `Call to undefined method App\Services\Tickets\TicketPageData::filterOptions()`.
- The SLA HTTP test still fails.

- [ ] **Step 3: Modify** `app/Http/Requests/Tickets/IndexTicketsRequest.php`. Replace **only** the `rules()` method. The first three entries are T05's, verbatim. Add the imports `App\Enums\SlaState`, `App\Enums\TicketPriority`, `App\Enums\TicketStatus` and `App\Services\Tickets\TicketFilter`; `Illuminate\Validation\Rule` is already imported. Update the class docblock to `TD-080 filter rules added by T08; scope, page and per_page are T05's.`

```php
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // T05, unchanged.
            'scope' => ['sometimes', 'string', Rule::in(['active', 'resolved'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tickets.per_page.max', 100)],
            // T08 (TD-080).
            'number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', 'array'],
            'priority.*' => ['string', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('categories', 'id')],
            // No `exists`: a 422 versus a 200 would let customers enumerate user ids.
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
            'sort' => ['sometimes', Rule::in(TicketFilter::sortOptions())],
        ];
    }
```

- [ ] **Step 4: Modify** `app/Services/Tickets/TicketListQuery.php`, three edits and nothing else
  1. Constructor: replace
     ```php
         public function __construct(
             private readonly Config $config,
         ) {}
     ```
     with
     ```php
         public function __construct(
             private readonly Config $config,
             private readonly TicketFilter $filter,
         ) {}
     ```
     `TicketFilter` is in the same namespace (`App\Services\Tickets`), so it needs no import.
  2. Replace the whole `applyFilters()` method, docblock included, with:
     ```php
         /**
          * The TD-080 filters and the order, delegated to TicketFilter (T08).
          *
          * @param  Builder<Ticket>  $query
          * @param  array<string, mixed>  $validated
          * @return Builder<Ticket>
          */
         private function applyFilters(Builder $query, array $validated, User $viewer, CarbonImmutable $now): Builder
         {
             return $this->filter->apply($query, $validated, $viewer, $now);
         }
     ```
  3. In the class docblock, replace `T08 plugs TicketFilter into applyFilters(); visibility, scope, eager loading and pagination stay here.` with `TicketFilter (T08) applies the filters and the order in applyFilters(); visibility, scope, eager loading and pagination stay here.`

- [ ] **Step 5: Modify** `app/Services/Tickets/TicketPageData.php` by adding this method after `formOptions()`, and add the imports `App\Enums\Role`, plus `App\Models\Ticket` if it isn't already imported. Nothing else changes.

```php
    /**
     * Options for the ticket list filter bar. Staff-only lists use the createOnBehalf ability as the
     * "is staff" signal, so no role is checked here (TD-010).
     *
     * @return array{categories: list<array{id: int, name: string}>, agents: list<array{id: int, name: string}>, showPeopleFilters: bool}
     */
    public function filterOptions(User $viewer): array
    {
        $isStaff = $viewer->can('createOnBehalf', Ticket::class);

        return [
            'categories' => $this->formOptions()['categories'],
            // All agents, including unavailable ones: the filter is about past and present assignments.
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

- [ ] **Step 6: Run the T08 tests and T05's ticket tests, expect PASS**

```bash
./vendor/bin/pest tests/Feature/Tickets tests/Feature/Http tests/Feature/Performance/TicketQueryCountTest.php
```
Expected: everything passes:
- `TicketIndexFilteringTest`: 21;
- `TicketSlaFilterTest`: 1;
- `TicketFilterTest`: 31;
- T05's `TicketReadLayerTest`, `TicketPagesTest`, `TicketApiTest` and `TicketQueryCountTest`. Their defaults are kept: `scope=active` and newest first.

If a T05 test fails, the seam was changed beyond the three edits above. Revert to the three edits; don't change T05's tests.

- [ ] **Step 7: Style and static analysis**

```bash
./vendor/bin/pint app tests/Feature/Tickets && ./vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Tickets/IndexTicketsRequest.php app/Services/Tickets/TicketListQuery.php \
  app/Services/Tickets/TicketPageData.php tests/Feature/Tickets/TicketIndexFilteringTest.php \
  tests/Feature/Tickets/TicketSlaFilterTest.php
git commit -m "feat(search): apply ticket filter to the ticket list query" --trailer "Refs: T08.3"
```

---

### T08.4 — Keyword search on real MySQL FULLTEXT

**Why this test can't use `RefreshDatabase`:** `RefreshDatabase` wraps every test in a transaction that is rolled back, so the rows the test inserts are never committed. InnoDB's FULLTEXT index only sees committed rows: `MATCH … AGAINST` inside the same, still-open transaction returns nothing. A test asserting on it would fail, or pass for the wrong reason. This file therefore uses `DatabaseMigrations`. That runs `migrate:fresh` before each test (no wrapping transaction, so inserts commit and get indexed), runs `migrate:rollback` afterwards, and resets `RefreshDatabaseState::$migrated`. The next `RefreshDatabase` test in the same process therefore re-migrates instead of finding leftover rows. `DatabaseTruncation` would also work, but it leaves committed rows behind for the next `RefreshDatabase` test, which would break that test's counts. The cost is two migration runs per test, so this file stays small. It lives in `tests/Database/`, the suite T00's `phpunit.xml` provides for this test. That directory is outside `tests/Feature/`, which matters because `tests/Pest.php` (T01) applies `RefreshDatabase` to everything in `Feature`, and the two traits can't be combined in one class.

- [ ] **Step 1: Write the test** `tests/Database/TicketKeywordSearchTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create();

    $make = fn (string $number, string $subject, string $description, ?User $owner = null): Ticket => Ticket::factory()
        ->status(TicketStatus::Open)
        ->create(array_filter([
            'number' => $number,
            'subject' => $subject,
            'description' => $description,
            'customer_id' => $owner?->id,
            'created_by_id' => $owner?->id,
        ], static fn (mixed $value): bool => $value !== null));

    // Committed immediately: there is no wrapping transaction in this file.
    $this->printer = $make('TKT-2026-000301', 'Printer jammed on floor three', 'Paper stuck in tray two.', $this->customer);
    $this->invoice = $make('TKT-2026-000302', 'Invoice duplicated', 'The printer driver also crashed after the update.');
    $this->vpn = $make('TKT-2026-000303', 'Cannot reach VPN', 'Connection drops every hour.');

    $this->search = fn (string $q, ?User $viewer = null): array => app(TicketFilter::class)
        ->apply(Ticket::query(), ['q' => $q], $viewer ?? $this->admin, CarbonImmutable::now())
        ->pluck('number')
        ->sort()
        ->values()
        ->all();
});

it('finds matches in subject or description', function (): void {
    expect(($this->search)('printer'))->toBe([$this->printer->number, $this->invoice->number])
        ->and(($this->search)('invoice'))->toBe([$this->invoice->number]);
});

it('matches word prefixes', function (): void {
    expect(($this->search)('print'))->toBe([$this->printer->number, $this->invoice->number])
        ->and(($this->search)('conn'))->toBe([$this->vpn->number]);
});

it('neutralises boolean operators typed by the user', function (): void {
    // Unsanitized, "-invoice" would exclude the invoice ticket and "+" would make terms required.
    expect(($this->search)('+printer -invoice'))->toBe([$this->printer->number, $this->invoice->number]);
});

it('survives unbalanced quotes and parentheses without a SQL error', function (): void {
    expect(($this->search)('"printer (jam'))->toBe([$this->printer->number, $this->invoice->number])
        ->and(($this->search)('*@~<>'))->toHaveCount(3);
});

it('still applies visibility to keyword results', function (): void {
    expect(($this->search)('printer', $this->customer))->toBe([$this->printer->number]);
});
```

- [ ] **Step 2: Run it**

```bash
./vendor/bin/pest tests/Database/TicketKeywordSearchTest.php
```
Expected: PASS, 5 tests (the implementation came from T08.2). Check that this run really exercises the index: temporarily swap `DatabaseMigrations::class` for `Illuminate\Foundation\Testing\RefreshDatabase::class`, rerun, and expect `finds matches in subject or description` to FAIL with `Failed asserting that two arrays are identical` (empty results inside the uncommitted transaction). Then revert the swap.

- [ ] **Step 3: Confirm it doesn't pollute the RefreshDatabase suite**

```bash
./vendor/bin/pest tests/Database/TicketKeywordSearchTest.php tests/Feature/Tickets/TicketIndexFilteringTest.php
```
Expected: PASS for both files in one process (the `assertJsonCount` totals in `TicketIndexFilteringTest` still see only its own tickets).

- [ ] **Step 4: Style, then commit**

```bash
./vendor/bin/pint tests/Database
git add tests/Database/TicketKeywordSearchTest.php
git commit -m "test(search): cover FULLTEXT keyword search on committed rows" --trailer "Refs: T08.4"
```

---


### T08.5 — Filter bar UI (replaces T05's `TicketFilterBar.vue` stub)

- [ ] **Step 1: Baseline build and type check.** Build first: the kit's Wayfinder Vite plugin generates the gitignored `resources/js/{routes,actions,wayfinder}` during the build. Without them, `vue-tsc` fails with `TS2307: Cannot find module '@/routes'` in the kit's own components.

```bash
npm run build && npx vue-tsc --noEmit
```
Expected: PASS (exit 0).

- [ ] **Step 2: Create** `resources/js/types/ticket-filters.ts`

```ts
import type { AgentOption, CategorySummary } from '@/types/tickets';

/** TicketPageData::filterOptions(). Optional on the page until the controller passes it (Contract note 2). */
export type TicketFilterOptions = {
    categories: CategorySummary[];
    agents: AgentOption[];
    showPeopleFilters: boolean;
};
```

- [ ] **Step 3: Replace** `resources/js/components/tickets/TicketFilterBar.vue` (T05's two props are kept as they are; `options` is added as optional)

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TicketFilterOptions } from '@/types/ticket-filters';
import type { SharedProps } from '@/types/shared';
import type { Option } from '@/types/tickets';
import { router, usePage } from '@inertiajs/vue3';
import { RotateCcw, Search } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';

const props = defineProps<{
    scope: 'active' | 'resolved';
    filters: Record<string, unknown>;
    options?: TicketFilterOptions;
}>();

const DEFAULT_SORT = '-created_at';

// Mirrors the PHP enums; the server validates every value (IndexTicketsRequest).
const STATUSES: Record<'active' | 'resolved', Option[]> = {
    active: [
        { value: 'open', label: 'Open' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'waiting_for_customer', label: 'Waiting for customer' },
    ],
    resolved: [
        { value: 'resolved', label: 'Resolved' },
        { value: 'closed', label: 'Closed' },
    ],
};
const PRIORITIES: Option[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];
const SLA_STATES: Option[] = [
    { value: 'within', label: 'Within SLA' },
    { value: 'approaching', label: 'Approaching' },
    { value: 'breached', label: 'Breached' },
    { value: 'paused', label: 'Paused' },
    { value: 'stopped', label: 'Stopped' },
];
const SORTS: Option[] = [
    { value: '-created_at', label: 'Newest first' },
    { value: 'created_at', label: 'Oldest first' },
    { value: 'sla_due_at', label: 'Due soonest' },
    { value: '-priority', label: 'Highest priority' },
    { value: '-updated_at', label: 'Recently updated' },
];

type FilterState = {
    number: string;
    q: string;
    status: string[];
    priority: string[];
    category_id: string;
    assigned_agent_id: string;
    unassigned: boolean;
    customer_id: string;
    created_from: string;
    created_to: string;
    sla_state: string;
    sort: string;
};

// `filters` is the raw validated query: strings, string arrays, or absent.
const text = (value: unknown): string => (typeof value === 'string' || typeof value === 'number' ? String(value) : '');
const list = (value: unknown): string[] => (Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : []);
const flag = (value: unknown): boolean => value === true || value === '1' || value === 1 || value === 'true';

function fromFilters(filters: Record<string, unknown>): FilterState {
    return {
        number: text(filters.number),
        q: text(filters.q),
        status: list(filters.status),
        priority: list(filters.priority),
        category_id: text(filters.category_id),
        assigned_agent_id: text(filters.assigned_agent_id),
        unassigned: flag(filters.unassigned),
        customer_id: text(filters.customer_id),
        created_from: text(filters.created_from),
        created_to: text(filters.created_to),
        sla_state: text(filters.sla_state),
        sort: text(filters.sort) || DEFAULT_SORT,
    };
}

const page = usePage<SharedProps>();
const state = reactive<FilterState>(fromFilters(props.filters));

const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const path = computed(() => page.url.split('?')[0]);
const statuses = computed(() => STATUSES[props.scope]);
// The createOnBehalf ability is the shared "staff" signal (T04); options refine it when present.
const isStaff = computed(() => props.options?.showPeopleFilters ?? page.props.auth.can.createOnBehalf);

// Only non-empty values go into the URL, so links stay short and shareable; scope is always kept.
function toQuery(s: FilterState): Record<string, string | string[]> {
    const query: Record<string, string | string[]> = { scope: props.scope };
    const scalar: (keyof FilterState)[] = ['number', 'q', 'category_id', 'assigned_agent_id', 'customer_id', 'created_from', 'created_to', 'sla_state'];

    for (const key of scalar) {
        const value = String(s[key]).trim();
        if (value !== '') {
            query[key] = value;
        }
    }
    if (s.status.length > 0) {
        query.status = [...s.status];
    }
    if (s.priority.length > 0) {
        query.priority = [...s.priority];
    }
    if (s.unassigned) {
        query.unassigned = '1';
    }
    if (s.sort !== DEFAULT_SORT) {
        query.sort = s.sort;
    }

    return query;
}

let timer: ReturnType<typeof setTimeout> | undefined;

function visit(): void {
    router.get(path.value, toQuery(state), {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
}

// One debounced visit per burst of typing or clicking; `page` is not carried, so results restart at page 1.
watch(
    state,
    () => {
        clearTimeout(timer);
        timer = setTimeout(visit, 300);
    },
    { deep: true },
);

onBeforeUnmount(() => clearTimeout(timer));

function toggle(values: string[], value: string): void {
    const index = values.indexOf(value);
    if (index === -1) {
        values.push(value);
    } else {
        values.splice(index, 1);
    }
}

function onUnassignedChange(): void {
    if (state.unassigned) {
        state.assigned_agent_id = '';
    }
}

function reset(): void {
    Object.assign(state, fromFilters({}));
}

const hasActiveFilters = computed(() => Object.keys(toQuery(state)).some((key) => key !== 'scope'));

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50';
const chipClass = (active: boolean): string =>
    [
        'rounded-full border px-3 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
        active ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background hover:bg-accent',
    ].join(' ');
</script>

<template>
    <section aria-label="Filter tickets" class="flex flex-col gap-4 rounded-lg border border-border bg-card p-4">
        <div class="grid gap-3 md:grid-cols-[1fr_14rem_12rem]">
            <div class="grid gap-1.5">
                <Label for="filter-q">Keyword</Label>
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <Input id="filter-q" v-model="state.q" type="search" placeholder="Subject or description" class="pl-8" maxlength="200" />
                </div>
                <p v-if="errors.q" class="text-xs text-destructive">{{ errors.q }}</p>
            </div>
            <div class="grid gap-1.5">
                <Label for="filter-number">Ticket ID</Label>
                <Input id="filter-number" v-model="state.number" placeholder="TKT-2026-000123" maxlength="32" />
            </div>
            <div class="grid gap-1.5">
                <Label for="filter-sort">Sort</Label>
                <select id="filter-sort" v-model="state.sort" :class="selectClass">
                    <option v-for="option in SORTS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p v-if="errors.sort" class="text-xs text-destructive">{{ errors.sort }}</p>
            </div>
        </div>

        <fieldset class="flex flex-wrap items-center gap-2">
            <legend class="mb-1.5 text-sm font-medium">Status</legend>
            <button
                v-for="option in statuses"
                :key="option.value"
                type="button"
                :class="chipClass(state.status.includes(option.value))"
                :aria-pressed="state.status.includes(option.value)"
                @click="toggle(state.status, option.value)"
            >
                {{ option.label }}
            </button>
        </fieldset>

        <fieldset class="flex flex-wrap items-center gap-2">
            <legend class="mb-1.5 text-sm font-medium">Priority</legend>
            <button
                v-for="option in PRIORITIES"
                :key="option.value"
                type="button"
                :class="chipClass(state.priority.includes(option.value))"
                :aria-pressed="state.priority.includes(option.value)"
                @click="toggle(state.priority, option.value)"
            >
                {{ option.label }}
            </button>
        </fieldset>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-if="options && options.categories.length > 0" class="grid gap-1.5">
                <Label for="filter-category">Category</Label>
                <select id="filter-category" v-model="state.category_id" :class="selectClass">
                    <option value="">All categories</option>
                    <option v-for="category in options.categories" :key="category.id" :value="String(category.id)">{{ category.name }}</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="filter-sla">SLA</Label>
                <select id="filter-sla" v-model="state.sla_state" :class="selectClass">
                    <option value="">Any SLA state</option>
                    <option v-for="option in SLA_STATES" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="filter-from">Created from</Label>
                <Input id="filter-from" v-model="state.created_from" type="date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="filter-to">Created to</Label>
                <Input id="filter-to" v-model="state.created_to" type="date" :min="state.created_from || undefined" />
                <p v-if="errors.created_to" class="text-xs text-destructive">{{ errors.created_to }}</p>
            </div>
        </div>

        <div v-if="isStaff" class="grid gap-3 sm:grid-cols-3">
            <div v-if="options && options.agents.length > 0" class="grid gap-1.5">
                <Label for="filter-agent">Assigned agent</Label>
                <select id="filter-agent" v-model="state.assigned_agent_id" :class="selectClass" :disabled="state.unassigned">
                    <option value="">Any agent</option>
                    <option v-for="agent in options.agents" :key="agent.id" :value="String(agent.id)">{{ agent.name }}</option>
                </select>
            </div>
            <div class="flex items-end gap-2 pb-2">
                <input id="filter-unassigned" v-model="state.unassigned" type="checkbox" class="size-4 rounded border-input" @change="onUnassignedChange" />
                <Label for="filter-unassigned">Unassigned only</Label>
            </div>
            <div class="grid gap-1.5">
                <Label for="filter-customer">Customer ID</Label>
                <Input id="filter-customer" v-model="state.customer_id" type="number" min="1" inputmode="numeric" />
            </div>
        </div>

        <div class="flex justify-end">
            <Button type="button" variant="ghost" size="sm" :disabled="!hasActiveFilters" @click="reset">
                <RotateCcw class="size-4" aria-hidden="true" />
                Clear filters
            </Button>
        </div>
    </section>
</template>
```

- [ ] **Step 4: Modify** `resources/js/pages/tickets/Index.vue` (T05 Step 36), making exactly three edits
  1. After `import type { Paginated, TicketListItem } from '@/types/tickets';`, add:
     ```ts
     import type { TicketFilterOptions } from '@/types/ticket-filters';
     ```
  2. In `defineProps<{ … }>()`, after the line `filters: Record<string, unknown>;`, add:
     ```ts
     filterOptions?: TicketFilterOptions;
     ```
  3. Replace
     ```vue
     <TicketFilterBar :scope="props.scope" :filters="props.filters" />
     ```
     with
     ```vue
     <TicketFilterBar :scope="props.scope" :filters="props.filters" :options="props.filterOptions" />
     ```

- [ ] **Step 4b: Pass `filterOptions` from the web controller** (the one line README §4 allows)

In `app/Http/Controllers/Web/TicketController.php`, add this as the last entry of the `Inertia::render('tickets/Index', [...])` array in `index()`. Change nothing else in the file:
```php
            'filterOptions' => $this->pages->filterOptions($this->user($request)),
```
Then check that it is the only change: `git diff --stat app/Http/Controllers/Web/TicketController.php` shows `1 insertion(+)`.

- [ ] **Step 5: Build and type check, expect PASS**

```bash
npm run build && npx vue-tsc --noEmit
```
Expected: Vite prints `✓ built in …`; `vue-tsc` exits 0.

- [ ] **Step 6: Run T05's page test, expect PASS** (the stub's `data-stub` marker is gone, and T05's tests don't assert it)

```bash
./vendor/bin/pest tests/Feature/Http/Web/TicketPagesTest.php
```
Expected: PASS.

- [ ] **Step 7: Manual smoke run** (`composer run dev`, as `admin@example.com`)
  - Type `print` in Keyword. After about 300 ms the URL becomes `/tickets?scope=active&q=print`, the list narrows, and the input keeps focus (`preserveState`).
  - Toggle *Critical*, then pick *Breached*. The URL gains `priority[]=critical&sla_state=breached`. Back leaves the page rather than stepping through each change (`replace: true`).
  - Reload: the bar is restored from the `filters` prop. Switch to *Resolved & closed*: the status chips change to Resolved and Closed.
  - As `customer1@example.com`: the unassigned and customer controls are absent.
  - The category and agent selects are shown for staff (Step 4b passes `filterOptions`) and hidden for customers.

- [ ] **Step 8: Commit**

```bash
git add resources/js/types/ticket-filters.ts resources/js/components/tickets/TicketFilterBar.vue resources/js/pages/tickets/Index.vue app/Http/Controllers/Web/TicketController.php
git commit -m "feat(search): add filter bar with query-string sync" --trailer "Refs: T08.5"
```

---

## 6. Acceptance criteria

- [ ] `TicketFilter::apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder` matches README §3.4.
- [ ] Only T05's seam is changed:
  - `TicketListQuery`: the constructor and the `applyFilters()` body;
  - `IndexTicketsRequest::rules()`, keeping `scope`, `page` and `per_page`;
  - the `TicketFilterBar.vue` stub;
  - plus `TicketPageData::filterOptions()` and three lines in `Index.vue`.

  No controller or route file is modified.
- [ ] Visibility comes first (`TicketListQuery` and `TicketFilter`). A customer passing another customer's `customer_id` gets an empty list on the web and the API.
- [ ] Every filter from spec §12 works on its own and in combination, including query-string string values (dataset tests). `number` is a case-insensitive prefix match with LIKE metacharacters escaped.
- [ ] `q`:
  - On MySQL/MariaDB it uses `MATCH(subject, description) AGAINST(? IN BOOLEAN MODE)`. The input is sanitized: `+-<>()~*"@` are stripped, words of 3 or more characters get `*`, and at most 10 terms are kept.
  - Other drivers use an escaped `LIKE`.
  - It is proven on committed rows in `tests/Database` (`DatabaseMigrations`).
- [ ] `sla_state` goes through `SlaQuery::whereState` (T05's `SlaQueryTest` covers the five states); the HTTP test proves it uses the current time.
- [ ] `sort` accepts only the whitelist (with `-` for descending); anything else → 422. The default stays newest first. `priority` sorts by rank; `id` breaks ties.
- [ ] `per_page` is still capped by T05's rule and clamp. Pagination links keep the query string.
- [ ] Column-limited eager loading and a visible-only `replies_count` hold with filters active. The query count is identical for 5 and 50 tickets.
- [ ] `TicketFilterBar.vue` keeps T05's `scope` and `filters` props, and syncs the query string through `router.get(path, query, { preserveState: true, replace: true })`. People filters are shown to staff only. There's no `v-html`.

## 7. Verification

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
```
Expected:
- Pint shows no changes.
- PHPStan prints `[OK] No errors`.
- Pest is all green. `pest --parallel` runs the Unit, Feature, Concurrency and Database suites (T00's `phpunit.xml`), including:
  - `tests/Unit/Tickets/FulltextSearchTermTest.php` (13);
  - `tests/Feature/Tickets/TicketFilterTest.php` (31; one test may be skipped without `pdo_sqlite`);
  - `TicketSlaFilterTest.php` (1);
  - `TicketIndexFilteringTest.php` (21);
  - `tests/Database/TicketKeywordSearchTest.php` (5);
  - T05's ticket tests, unchanged.
- The build and `vue-tsc` exit 0.

## Contract notes for controller

1. Resolved: T08 follows T05's seam (T05 §3 "Seam for T08"):
   - `TicketListQuery` gets a `TicketFilter` constructor dependency, and `applyFilters()` delegates to it;
   - `IndexTicketsRequest::rules()` gains the TD-080 rules, keeping `scope`, `page` and `per_page`;
   - `TicketFilterBar.vue` replaces the stub.

   No controller changes. The earlier `baseIndexQuery()` assumption is gone.
2. Resolved: README §4 allows T08 exactly one line in `Web\TicketController::index()`, which passes `filterOptions` (T08.5 Step 4b). `TicketPageData::filterOptions(User)` is tested, and the bar's category and agent selects are shown for staff.
3. Resolved: T00's `phpunit.xml` already has the `Database` suite (`tests/Database`), so `pest --parallel` runs the FULLTEXT test. `tests/Pest.php` (T01) configures only `Feature` and `Concurrency`, so the file declares `uses(TestCase::class, DatabaseMigrations::class)` itself.
4. Resolved: "staff" in the filter options is `$viewer->can('createOnBehalf', Ticket::class)` (T04 policy). In the bar it's `auth.can.createOnBehalf`. No role checks and no `isStaff()`.
5. Spec §12's signature `TicketFilter::apply(Builder, array $validated)` omits `$viewer` and `$now`. T08 follows README §3.4, which includes them.
6. Resolved: `replies_count` counting only visible replies is T05's `TicketListQuery` behaviour, and T08's seam test keeps it with filters active.
7. `status[]` narrows within the scope's statuses, because `TicketListQuery` applies the scope first. The bar therefore offers only the current scope's statuses. An API client sending `scope=active&status[]=closed` gets an empty page, not an error.
