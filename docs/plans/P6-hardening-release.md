# P6 — Hardening and release

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Session steps (S0, S1) are done by the controller itself. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** prove the performance and security claims with tests, publish admin-gated Scramble API docs, finish the project docs, and close with a whole-project review.
**Depends on:** P5 (and through it P1–P4). `dedoc/scramble` ^0.13 is already in `composer.json`.
**Outcome:** the hot queries are shown by EXPLAIN to use their TD-021 indexes, list endpoints are guarded against N+1 queries, every TD-120 measure maps to exactly one passing test, `/docs/api` serves the OpenAPI spec to admins (open in `local`), `docs/api/openapi.json` is committed and current, `README.md` and `docs/FEATURES.md` describe the finished system, and a whole-project review has passed.

## Schema needs for P1
None from this plan. The audit relies on P4's `tickets(status, resolved_at)` index for the dashboard totals; if P1 did not add it, P6.1's dashboard test reports the full scan.

## Global constraints
- This plan adds no production behaviour except `AuthorizeApiDocs`, `config/scramble.php` and the upload throttle on ticket creation (P6.2). A failing audit or security test is a **finding**: report the EXPLAIN plan or the failing assertion and the owning file. Don't loosen the test and don't patch files outside your list.
- Route names come from the P2–P4 plans and the real route files. Where a name on `main` differs, use the real one and report it.
- Before writing a new test, grep `tests/` for one that already proves the same rule. If one exists, drop yours and report it (CLAUDE.md §Testing).
- Bulk fixture rows are inserted with `DB::table()->insert()` inside the test transaction. Never run `ANALYZE TABLE`: it commits implicitly and breaks `RefreshDatabase`.

## Waves
| Wave | Subtask (agent N) | Model | Runs in parallel with |
|---|---|---|---|
| W1 | P6.1 EXPLAIN audit and N+1 guards (1) | sonnet | P6.2, P6.3 |
| W1 | P6.2 Security checklist tests (2) | sonnet | P6.1, P6.3 |
| W1 | P6.3 Scramble docs and final docs (3) | sonnet | P6.1, P6.2 |
| Review | Whole-project final review (§Final review) | opus ×3 + sonnet documenter | — |

After wave 1 the session runs `php artisan scramble:export` once more (P6.2's throttle can change the documented responses), then the gate from ROADMAP step 4, **including** the frontend line, because this is the release gate.

---

## P6.1 — EXPLAIN audit and N+1 guards

**Files:**
- create `tests/Feature/Performance/IndexUsageTest.php`
- create `tests/Feature/Performance/ListQueryCountTest.php`

**Consumes:** routes `api.v1.tickets.index` (filters from TD-080: `assigned_agent_id`, `priority[]`, `category_id`, `sla_state`, `created_from`, `created_to`, `number`, `q`), `api.v1.dashboard`, `api.v1.notifications.index` (`?unread=1`), `api.v1.tickets.activities`, `api.v1.tickets.replies.index`, `notifications.index`, `admin.users.index`, `api.v1.admin.users.index`; the `sla:scan` command; `App\Notifications\TicketCreatedNotification`; events `TicketSlaApproaching`, `TicketSlaBreached`, `TicketReplied`.

**Already covered, not repeated:** `Model::preventsLazyLoading()` (`AppServiceProviderTest`), the ticket index and show query counts (`TicketQueryCountTest`), the filtered list count (P3), and the dashboard query budget (P4). T14's "an N+1 access throws" test is dropped: it tests the framework.

### Why the audit goes through the real endpoints
Each test calls the real HTTP endpoint or command, captures the SQL from the query log, and runs `EXPLAIN FORMAT=JSON` on it. That audits the queries the app actually runs, not a hand-built copy. The expected index is found in `information_schema` by its column list, so the test doesn't depend on Laravel's index names. About 3,000 tickets with a realistic shape (85% finished, a few breached, one customer with about 30 tickets, one agent with about 60) give the optimizer honest statistics: InnoDB keeps the row estimate current on insert, and range predicates use index dives.

- [ ] **Step 1: Write** `tests/Feature/Performance/IndexUsageTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** @param  list<string>  $columns */
function indexOnColumns(string $table, array $columns): string
{
    $rows = DB::select(
        'SELECT INDEX_NAME AS index_name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          GROUP BY INDEX_NAME',
        [$table],
    );

    foreach ($rows as $row) {
        if ($row->index_columns === implode(',', $columns)) {
            return (string) $row->index_name;
        }
    }

    throw new RuntimeException(sprintf('No index on %s(%s), which TD-021 promises.', $table, implode(', ', $columns)));
}

/** @return list<array{query: string, bindings: array<int, mixed>}> */
function capturedSelects(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_values(array_filter(
        array_map(static fn (array $entry): array => ['query' => (string) $entry['query'], 'bindings' => $entry['bindings']], $log),
        static fn (array $entry): bool => str_starts_with(strtolower($entry['query']), 'select'),
    ));
}

/**
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $selects
 * @param  list<string>  $needles
 * @return array{query: string, bindings: array<int, mixed>}
 */
function selectFrom(array $selects, string $table, array $needles = []): array
{
    foreach ($selects as $select) {
        if (! str_contains($select['query'], "from `{$table}`")) {
            continue;
        }

        foreach ($needles as $needle) {
            if (! str_contains($select['query'], $needle)) {
                continue 2;
            }
        }

        return $select;
    }

    throw new RuntimeException("No select from {$table} containing [".implode(', ', $needles)."]:\n".implode("\n", array_column($selects, 'query')));
}

/**
 * @param  array{query: string, bindings: array<int, mixed>}  $select
 * @return array{tables: array<string, array{key: ?string, access: ?string}>, plan: string}
 */
function explainSelect(array $select): array
{
    $row = (array) DB::selectOne('EXPLAIN FORMAT=JSON '.$select['query'], $select['bindings']);
    $json = (string) ($row['EXPLAIN'] ?? reset($row));
    $tables = [];

    $walk = function (array $node) use (&$walk, &$tables): void {
        if (isset($node['table_name']) && is_string($node['table_name']) && ! array_key_exists($node['table_name'], $tables)) {
            $tables[$node['table_name']] = [
                'key' => isset($node['key']) ? (string) $node['key'] : null,
                'access' => isset($node['access_type']) ? (string) $node['access_type'] : null,
            ];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };
    $walk(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    return ['tables' => $tables, 'plan' => $json];
}

/**
 * @param  array{query: string, bindings: array<int, mixed>}  $select
 * @param  list<string>  $columns
 */
function expectIndex(array $select, string $table, array $columns): void
{
    $explain = explainSelect($select);

    expect($explain['tables'][$table]['key'] ?? null)->toBe(indexOnColumns($table, $columns), $explain['plan']);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $now = CarbonImmutable::now();

    $categories = Category::factory()->count(4)->create()->pluck('id')->all();
    $this->categoryId = $categories[1];
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent($categories)->create();
    $this->customer = User::factory()->customer()->create();
    $otherAgent = User::factory()->agent($categories)->create();
    $customers = User::factory()->customer()->count(20)->create()->pluck('id')->all();

    $active = TicketStatus::active();
    $priorities = TicketPriority::cases();
    $rows = [];

    for ($i = 1; $i <= 3000; $i++) {
        $finished = $i % 20 < 17;
        $status = $finished ? ($i % 2 === 0 ? TicketStatus::Closed : TicketStatus::Resolved) : $active[$i % 3];
        $created = $now->subMinutes($i * 37);
        [$due, $warning] = match (true) {
            $finished => [$created->addHours(8), $created->addHours(6)],
            $i % 7 === 0 => [$now->subHour(), $now->subHours(3)],
            $i % 7 === 1 => [$now->addMinutes(90), $now->subMinutes(30)],
            default => [$now->addDays(2), $now->addDay()],
        };
        $customerId = $i % 97 === 0 ? $this->customer->id : $customers[$i % 20];

        $rows[] = [
            'number' => sprintf('TKT-2026-%06d', $i),
            'subject' => "Ticket {$i} about a ".($i % 5 === 0 ? 'printer' : 'login').' problem',
            'description' => 'Generated for the EXPLAIN audit.',
            'priority' => $priorities[$i % 4]->value,
            'status' => $status->value,
            'category_id' => $categories[intdiv($i, 4) % 4],
            'customer_id' => $customerId,
            'created_by_id' => $customerId,
            'assigned_agent_id' => $i % 47 === 0 ? $this->agent->id : ($i % 3 === 0 ? $otherAgent->id : null),
            'sla_started_at' => $created,
            'sla_due_at' => $due,
            'sla_warning_at' => $warning,
            'sla_paused_at' => $status === TicketStatus::WaitingForCustomer ? $now->subDay() : null,
            'escalation_level' => 0,
            'resolved_at' => $finished ? $created->addHours(4) : null,
            'closed_at' => $status === TicketStatus::Closed ? $created->addHours(5) : null,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('tickets')->insert($chunk);
    }
});

it("serves a customer's ticket list from tickets(customer_id, created_at)", function (): void {
    Sanctum::actingAs($this->customer, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index'))->assertOk());

    expectIndex(selectFrom($selects, 'tickets', ['limit']), 'tickets', ['customer_id', 'created_at']);
});

it('serves the default staff ticket list without a full table scan', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index'))->assertOk());
    $explain = explainSelect(selectFrom($selects, 'tickets', ['limit']));

    expect($explain['tables']['tickets']['access'] ?? 'ALL')->not->toBe('ALL', $explain['plan']);
});

dataset('indexed ticket filters', [
    'my tickets' => [fn (): array => ['assigned_agent_id' => $this->agent->id], ['assigned_agent_id', 'status']],
    'priority' => [fn (): array => ['priority' => [TicketPriority::Critical->value]], ['priority', 'status']],
    'category' => [fn (): array => ['category_id' => $this->categoryId], ['category_id', 'status']],
    'breached' => [fn (): array => ['sla_state' => SlaState::Breached->value], ['status', 'sla_due_at']],
    'approaching' => [fn (): array => ['sla_state' => SlaState::Approaching->value], ['status', 'sla_warning_at']],
    'created date range' => [fn (): array => ['created_from' => '2026-10-19', 'created_to' => '2026-10-19'], ['created_at']],
    'ticket number' => [fn (): array => ['number' => 'TKT-2026-0012'], ['number']],
    'keyword' => [fn (): array => ['q' => 'printer'], ['subject', 'description']],
]);

it('serves each ticket filter from its TD-021 index', function (array $filters, array $columns): void {
    Sanctum::actingAs($this->admin, ['*']);

    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.index', $filters))->assertOk());

    expectIndex(selectFrom($selects, 'tickets', ['limit']), 'tickets', $columns);
})->with('indexed ticket filters');

it('runs the escalation scan from the SLA timestamp indexes', function (): void {
    Event::fake([TicketSlaApproaching::class, TicketSlaBreached::class]);

    $selects = capturedSelects(fn () => $this->artisan('sla:scan')->assertSuccessful()->run());

    expectIndex(selectFrom($selects, 'tickets', ['`sla_warning_at` <=']), 'tickets', ['status', 'sla_warning_at']);
    expectIndex(selectFrom($selects, 'tickets', ['`sla_due_at` <=']), 'tickets', ['status', 'sla_due_at']);
});

it('builds the dashboard without a full scan of tickets', function (): void {
    Cache::flush();
    Sanctum::actingAs($this->admin, ['*']);

    $ticketSelects = collect(capturedSelects(fn () => $this->getJson(route('api.v1.dashboard'))->assertOk()))
        ->filter(fn (array $select): bool => str_contains($select['query'], '`tickets`'));
    $fullScans = $ticketSelects
        ->map(fn (array $select): array => explainSelect($select) + ['sql' => $select['query']])
        ->filter(fn (array $explain): bool => ($explain['tables']['tickets']['access'] ?? null) === 'ALL')
        ->map(fn (array $explain): string => $explain['sql'].PHP_EOL.$explain['plan'])
        ->values()
        ->all();

    expect($ticketSelects)->not->toBeEmpty()
        ->and($fullScans)->toBe([]);
});

it('serves the activity timeline from activity_log(subject_type, subject_id, created_at)', function (): void {
    $ticket = Ticket::query()->where('customer_id', $this->customer->id)->firstOrFail();
    $now = CarbonImmutable::now();
    $rows = [];

    foreach (Ticket::query()->pluck('id') as $index => $ticketId) {
        for ($n = 0; $n < ($ticketId === $ticket->id ? 40 : 1); $n++) {
            $rows[] = [
                'log_name' => 'ticket',
                'description' => 'Generated',
                'subject_type' => $ticket->getMorphClass(),
                'subject_id' => $ticketId,
                'event' => ActivityType::Updated->value,
                'properties' => '{}',
                'created_at' => $now->subMinutes($index + $n),
                'updated_at' => $now->subMinutes($index + $n),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('activity_log')->insert($chunk);
    }

    Sanctum::actingAs($this->admin, ['*']);
    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.tickets.activities', $ticket))->assertOk());

    expectIndex(selectFrom($selects, 'activity_log', ['limit']), 'activity_log', ['subject_type', 'subject_id', 'created_at']);
});

it('serves the notification inbox from notifications(notifiable_type, notifiable_id, read_at)', function (array $query): void {
    $userIds = User::query()->pluck('id')->all();
    $now = CarbonImmutable::now();
    $rows = [];

    for ($i = 1; $i <= 3000; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'type' => TicketCreatedNotification::class,
            'notifiable_type' => $this->customer->getMorphClass(),
            'notifiable_id' => $i % 20 === 0 ? $this->customer->id : $userIds[$i % count($userIds)],
            'data' => json_encode(['ticket_number' => sprintf('TKT-2026-%06d', $i), 'title' => 'Created', 'body' => 'Created', 'url' => '/'], JSON_THROW_ON_ERROR),
            'read_at' => $i % 2 === 0 ? $now : null,
            'created_at' => $now->subMinutes($i),
            'updated_at' => $now->subMinutes($i),
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }

    Sanctum::actingAs($this->customer, ['*']);
    $selects = capturedSelects(fn () => $this->getJson(route('api.v1.notifications.index', $query))->assertOk());

    expectIndex(selectFrom($selects, 'notifications', ['limit']), 'notifications', ['notifiable_type', 'notifiable_id', 'read_at']);
})->with([
    'all' => [[]],
    'unread only' => [['unread' => 1]],
]);
```

`toBe($expected, $message)` prints the whole EXPLAIN JSON on failure, so a wrong plan can be read straight from the output.

- [ ] **Step 2: Run it.** `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Performance/IndexUsageTest.php`
  Expected: PASS (15 tests counting dataset rows). This characterizes code that exists, so it should be green on the first run. A failure is a finding:
  - `access: ALL` with `key: null` means the query shape defeats the index.
  - Another key means a different index wins. For the timeline, the likely rival is the package's `subject` morph index, which is a left prefix of the TD-021 index; report it, because then one of the two is redundant.
  - `selectFrom` throwing means the endpoint's SQL differs from the expected shape (for example the scan uses another comparison). Print the captured SQL and report it.
  Report each finding with its plan. Don't change the expectation.
- [ ] **Step 3: Prove it can fail.** Change the breached expectation to `['status', 'sla_warning_at']`, run `--filter="escalation scan"`, see it FAIL with the plan printed, then restore it.

- [ ] **Step 4: Write** `tests/Feature/Performance/ListQueryCountTest.php`. It covers the list endpoints that no earlier plan guards: each one must run the same number of queries for 2 rows as for 12.

```php
<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function queriesDuring(Closure $visit): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $visit();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function addListRows(string $kind, int $count, User $viewer, Ticket $ticket, Category $category): void
{
    for ($i = 0; $i < $count; $i++) {
        if ($kind === 'users') {
            User::factory()->agent([$category->id])->create();

            continue;
        }

        if ($kind === 'notifications') {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => TicketCreatedNotification::class,
                'notifiable_type' => $viewer->getMorphClass(),
                'notifiable_id' => $viewer->id,
                'data' => json_encode(['ticket_number' => $ticket->number, 'title' => 'Created', 'body' => 'Created', 'url' => '/'], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

            continue;
        }

        $author = User::factory()->agent()->create();
        $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $author->id, 'is_internal' => false]);
        Attachment::factory()->create(['ticket_id' => $ticket->id, 'reply_id' => $reply->id, 'is_internal' => false]);

        if ($kind === 'activities') {
            TicketReplied::dispatch($ticket, $reply, $author);
        }
    }
}

dataset('list endpoints', [
    'api replies' => ['replies', 'api.v1.tickets.replies.index', true],
    'api activity timeline' => ['activities', 'api.v1.tickets.activities', true],
    'web inbox' => ['notifications', 'notifications.index', false],
    'api inbox' => ['notifications', 'api.v1.notifications.index', false],
    'web admin users' => ['users', 'admin.users.index', false],
    'api admin users' => ['users', 'api.v1.admin.users.index', false],
]);

it('keeps list endpoints at a constant query count as rows grow', function (string $kind, string $routeName, bool $needsTicket): void {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $ticket = Ticket::factory()
        ->status(TicketStatus::InProgress)
        ->assignedTo(User::factory()->agent([$category->id])->create())
        ->create(['category_id' => $category->id]);
    $url = $needsTicket ? route($routeName, $ticket) : route($routeName);
    $isApi = str_starts_with($routeName, 'api.');

    if ($isApi) {
        Sanctum::actingAs($admin, ['*']);
    } else {
        $this->actingAs($admin);
    }

    $visit = fn () => $isApi ? $this->getJson($url)->assertOk() : $this->get($url)->assertOk();

    addListRows($kind, 2, $admin, $ticket, $category);
    $visit();
    $small = queriesDuring($visit);

    addListRows($kind, 10, $admin, $ticket, $category);
    $large = queriesDuring($visit);

    expect($large)->toBe($small);
})->with('list endpoints');
```

- [ ] **Step 5: Run both files.** `DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Performance/IndexUsageTest.php tests/Feature/Performance/ListQueryCountTest.php`
  Expected: PASS. A `LazyLoadingViolationException` or a larger count for 12 rows is an N+1 finding in that endpoint's controller or resource: report it with the endpoint. Then run `./vendor/bin/pint` on both files.

**Proposed commit:** `test(db): audit hot queries with EXPLAIN and guard list query counts` + `Refs: P6.1` + `Implements: TD-021, TD-101`

---

## P6.2 — Security checklist tests

**Files:**
- create `tests/Feature/Security/SecurityChecklistTest.php`
- modify `routes/web/tickets.php` and `routes/api/v1/tickets.php`: add `->middleware('throttle:uploads')` to the `tickets.store` route (skip a file whose `tickets.store` already has a `throttle:` middleware)

**Consumes:** the `uploads` rate limiter (`AppServiceProvider`, defined but unused on `main`), routes `api.v1.tickets.store`, `tickets.replies.store`, `api.v1.tickets.replies.store`, `admin.imports.store`, `api.v1.imports.store`, `POST /register`, and the admin route names `admin.*`, `api.v1.admin.*`, `api.v1.imports.*`.
**Produces:** the test names listed in P6.3's TD-120 table.

### What this file proves, and what it leaves to the owning tests
Each TD-120 row is proven once. Rows another plan already proves at the right layer are mapped, not repeated. P6.3 records the mapping in TD-120.

| TD-120 row | Proven here | Already proven by (verify on `main`) |
|---|---|---|
| Server-side authorization | auth on every non-public route; guest-only routes limited to sign-in, registration and password reset; every parameterless admin route answers 403 to agents and customers | each feature's route matrix (`TicketRoutesAuthorizationTest`, P2–P4 matrices) |
| Internal-note leakage | — | `TicketReplyTest` (scope), `TicketTest` (activity scope), `TicketPagesTest` (customer show props), `TicketApiTest` (activities), P3 conversation visibility tests (web props, API replies index, resource guard, internal attachment download), P4 "sends the customer nothing at all for an internal note" |
| Mass assignment | injected privileged fields ignored by ticket creation; registration can't choose a role | `TicketTest`, `UserTest`, `SupportingModelsTest`, `TicketReplyTest` (fillable lists) |
| File uploads | a PHP script disguised as a PDF is rejected by content | `AttachmentStorerTest` (private disk, UUID names), P3 upload validation and download-header tests |
| Rate limiting | every `api/v1` route is in the throttled `api` group; every upload route is throttled | `LoginTest`, `AuthTokenTest` (5 per minute), `AppServiceProviderTest` (limiter values) |
| Tokens | — | `AuthTokenTest` (abilities mirror permissions, revoke current only) |
| Inertia props | — | `InertiaSharedPropsTest`, `SharedAuthPropsTest` |
| Search input | — | P3 search tests (operators neutralised, unbalanced quotes, sort whitelist, customer can't widen visibility) |
| CSRF / XSS | no `v-html` anywhere in `resources/js`. CSRF is the `web` group's framework default and is disabled while unit tests run, so it isn't re-tested | — |
| 404 not 403 (TD-012) | — | `TicketRoutesAuthorizationTest` (web and API), P3 attachment matrix |

Public by design: `home`, `up`, `api.v1.auth.tokens.store`, `sanctum.csrf-cookie`, the guest-only `login`, `register` and password-reset routes, the Scramble docs (gated by `AuthorizeApiDocs`, proven in P6.3), and package dev tooling under `_boost/`, `_inertia/`, `_scramble/` and the signed `storage/` route, which the packages guard themselves.

- [ ] **Step 1: Write the test** `tests/Feature/Security/SecurityChecklistTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\EscalationLevel;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** @return Collection<int, RoutingRoute> */
function applicationRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), ['_boost/', '_inertia/', '_scramble/', 'storage/'])
            || Str::startsWith((string) $route->getName(), 'scramble.'))
        ->values();
}

/** @return Collection<int, string> */
function middlewareOf(RoutingRoute $route): Collection
{
    return collect($route->gatherMiddleware())->filter(fn (mixed $middleware): bool => is_string($middleware))->values();
}

function requiresAuthentication(RoutingRoute $route): bool
{
    return middlewareOf($route)->contains(fn (string $middleware): bool => $middleware === 'auth' || str_starts_with($middleware, 'auth:'));
}

function isGuestOnly(RoutingRoute $route): bool
{
    return middlewareOf($route)->contains('guest');
}

function describeRoute(RoutingRoute $route): string
{
    return implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
}

beforeEach(function (): void {
    config(['tickets.auto_assign' => false]);
    $this->category = Category::factory()->create(['is_active' => true]);
    $this->customer = User::factory()->customer()->create();
});

describe('server-side authorization', function (): void {
    it('requires authentication on every route that is not public by design', function (): void {
        $publicNames = ['home', 'api.v1.auth.tokens.store', 'sanctum.csrf-cookie'];

        $offenders = applicationRoutes()
            ->reject(fn (RoutingRoute $route): bool => requiresAuthentication($route)
                || isGuestOnly($route)
                || in_array($route->getName(), $publicNames, true)
                || $route->uri() === 'up')
            ->map(fn (RoutingRoute $route): string => describeRoute($route))
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });

    it('keeps guest-only routes to sign-in, registration and password reset', function (): void {
        $publicUris = collect(['login', 'register', 'password.request', 'password.email', 'password.reset', 'password.update'])
            ->map(fn (string $name): ?string => Route::getRoutes()->getByName($name)?->uri())
            ->filter()
            ->all();

        $offenders = applicationRoutes()
            ->filter(fn (RoutingRoute $route): bool => isGuestOnly($route))
            ->reject(fn (RoutingRoute $route): bool => in_array($route->uri(), $publicUris, true))
            ->map(fn (RoutingRoute $route): string => describeRoute($route))
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });

    it('answers 403 to agents and customers on every parameterless admin route', function (): void {
        $adminRoutes = applicationRoutes()
            ->filter(fn (RoutingRoute $route): bool => preg_match('/^(admin\.|api\.v1\.admin\.|api\.v1\.imports\.)/', (string) $route->getName()) === 1)
            ->filter(fn (RoutingRoute $route): bool => $route->parameterNames() === []);
        $offenders = [];

        foreach ([Role::Agent, Role::Customer] as $role) {
            $user = $role === Role::Agent ? User::factory()->agent()->create() : User::factory()->customer()->create();
            $this->actingAs($user);
            Sanctum::actingAs($user, ['*']);

            foreach ($adminRoutes as $route) {
                $method = (string) collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
                $uri = '/'.$route->uri();
                $status = str_starts_with($route->uri(), 'api/')
                    ? $this->json($method, $uri)->status()
                    : $this->call($method, $uri)->status();

                if ($status !== 403) {
                    $offenders[] = "{$role->value} {$method} {$uri} → {$status}";
                }
            }
        }

        expect($adminRoutes)->not->toBeEmpty()
            ->and($offenders)->toBe([]);
    });
});

describe('mass assignment', function (): void {
    it('ignores privileged fields injected into ticket creation', function (): void {
        $agent = User::factory()->agent([$this->category->id])->create();
        $admin = User::factory()->admin()->create();
        $otherCustomer = User::factory()->customer()->create();
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Injected',
            'description' => 'Trying to set protected columns',
            'priority' => TicketPriority::Low->value,
            'category_id' => $this->category->id,
            'number' => 'TKT-1999-999999',
            'status' => TicketStatus::Closed->value,
            'assigned_agent_id' => $agent->id,
            'sla_due_at' => '2099-01-01 00:00:00',
            'escalation_level' => EscalationLevel::Breached->value,
            'customer_id' => $otherCustomer->id,
            'created_by_id' => $admin->id,
        ])->assertCreated();

        $ticket = Ticket::query()->where('subject', 'Injected')->sole();

        expect($ticket->number)->not->toBe('TKT-1999-999999')
            ->and($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->assigned_agent_id)->toBeNull()
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None)
            ->and($ticket->customer_id)->toBe($this->customer->id)
            ->and($ticket->created_by_id)->toBe($this->customer->id)
            ->and($ticket->sla_due_at->year)->not->toBe(2099);
    });

    it('registers a customer whatever role the form posts', function (): void {
        $this->post('/register', [
            'name' => 'Role Seeker',
            'email' => 'role.seeker@example.com',
            'password' => 'S3cure-passw0rd!',
            'password_confirmation' => 'S3cure-passw0rd!',
            'role' => Role::Admin->value,
            'roles' => [Role::Admin->value],
        ]);

        expect(User::query()->where('email', 'role.seeker@example.com')->sole()->getRoleNames()->all())
            ->toBe([Role::Customer->value]);
    });
});

describe('file uploads', function (): void {
    it('rejects a PHP script disguised as a PDF by its content', function (): void {
        Storage::fake((string) config('attachments.disk'));
        $path = (string) tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($path, "<?php echo 'pwned';");
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Invoice',
            'description' => 'See attached',
            'priority' => TicketPriority::Low->value,
            'category_id' => $this->category->id,
            'attachments' => [new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true)],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        unlink($path);

        expect(Ticket::query()->count())->toBe(0)
            ->and(Attachment::query()->count())->toBe(0);
    });
});

describe('rate limiting', function (): void {
    it('runs every api/v1 route through the throttled api group', function (): void {
        $apiGroup = collect(app('router')->getMiddlewareGroups()['api'] ?? []);

        $outside = applicationRoutes()
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->reject(fn (RoutingRoute $route): bool => middlewareOf($route)->contains('api'))
            ->map(fn (RoutingRoute $route): string => describeRoute($route))
            ->values()
            ->all();

        expect($apiGroup->contains(fn (string $middleware): bool => str_contains($middleware, 'throttle') || str_contains($middleware, 'ThrottleRequests')))->toBeTrue()
            ->and($outside)->toBe([]);
    });

    it('throttles every route that accepts uploads', function (string $name): void {
        expect(middlewareOf(Route::getRoutes()->getByName($name) ?? throw new RuntimeException("Route {$name} is missing."))
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')))->toBeTrue();
    })->with([
        'tickets.store',
        'api.v1.tickets.store',
        'tickets.replies.store',
        'api.v1.tickets.replies.store',
        'admin.imports.store',
        'api.v1.imports.store',
    ]);
});

describe('xss', function (): void {
    it('never renders raw HTML in Vue', function (): void {
        $offenders = collect(File::allFiles(resource_path('js')))
            ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'v-html'))
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });
});
```

- [ ] **Step 2: Run it, expect one FAIL.** `DB_DATABASE=techzu_testing_agent2 ./vendor/bin/pest tests/Feature/Security/SecurityChecklistTest.php`
  Expected: the `tickets.store` and `api.v1.tickets.store` rows of "throttles every route that accepts uploads" fail, because ticket creation accepts attachments but is not throttled on `main`. Every other test passes. Any other failure is a finding for the owning plan: report the measure, the assertion and the owning file. Don't edit that file.
- [ ] **Step 3: Throttle ticket creation.** In both route files, chain `->middleware('throttle:uploads')` onto the `tickets.store` route. On `main` the API line becomes:

```php
    Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:uploads')->name('tickets.store');
```

- [ ] **Step 4: Run it, expect PASS.** Same command as Step 2. Then prove the XSS check can fail: add `<div v-html="'x'" />` to any page, run `--filter="raw HTML"`, see it FAIL listing that file, and revert. Then run `./vendor/bin/pint` on the test and the two route files.

**Proposed commit:** `test(security): prove the security checklist across all routes` + `Refs: P6.2` + `Implements: TD-120`. Commit body: "Ticket creation accepts attachments, so it now uses the existing uploads limiter."

---

## P6.3 — Scramble docs and final docs

**Files:**
- create `app/Http/Middleware/AuthorizeApiDocs.php`
- create `config/scramble.php`
- create `docs/api/openapi.json` (generated)
- create `tests/Feature/Docs/ApiDocsTest.php`
- modify `README.md` (four sections, below)
- modify `TECHNICAL_DECISIONS.md` (TD-021, TD-033, TD-120 only)
- modify `docs/FEATURES.md` (consistency pass; not the "Security and performance" section)

**Consumes:** `UserPolicy::viewAny` (admins only, through `Permission::UsersManage`), every `api/v1` route.
**Produces:** `/docs/api` (UI) and `/docs/api.json`, open in `local` and admin-only elsewhere; `docs/api/openapi.json`; `App\Http\Middleware\AuthorizeApiDocs`.

Dropped from T14: `docs/API.md` and its drift test (Scramble is the only API documentation) and `docs/TECHNICAL.md` (the rationale lives in `docs/DESIGN.md` and `TECHNICAL_DECISIONS.md`).

### Scramble
Scramble's default `RestrictedDocsAccess` needs a `viewApiDocs` gate defined in a provider. A small middleware referenced from `config/scramble.php` applies the same rule through the existing `UserPolicy`, so no provider changes.

- [ ] **Step 1: Write the failing test** `tests/Feature/Docs/ApiDocsTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AuthorizationMatrix;

it('serves the API docs UI and JSON to admins outside local', function (): void {
    expect(app()->environment('local'))->toBeFalse();
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/docs/api')->assertOk();
    $this->get('/docs/api.json')
        ->assertOk()
        ->assertJsonStructure(['openapi', 'info' => ['title', 'version'], 'paths']);
});

it('hides the API docs from agents, customers and guests outside local', function (?Role $role, string $uri): void {
    $matrix = AuthorizationMatrix::for($this);
    $role === null ? $matrix->asGuest() : $matrix->actingAsRole($role);

    $matrix->expectAccess('GET', $uri, 403);
})->with([
    'guest' => [null],
    'agent' => [Role::Agent],
    'customer' => [Role::Customer],
])->with(['/docs/api', '/docs/api.json']);

it('exports an OpenAPI document that lists every api/v1 route', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    $documented = array_keys($spec['paths']);

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn (RoutingRoute $route): string => '/'.Str::after($route->uri(), 'api/v1/'))
        ->unique()
        ->reject(fn (string $path): bool => in_array($path, $documented, true))
        ->values()
        ->all();

    expect($spec['openapi'])->toStartWith('3.')
        ->and($missing)->toBe([]);
});
```

- [ ] **Step 2: Run it, expect FAIL.** `DB_DATABASE=techzu_testing_agent3 ./vendor/bin/pest tests/Feature/Docs/ApiDocsTest.php`
  Expected: the admin test gets 403 (no `viewApiDocs` gate), and the export test fails to open `docs/api/openapi.json`.
- [ ] **Step 3: Create** `app/Http/Middleware/AuthorizeApiDocs.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeApiDocs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('viewAny', User::class), 403);

        return $next($request);
    }
}
```

- [ ] **Step 4: Create** `config/scramble.php`. Laravel merges package config only at the top level, so every key left out (`api_domain`, `cache`, `openapi_version`, `dev_tools`, the renderers, `servers`, `extensions`, the enum strategies) keeps Scramble 0.13's default. `api_path` limits the docs to `api/v1`, so paths appear as `/tickets` and the server is `{APP_URL}/api/v1`. `ui.title` becomes the document's `info.title`. `security_strategy` adds the bearer scheme to every route behind `auth:sanctum` and marks `POST auth/tokens` public. The `web` middleware lets a signed-in admin open the docs from the app.

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\AuthorizeApiDocs;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api/v1',
    'export_path' => 'docs/api/openapi.json',
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Support Ticket & SLA Management API. Get a Sanctum bearer token from `POST /api/v1/auth/tokens` with `email`, `password` and `device_name`, then send it as `Authorization: Bearer <token>`. Errors are `{"message", "code"}`; branch on `code` or the HTTP status.',
    ],
    'ui' => [
        'title' => env('APP_NAME', 'Laravel').' API',
    ],
    'middleware' => [
        'web',
        AuthorizeApiDocs::class,
    ],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
```

- [ ] **Step 5: Export the spec.** `mkdir -p docs/api && php artisan scramble:export`
  Expected: exit 0 and a pretty-printed `docs/api/openapi.json`. `jq '.info.title, (.components.securitySchemes | keys)' docs/api/openapi.json` prints `"<APP_NAME> API"` and `["http"]`. Scramble infers types statically; list any export warnings in your report. Don't add docblocks to other plans' files.
- [ ] **Step 6: Run the test, expect PASS** (same command as Step 2: 8 tests counting dataset rows). If a path is missing, compare `php artisan route:list --path=api/v1` with the exported `paths` and report the route Scramble skipped. Then run `./vendor/bin/pint` on the middleware, the config and the test.

### README.md
P1 created `README.md` with the setup, demo accounts and test commands. Add the four sections below after P1's last setup section; if a section with the same title exists, replace its body. Leave P1's sections unchanged. **Every sentence must match the code on `main`:** check each rule against the config files, the TDs and the code, and correct the text where they differ (report each correction).

- [ ] **Step 7: Add** these sections to `README.md`.

```markdown
## Features

- **Roles.** Admin, Agent and Customer. Permissions are defined in code and seeded; admins change a user's role and can view the permission matrix read-only.
- **Tickets.** Numbers look like `TKT-2026-000001`: the prefix is configurable, and a per-year counter row makes them unique under concurrency. Statuses are Open, In progress, Waiting for customer, Resolved and Closed, and only the allowed transitions are accepted. Customers see only their own tickets; asking for anyone else's returns 404.
- **SLA.** Resolution time depends on priority (Low 48 h, Medium 24 h, High 8 h, Critical 2 h, all in business minutes) and is counted only inside business hours, Sunday–Thursday 09:00–18:00 Asia/Dhaka, skipping holidays. The clock pauses while a ticket waits for the customer, and a priority change recalculates the deadline from the original start. A ticket is "approaching" after 75% of its time.
- **Escalation.** `sla:scan` runs every minute. It marks approaching and breached tickets, notifies the right people and records the escalation in the ticket history. Running it twice changes nothing.
- **Assignment.** Manual, claim and automatic (see below).
- **Conversation.** Public replies from customers and staff, and internal notes that customers never see in any form. Files are validated by type and size, stored on a private disk, and downloaded only through a permission check.
- **Notifications.** Mail and in-app notifications for created, assigned, replied, resolved, SLA approaching and SLA breached, queued and sent after the database commit. Each user has an inbox with read and unread state.
- **Search and filters.** Ticket number, keyword (full-text), status, priority, category, agent, customer, created date and SLA state, with pagination.
- **CSV import.** Large files are streamed in chunks on the queue. Each row is validated, duplicates are skipped, and invalid rows are reported with their errors.
- **Dashboard.** Open, unassigned, critical, breached, approaching and recently resolved counts, plus tickets by priority and by agent, from a few indexed aggregate queries cached for 60 seconds.
- **Activity history.** Every important change is recorded with its time and actor.
- **REST API.** `/api/v1`, authenticated with Sanctum tokens, using the same validation, actions and policies as the web app.

## Automatic assignment

New tickets are assigned by a queued job right after they are created, unless `TICKETS_AUTO_ASSIGN=false`. Imported tickets are not auto-assigned.

1. **Eligible agents:** available agents linked to the ticket's category.
2. **Least load first:** the agent with the fewest active tickets (Open, In progress, Waiting for customer) wins.
3. **Tie-break:** the agent whose last assignment is oldest (never-assigned agents first), then the lowest id. This acts as round-robin among equally loaded agents.
4. **No eligible agent:** the ticket stays unassigned and shows up as unassigned on the dashboard.

The job locks the candidate agents' rows while it chooses, so a burst of new tickets doesn't pile onto one agent. Agents can also **claim** an unassigned ticket: the claim is a single conditional update, so when two agents claim at once exactly one wins and the other gets `409 ticket_already_claimed`. Admins can **assign or reassign** any ticket to an eligible agent.

## API documentation

The OpenAPI 3.1 spec is generated from the routes, form requests and resources by Scramble.

- Browse it at `{APP_URL}/docs/api`; the raw JSON is at `{APP_URL}/docs/api.json`. Both are open in the `local` environment and admin-only everywhere else.
- A copy is committed at `docs/api/openapi.json`. Regenerate it after changing the API: `php artisan scramble:export`.
- Get a token with `POST /api/v1/auth/tokens` (`email`, `password`, `device_name`) and send it as `Authorization: Bearer <token>`.

## Known limitations

- One company-wide business calendar and timezone. Holiday changes affect new and recalculated deadlines only.
- Agent load counts every active ticket equally, whatever its priority.
- SLA breaches are detected up to one minute late (the scan interval).
- Only a resolution SLA; there is no first-response SLA.
- Full-text search ignores words shorter than three characters; search by ticket number covers the common short case.
- Dashboard figures can be up to 60 seconds old.
- Lists use offset pagination; very deep pages would need cursor pagination.
- Uploaded files are not virus-scanned. On S3, downloads should switch to temporary URLs after the permission check.
- Roles are fixed and permissions are defined in code. There is no email verification, and local mail goes to the log.
```

### TECHNICAL_DECISIONS.md
- [ ] **Step 8: Edit three entries.**
  - **TD-021**, last trade-off sentence: replace "T14 includes an `EXPLAIN` audit to confirm each index is actually used." with "An `EXPLAIN` audit test runs the real endpoints and the escalation scan and confirms each query uses its index."
  - **TD-033**, decision: delete the `docs/API.md` bullet, and add to the "served at `/docs/api`" bullet that the JSON is at `/docs/api.json` and that the gate is `AuthorizeApiDocs` through `UserPolicy::viewAny`.
  - **TD-120**: add a third column, **Proven by**, filled from P6.2's table. Use the real test file and test name for each cell (grep `tests/` on `main`; P3 and P4 names may differ from the old plans). Rows proven in P6.2 name `SecurityChecklistTest` and the test. The CSRF cell reads "framework default for the `web` group; not re-tested", plus the XSS test.

### docs/FEATURES.md consistency pass
- [ ] **Step 9:** Read the whole file against the code and fix only inconsistencies:
  1. Headings name features only: drop the old task numbers, such as "(T06)".
  2. No `_Pending._` remains except under "Security and performance", which the review documenter writes. Write any other pending section from the code, describing behaviour only.
  3. A rule described in two sections reads the same in both (for example resuming the SLA on a customer reply, appearing under lifecycle and conversation). Where they disagree, the code wins.
  4. No file, class, method, route or test names (`grep -nE '\.php|::|App\\\\|Test\b' docs/FEATURES.md` returns nothing).
  Report every change you made.

**Proposed commit:** `feat(api): serve admin-gated API docs and finish the project docs` + `Refs: P6.3` + `Implements: TD-033, TD-120`

---

## Final review (whole project)

This replaces ROADMAP step 5 for P6. It runs after the release gate is green. The three reviewers (opus) read the **whole codebase**, not only P6's diff, and each returns a list of confirmed findings with file and line. They run in parallel, in one message.

**Spec reviewer: does the system do what was asked?**
- Each PRD section 1–17 is implemented, reachable from the UI and (where the PRD implies it) the API, and covered by at least one test that proves its rule. List any section without one.
- Every P6 acceptance criterion below holds.
- `README.md` and `docs/FEATURES.md` match the code: every documented rule exists, and every important rule is documented. The assignment strategy (PRD §4) is documented.
- `TECHNICAL_DECISIONS.md` matches the code: for each TD, the decision it states is what the code does. Report drift either way.
- The seeded demo accounts can exercise every role's main flow.

**Quality reviewer: is it simple and does it follow CLAUDE.md everywhere?**
- No comments in `app/`, `config/`, `database/`, `routes/`, `tests/`, `resources/js/`, `resources/css/`, other than PHPDoc type tags. Start from `grep -rnP '^\s*(//|#(?!\[)|/\*(?!\*\s*@))' app config database routes tests` and `grep -rn '<!--' resources/js`, then read the hits.
- `declare(strict_types=1);` in every PHP file, and `final` on Actions, Services, Jobs and Listeners.
- Controllers are thin (no queries beyond route-model binding, no business logic). Only Actions change domain state. `BusinessCalendar` stays pure. `env()` appears only in `config/`.
- No `hasRole()` / `hasPermissionTo()` in controllers, Actions or Vue props, and no role or permission string literals. No `$guarded = []`. No unbounded `->get()` on large tables.
- Over-engineering: interfaces with one implementation, unused classes, config, routes, props or TS types, and duplicate helpers.
- Redundant tests: the same rule tested at several layers, framework behaviour, or trivial getters (CLAUDE.md §Testing). Name each test to delete and the test that already proves its rule.

**Security and test reviewer: are the boundaries real?**
- Every route is authenticated and authorized by a policy (check `php artisan route:list` against the policies and the route matrix tests), and customers get 404, not 403, for anything outside their tickets.
- Internal notes and internal attachments never reach a customer: walk every surface (Inertia props, API resources, activity entries, notifications and their payloads, downloads, dashboard and search results).
- File handling: content-based type validation, size and count limits, private disk, policy-checked downloads with `nosniff` and `attachment` disposition.
- Rate limits on login, token issuance, uploads and the API group. Sort and filter columns come from whitelists, and there is no raw SQL with user input.
- Missing tests that are actually necessary: an untested security boundary, race (claim, auto-assign, ticket number, import duplicates) or compensation path.

**Documenter (sonnet), in the same message:** writes the "Security and performance" section of `docs/FEATURES.md`: which queries are index-backed, the N+1 guard, the authorization, internal-note, upload and rate-limit rules, and who can see the API docs. Behaviour only.

Then ROADMAP steps 6–10 apply as usual: fix, rerun the affected tests plus `pint --test` and `phpstan`, report, and commit after approval.

### Optional: verify every commit (only if the owner asks)
After the P6 commits exist, the session can check that each commit passes the gate on its own. It runs on a detached HEAD, so `main` itself is never rewritten. The default base is `7e6f95f` (the last commit before P1); use `--root` for the whole history.

```bash
git status --porcelain
git checkout --detach main
git rebase --exec 'git diff --quiet HEAD~1 HEAD -- composer.lock || composer install --no-interaction --quiet; ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress && ./vendor/bin/pest --parallel && { git diff --quiet HEAD~1 HEAD -- resources/js package.json || { npm run build && npm run check && npx vue-tsc --noEmit; }; }' 7e6f95f
git checkout main
composer install --no-interaction --quiet
```

The first command must print nothing. When a commit fails, the rebase stops on it: note its SHA and the failing command, run `git rebase --abort && git checkout main && composer install --no-interaction --quiet`, and report. A fix is a new commit on `main`.

---

## Acceptance criteria
1. `IndexUsageTest` passes. Run through the real endpoints and the `sla:scan` command, MySQL uses:
`tickets(customer_id, created_at)` for a customer's list; `tickets(assigned_agent_id, status)`, `(priority, status)`, `(category_id, status)`, `(status, sla_due_at)`, `(status, sla_warning_at)`, `(created_at)`, `(number)` and the FULLTEXT index for the matching filters; `(status, sla_warning_at)` and `(status, sla_due_at)` for the two scan passes; `activity_log(subject_type, subject_id, created_at)` for the timeline; `notifications(notifiable_type, notifiable_id, read_at)` for the inbox, with and without `unread`. The default staff list and every dashboard query avoid a full scan of `tickets`. Any query that didn't was reported, not hidden.
2. `ListQueryCountTest` passes: the replies list, the activity timeline, both inboxes and both admin user lists run the same number of queries for 2 and 12 rows.
3. `SecurityChecklistTest` passes, and each TD-120 row names exactly one proving test (new or existing) in TD-120's **Proven by** column. No test duplicates one that P2–P4 or `main` already has.
4. Every route outside the public-by-design list requires authentication. Guest-only routes are limited to sign-in, registration and password reset. Every parameterless admin route answers 403 to agents and customers.
5. Ticket creation (web and API) is throttled by the `uploads` limiter, like every other upload route.
6. `/docs/api` and `/docs/api.json` return 200 to admins and 403 to agents, customers and guests outside `local`. `docs/api/openapi.json` is committed, lists every `api/v1` path, and is identical to a fresh `php artisan scramble:export`.
7. There is no `docs/API.md` and no `docs/TECHNICAL.md`.
8. `README.md` has the Features, Automatic assignment, API documentation and Known limitations sections, each consistent with the code. P1's setup sections are unchanged.
9. `docs/FEATURES.md` has no task numbers in its headings, no `_Pending._` sections, no code references, and no contradictions between sections. Its "Security and performance" section describes the P6 behaviour.
10. TD-021, TD-033 and TD-120 are updated as in P6.3 Step 8.
11. The release gate (including the frontend line) is green, and the whole-project review's confirmed findings are fixed or reported to the owner.
