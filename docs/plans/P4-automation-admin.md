# P4 — Automation and admin: escalation, notifications, CSV import, categories, holidays, dashboard

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Session steps (S0, S1) are done by the controller itself. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** Add the server side of SLA escalation, notifications and the inbox, CSV import, category and holiday administration, and dashboard statistics, for both the web (Inertia renders and props) and API v1.
**Depends on:** P1 (the `tickets(status, resolved_at)` index), P2 (auth, `AuthorizationMatrix` unchanged), P3 (`config('tickets.auto_assign')`, `QueueAutoAssignment` skipping imported tickets, `api.v1.tickets.replies.store`).
**Outcome:** `sla:scan` runs every minute and escalates exactly once. Ticket events send queued mail and database notifications to the spec §11 recipients, and internal notes never reach a customer. `/notifications` and `/api/v1/notifications` list and mark notifications, and every Inertia page shares `notifications.unread_count`. An admin uploads a CSV over the web or the API, it is imported in queued batches with one transaction per row, and the errors can be paged and downloaded. Admins manage categories and holidays, and a holiday change takes effect on the next SLA calculation. Staff get dashboard figures from `/dashboard` and `/api/v1/dashboard`. P5 builds every page listed in "Produces for P5".

## Schema needs for P1

None beyond what P1 already plans. `DashboardStats` relies on the `tickets(status, resolved_at)` index from P1. Every other table, column and index used here already exists: `notifications(notifiable_type, notifiable_id, read_at)`, `ticket_imports.batch_id`, `ticket_import_errors(ticket_import_id, row_number)`, `job_batches`, `tickets.external_reference` unique, `holidays.date` unique, `categories.slug` unique, `tickets(status, sla_due_at)`, `tickets(status, sla_warning_at)`, `tickets(assigned_agent_id, status)`, `tickets(priority, status)`. This plan adds no migrations.

## Global constraints

- The Vue pages for this plan's Inertia renders are built in P5. Every `assertInertia` passes `false` as the second argument of `component()` (`->component('dashboard/Index', false)`), so tests don't need the page file, which `inertia.testing.ensure_pages_exist` would otherwise require.
- Admin and staff routes authorize in route middleware with `->can(ability, model)` (the existing policies and the `viewDashboard` gate). It runs before FormRequest validation, so these FormRequests return `true` from `authorize()`. Notification routes use `->can('viewAny', DatabaseNotification::class)` (the new `NotificationPolicy`: any user who may view tickets), and they are also owner-scoped: every query goes through `$user->notifications()`, so another user's id is a 404.
- The role × route matrix for every new route is written once, in P4.6. W1 endpoint tests cover only the HTTP mapping (status, props, resource shape) and owner scoping.
- Every new route must deny a signed-in user with no role (403 or 404), as P2's `RouteProtectionTest` requires. That audit can't pass until P4.6 adds the new `RouteAudit::bindings()` entries, so W1 implementers don't run `tests/Feature/Authorization/RouteProtectionTest.php`; the session gate runs it after W2.
- Listeners in `app/Listeners` are auto-discovered (`handle*` methods), as `RecordTicketActivity` already is. No subtask registers listeners or edits a provider.
- W1 runs five implementers in one checkout. Any test that fires domain events (`TicketCreated`, `TicketImportFinished`, the SLA events) calls `Notification::fake()` unless notifications are what it tests, so a neighbour's half-written listener can't change its result.
- Queues: every notification uses `notifications`, and every import job uses `imports` (TD-072). `phpunit.xml` already sets `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` and `TICKETS_AUTO_ASSIGN=false`.
- Code blocks omit the `<?php` / `declare(strict_types=1);` header, the `namespace` line (it follows the file path under `app/`) and the `use` imports: add them for every referenced class. Blank lines inside methods are omitted too; `pint` restores the house layout.
- Freeze time with `$this->travelTo()`. Timestamps in tests are UTC unless a timezone is named.
- An implementer runs only their own tests: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest <files>`, then `./vendor/bin/pint <files>`.

## Waves

| Wave | Subtask | Model | Runs in parallel with |
|---|---|---|---|
| W1 | P4.1 SLA escalation scan and schedule | sonnet | P4.2, P4.3, P4.4, P4.5 |
| W1 | P4.2 Notifications, recipients, inbox and unread count | sonnet | P4.1, P4.3, P4.4, P4.5 |
| W1 | P4.3 CSV import pipeline and HTTP | **opus** | P4.1, P4.2, P4.4, P4.5 |
| W1 | P4.4 Categories and holidays | sonnet | P4.1, P4.2, P4.3, P4.5 |
| W1 | P4.5 Dashboard statistics | sonnet | P4.1, P4.2, P4.3, P4.4 |
| W2 | P4.6 Authorization matrix and end-to-end internal-note test | sonnet | — |

Shared existing files and their single owner: `routes/console.php` → P4.1; `app/Http/Middleware/HandleInertiaRequests.php` and `app/Providers/AppServiceProvider.php` → P4.2; `app/Services/Sla/SlaCalculator.php` and `app/Enums/ImportStatus.php` → P4.3; `routes/web/dashboard.php` and `tests/Feature/App/InertiaSharedPropsTest.php` → P4.5; `tests/Support/RouteAudit.php` and `tests/Feature/Authorization/AuthorizationMatrixTest.php` → P4.6. Categories and holidays get their own route files (`routes/web/categories.php`, `routes/web/holidays.php` and the two API files) instead of README §3.10's `routes/*/admin.php`, so they never share a file with P2's user management.

---

## P4.1 — SLA escalation scan and schedule

**Files:**
- create `app/Console/Commands/ScanSlaCommand.php`
- modify `routes/console.php`
- create `tests/Feature/Sla/ScanSlaCommandTest.php`, `tests/Feature/Sla/ScanSlaActivityTest.php`, `tests/Feature/Sla/ScanSlaScheduleTest.php`

**Consumes:** `SlaQuery::whereApproaching(Builder, CarbonImmutable)` and `::whereBreached(...)` (both already restrict to active, unpaused tickets), `TicketSlaApproaching(Ticket)`, `TicketSlaBreached(Ticket)`, and `RecordTicketActivity`, which records `sla_approaching` and `escalated` with a null causer.

**Produces:**
- `App\Console\Commands\ScanSlaCommand`, signature `sla:scan`, `public const CHUNK_SIZE = 500`. It prints `SLA scan at <ISO-8601>: <n> approaching, <m> breached.`
- `routes/console.php`: `Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();`
- Behaviour P4.2 relies on:
  - `TicketSlaApproaching` fires once when a ticket's level goes 0 → 1, and `TicketSlaBreached` once when it goes 0 → 2 or 1 → 2. A 0 → 2 jump fires only `TicketSlaBreached`.
  - The event carries a freshly loaded ticket with no relations; listeners look up users by id.
  - `escalated_at` is set on breach only, and `updated_at` is not touched.

**Design.** For each level N (1, then 2), with `runAt` = now in UTC truncated to the second:
1. Stream candidate ids with `chunkById(500)`, using `escalation_level < N` plus the matching `SlaQuery` predicate. Keyset paging never skips rows while the loop changes the filtered column.
2. Per chunk, in one short transaction, re-run the same predicate restricted to the chunk's ids with `FOR UPDATE`. A row that another run escalated, or that was paused or resolved since step 1, drops out, and a concurrent run blocks until commit and then skips the rows.
3. Run a conditional `UPDATE … SET escalation_level = N WHERE id IN (locked) AND escalation_level < N` through `toBase()`, so `updated_at` stays as it is.
4. After commit, reload the locked tickets and dispatch one event each. Delivery is at most once (TD-044).

**Steps:**

1. Write `tests/Feature/Sla/ScanSlaCommandTest.php`:

```php
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
```

2. Write `tests/Feature/Sla/ScanSlaActivityTest.php`. It runs the real `RecordTicketActivity` and fakes only notifications.

```php
it('records one sla_approaching and one escalated entry, caused by the system', function (): void {
    Notification::fake();
    $now = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
    $this->travelTo($now);
    $base = ['status' => TicketStatus::InProgress, 'sla_paused_at' => null, 'escalation_level' => EscalationLevel::None];
    $approaching = Ticket::factory()->create([...$base, 'sla_warning_at' => $now->subMinutes(10), 'sla_due_at' => $now->addMinutes(20)]);
    $breached = Ticket::factory()->create([...$base, 'sla_warning_at' => $now->subHours(2), 'sla_due_at' => $now->subHour()]);
    $this->artisan('sla:scan')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();
    $entries = fn (Ticket $ticket): array => $ticket->activities()
        ->where('log_name', 'ticket')
        ->get(['event', 'causer_id'])
        ->map(fn ($activity): array => [$activity->event, $activity->causer_id])
        ->all();
    expect($entries($approaching))->toBe([[ActivityType::SlaApproaching->value, null]])
        ->and($entries($breached))->toBe([[ActivityType::Escalated->value, null]]);
});
```

3. Write `tests/Feature/Sla/ScanSlaScheduleTest.php`:

```php
it('schedules sla:scan every minute, without overlapping, on one server', function (): void {
    $this->app->make(ConsoleKernel::class)->bootstrap();
    $events = collect($this->app->make(Schedule::class)->events())
        ->filter(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'sla:scan'))
        ->values();
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->onOneServer)->toBeTrue();
});
```

4. Run them and confirm they fail (`The command "sla:scan" does not exist.`).

5. Create `app/Console/Commands/ScanSlaCommand.php` (auto-discovered from `app/Console/Commands`):

```php
final class ScanSlaCommand extends Command
{
    public const CHUNK_SIZE = 500;
    /** @var string */
    protected $signature = 'sla:scan';
    /** @var string */
    protected $description = 'Escalate active tickets whose SLA is approaching or breached.';

    public function handle(): int
    {
        $runAt = CarbonImmutable::now()->utc()->startOfSecond();
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
        $this->candidates($level, $runAt)
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($level, $runAt, &$escalated): void {
                $ids = $this->claim($level, $runAt, $chunk->modelKeys());
                $this->dispatchFor($level, $ids);
                $escalated += count($ids);
            });
        return $escalated;
    }
    /** @return Builder<Ticket> */
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
     * @param  array<int, mixed>  $candidateIds
     * @return list<int>
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
        Ticket::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->get()
            ->each(static function (Ticket $ticket) use ($level): void {
                $level === EscalationLevel::Breached
                    ? TicketSlaBreached::dispatch($ticket)
                    : TicketSlaApproaching::dispatch($ticket);
            });
    }
}
```

6. Replace `routes/console.php` with this. The line goes here rather than in `bootstrap/app.php`, because `routes/console.php` is loaded when the console kernel bootstraps.

```php
Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();
```

7. Run: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Sla/ScanSlaCommandTest.php tests/Feature/Sla/ScanSlaActivityTest.php tests/Feature/Sla/ScanSlaScheduleTest.php`. All pass. If only the activity test fails, `RecordTicketActivity` doesn't map the SLA events as described: stop and report, and don't add activity writes to the command.

**Proposed commit:** `feat(escalation): add idempotent sla:scan and run it every minute` · `Refs: P4.1` · `Implements: TD-044, TD-023, TD-101, TD-053`

---

## P4.2 — Notifications, recipients, inbox and unread count

**Files:**
- create `app/Notifications/BaseNotification.php`, `app/Notifications/TicketNotification.php`, `app/Notifications/TicketCreatedNotification.php`, `TicketAssignedNotification.php`, `TicketRepliedNotification.php`, `InternalNoteAddedNotification.php`, `TicketResolvedNotification.php`, `SlaApproachingNotification.php`, `SlaBreachedNotification.php`, `TicketImportFinishedNotification.php` (all in `app/Notifications/`)
- create `app/Services/Notifications/Recipients.php`, `app/Services/Notifications/NotificationFeed.php`
- create `app/Listeners/SendTicketCreatedNotifications.php`, `SendTicketAssignedNotifications.php`, `SendTicketRepliedNotifications.php`, `SendTicketResolvedNotifications.php`, `SendSlaNotifications.php`, `SendImportFinishedNotification.php` (all in `app/Listeners/`)
- create `app/Actions/Notifications/MarkNotificationRead.php`, `app/Actions/Notifications/MarkAllNotificationsRead.php`
- create `app/Http/Requests/Notifications/IndexNotificationsRequest.php`
- create `app/Http/Resources/V1/NotificationResource.php`
- create `app/Http/Controllers/Web/NotificationController.php`, `app/Http/Controllers/Api/V1/NotificationController.php`
- create `routes/web/notifications.php`, `routes/api/v1/notifications.php`
- modify `app/Http/Middleware/HandleInertiaRequests.php` (only the `notifications` value)
- create `app/Policies/NotificationPolicy.php`; modify `app/Providers/AppServiceProvider.php` (one `Gate::policy` line)
- create `tests/Feature/Notifications/NotificationClassesTest.php`, `tests/Feature/Notifications/NotificationRecipientsTest.php`, `tests/Feature/Notifications/NotificationInboxTest.php`

**Consumes:** the events `TicketCreated(ticket, ?actor, imported)`, `TicketAssigned(ticket, ?actor, agent, ?previousAgent, method)`, `TicketReplied(ticket, reply, author)`, `TicketResolved(ticket, ?actor)`, `TicketSlaApproaching(ticket)`, `TicketSlaBreached(ticket)`, `TicketImportFinished(import)`; `User::scopeAdmins`; `config('tickets.auto_assign')` (P3; a missing key reads as false).

**Produces:**
- Notification classes. All are `ShouldQueue` on queue `notifications` with `afterCommit()`. The database payload is `{ticket_number, title, body, url}`.

  | Class | Constructor | Channels |
  |---|---|---|
  | `TicketCreatedNotification` | `(Ticket $ticket)` | mail, database |
  | `TicketAssignedNotification` | `(Ticket $ticket, User $agent)` | mail, database |
  | `TicketRepliedNotification` | `(Ticket $ticket, TicketReply $reply)`; throws `LogicException` for an internal reply | mail, database |
  | `InternalNoteAddedNotification` | `(Ticket $ticket, TicketReply $reply)` | database |
  | `TicketResolvedNotification` | `(Ticket $ticket)` | mail, database |
  | `SlaApproachingNotification` | `(Ticket $ticket)` | mail, database |
  | `SlaBreachedNotification` | `(Ticket $ticket)` | mail, database |
  | `TicketImportFinishedNotification` | `(TicketImport $import)`; `ticket_number` null, `url` `/admin/imports/{id}` | database |

- Auto-discovered listeners, which apply the recipient rules below and never notify the actor or the reply author, except for the customer's own creation receipt: `SendTicketCreatedNotifications`, `SendTicketAssignedNotifications`, `SendTicketRepliedNotifications`, `SendTicketResolvedNotifications`, `SendSlaNotifications` (`handleApproaching`, `handleBreached`), `SendImportFinishedNotification`.

  | Event | Recipients |
  |---|---|
  | `TicketCreated`, not imported | the customer, plus all admins when `tickets.auto_assign` is false |
  | `TicketCreated`, imported | nobody |
  | `TicketAssigned` | the assigned agent and the customer |
  | `TicketReplied`, public, author is not the customer | the customer |
  | `TicketReplied`, public, author is the customer | the assigned agent, or all admins if unassigned |
  | `TicketReplied`, internal | the assigned agent if not the author; **never the customer**; database only |
  | `TicketResolved` | the customer |
  | `TicketSlaApproaching` | the assigned agent, or all admins if unassigned |
  | `TicketSlaBreached` | the assigned agent and all admins, de-duplicated |
  | `TicketImportFinished` | the importing admin; database only |

- Routes: web `GET /notifications` `notifications.index`, `POST /notifications/{notification}/read` `notifications.read`, `POST /notifications/read-all` `notifications.read-all`. API: the same paths under `/api/v1`, named `api.v1.notifications.index|read|read-all`. `{notification}` is a UUID.
- API bodies: index → paginated `NotificationResource` collection; read → `NotificationResource`; read-all → 204.
- `NotificationResource`: `id, type` (class basename), `ticket_number, title, body, url, read_at, created_at`.
- Inertia: `notifications/Index` with `items` (paginated `NotificationResource` collection) and `unreadOnly: bool`. The prop isn't called `notifications`, because that key is the shared prop. Shared prop: `notifications.unread_count: int`.

**Steps:**

1. Write `tests/Feature/Notifications/NotificationClassesTest.php`:

```php
beforeEach(function (): void {
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $this->publicReply = TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->create();
    $this->internalReply = TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->internal()->create();
    $this->import = (new TicketImport)->forceFill([
        'id' => 42,
        'user_id' => $this->admin->id,
        'original_name' => 'legacy.csv',
        'status' => ImportStatus::CompletedWithErrors,
        'succeeded_rows' => 8,
        'failed_rows' => 2,
    ]);
});

it('stores the inbox payload and uses the right channels', function (Closure $make, array $channels): void {
    $notification = $make();
    $payload = $notification->toArray($this->customer);
    expect(array_keys($payload))->toBe(['ticket_number', 'title', 'body', 'url'])
        ->and($payload['ticket_number'])->toBe($this->ticket->number)
        ->and($payload['url'])->toBe(route('tickets.show', $this->ticket))
        ->and($payload['title'])->not->toBe('')
        ->and($notification->via($this->customer))->toBe($channels);
    if ($channels === ['mail', 'database']) {
        expect($notification->toMail($this->customer)->actionUrl)->toBe(route('tickets.show', $this->ticket));
    }
})->with([
    'created' => [fn () => fn (): BaseNotification => new TicketCreatedNotification($this->ticket), ['mail', 'database']],
    'assigned' => [fn () => fn (): BaseNotification => new TicketAssignedNotification($this->ticket, $this->agent), ['mail', 'database']],
    'replied' => [fn () => fn (): BaseNotification => new TicketRepliedNotification($this->ticket, $this->publicReply), ['mail', 'database']],
    'internal note' => [fn () => fn (): BaseNotification => new InternalNoteAddedNotification($this->ticket, $this->internalReply), ['database']],
    'resolved' => [fn () => fn (): BaseNotification => new TicketResolvedNotification($this->ticket), ['mail', 'database']],
    'sla approaching' => [fn () => fn (): BaseNotification => new SlaApproachingNotification($this->ticket), ['mail', 'database']],
    'sla breached' => [fn () => fn (): BaseNotification => new SlaBreachedNotification($this->ticket), ['mail', 'database']],
]);

it('keeps the import summary in the inbox only, linking to the import', function (): void {
    $notification = new TicketImportFinishedNotification($this->import);
    $payload = $notification->toArray($this->admin);
    expect($notification->via($this->admin))->toBe(['database'])
        ->and($payload['ticket_number'])->toBeNull()
        ->and($payload['url'])->toBe(url('/admin/imports/42'))
        ->and($payload['body'])->toBe('8 tickets imported, 2 rows failed.');
});

it('refuses to build a public reply notification from an internal note', function (): void {
    expect(fn () => new TicketRepliedNotification($this->ticket, $this->internalReply))->toThrow(LogicException::class);
});

it('pushes notification jobs onto the notifications queue', function (): void {
    Queue::fake();
    event(new TicketAssigned($this->ticket, $this->admin, $this->agent, null, AssignmentMethod::Manual));
    Queue::assertPushed(SendQueuedNotifications::class, 4);
    Queue::assertNotPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue !== 'notifications');
});
```

2. Write `tests/Feature/Notifications/NotificationRecipientsTest.php`. `Queue::fake()` stops P3's auto-assign job from running. Each expected user gets the notification exactly once, and everyone else gets none.

```php
beforeEach(function (): void {
    Notification::fake();
    Queue::fake();
    config(['tickets.auto_assign' => false]);
    $this->u = [
        'customer' => User::factory()->customer()->create(),
        'agent' => User::factory()->agent()->create(),
        'other_agent' => User::factory()->agent()->create(),
        'admin1' => User::factory()->admin()->create(),
        'admin2' => User::factory()->admin()->create(),
    ];
    $this->ticket = fn (?string $assignee = 'agent'): Ticket => Ticket::factory()->status(TicketStatus::InProgress)->create([
        'customer_id' => $this->u['customer']->id,
        'created_by_id' => $this->u['customer']->id,
        'assigned_agent_id' => $assignee === null ? null : $this->u[$assignee]->id,
    ]);
    $this->reply = fn (Ticket $ticket, string $author, bool $internal): TicketReply => TicketReply::factory()
        ->for($ticket)
        ->for($this->u[$author], 'author')
        ->create(['is_internal' => $internal]);
    $this->expectRecipients = function (string $notification, array $expected): void {
        foreach ($this->u as $key => $user) {
            in_array($key, $expected, true)
                ? Notification::assertSentToTimes($user, $notification, 1)
                : Notification::assertNotSentTo($user, $notification);
        }
    };
});

it('notifies on TicketCreated', function (?string $actor, bool $autoAssign, bool $imported, array $expected): void {
    config(['tickets.auto_assign' => $autoAssign]);
    event(new TicketCreated(($this->ticket)(null), $actor === null ? null : $this->u[$actor], $imported));
    ($this->expectRecipients)(TicketCreatedNotification::class, $expected);
})->with([
    'customer self-service, manual assignment' => ['customer', false, false, ['customer', 'admin1', 'admin2']],
    'agent on behalf of the customer' => ['agent', false, false, ['customer', 'admin1', 'admin2']],
    'admin on behalf: that admin is skipped' => ['admin1', false, false, ['customer', 'admin2']],
    'auto-assign on: admins are not told' => ['customer', true, false, ['customer']],
    'imported: nobody' => ['admin1', false, true, []],
]);

it('notifies on TicketAssigned', function (?string $actor, string $agent, AssignmentMethod $method, array $expected): void {
    event(new TicketAssigned(($this->ticket)($agent), $actor === null ? null : $this->u[$actor], $this->u[$agent], null, $method));
    ($this->expectRecipients)(TicketAssignedNotification::class, $expected);
})->with([
    'admin assigns' => ['admin1', 'agent', AssignmentMethod::Manual, ['agent', 'customer']],
    'agent claims: no notice to themselves' => ['agent', 'agent', AssignmentMethod::Claimed, ['customer']],
    'automatic assignment' => [null, 'agent', AssignmentMethod::Automatic, ['agent', 'customer']],
    'hand-off to a colleague' => ['agent', 'other_agent', AssignmentMethod::Manual, ['other_agent', 'customer']],
]);

it('notifies on TicketReplied', function (string $author, ?string $assignee, bool $internal, string $notification, array $expected): void {
    $ticket = ($this->ticket)($assignee);
    event(new TicketReplied($ticket, ($this->reply)($ticket, $author, $internal), $this->u[$author]));
    ($this->expectRecipients)($notification, $expected);
})->with([
    'agent public reply → customer' => ['agent', 'agent', false, TicketRepliedNotification::class, ['customer']],
    'admin public reply → customer' => ['admin1', 'agent', false, TicketRepliedNotification::class, ['customer']],
    'customer reply → assigned agent' => ['customer', 'agent', false, TicketRepliedNotification::class, ['agent']],
    'customer reply, unassigned → admins' => ['customer', null, false, TicketRepliedNotification::class, ['admin1', 'admin2']],
    'admin replies on own ticket → customer only' => ['admin1', 'admin1', false, TicketRepliedNotification::class, ['customer']],
    'internal note by a colleague → assignee' => ['other_agent', 'agent', true, InternalNoteAddedNotification::class, ['agent']],
    'internal note by the assignee → nobody' => ['agent', 'agent', true, InternalNoteAddedNotification::class, []],
    'internal note, unassigned → nobody' => ['admin1', null, true, InternalNoteAddedNotification::class, []],
]);

it('sends the customer nothing at all for an internal note', function (?string $assignee, string $author): void {
    $ticket = ($this->ticket)($assignee);
    event(new TicketReplied($ticket, ($this->reply)($ticket, $author, true), $this->u[$author]));
    Notification::assertNothingSentTo($this->u['customer']);
})->with([
    'colleague writes, agent assigned' => ['agent', 'other_agent'],
    'admin writes, unassigned' => [null, 'admin1'],
    'assignee writes' => ['agent', 'agent'],
]);

it('notifies on TicketResolved', function (?string $actor, array $expected): void {
    event(new TicketResolved(($this->ticket)('agent'), $actor === null ? null : $this->u[$actor]));
    ($this->expectRecipients)(TicketResolvedNotification::class, $expected);
})->with([
    'agent resolves' => ['agent', ['customer']],
    'system resolves' => [null, ['customer']],
    'customer acted: no notice to themselves' => ['customer', []],
]);

it('notifies on SLA events', function (string $event, ?string $assignee, string $notification, array $expected): void {
    event(new $event(($this->ticket)($assignee)));
    ($this->expectRecipients)($notification, $expected);
})->with([
    'approaching, assigned → agent' => [TicketSlaApproaching::class, 'agent', SlaApproachingNotification::class, ['agent']],
    'approaching, unassigned → admins' => [TicketSlaApproaching::class, null, SlaApproachingNotification::class, ['admin1', 'admin2']],
    'breached, assigned → agent and admins' => [TicketSlaBreached::class, 'agent', SlaBreachedNotification::class, ['agent', 'admin1', 'admin2']],
    'breached, unassigned → admins' => [TicketSlaBreached::class, null, SlaBreachedNotification::class, ['admin1', 'admin2']],
    'breached, assigned to an admin → that admin once' => [TicketSlaBreached::class, 'admin1', SlaBreachedNotification::class, ['admin1', 'admin2']],
]);

it('notifies only the importing admin when an import finishes', function (): void {
    $import = (new TicketImport)->forceFill([
        'id' => 7,
        'user_id' => $this->u['admin1']->id,
        'original_name' => 'tickets.csv',
        'status' => ImportStatus::Completed,
        'succeeded_rows' => 3,
        'failed_rows' => 0,
    ]);
    event(new TicketImportFinished($import));
    ($this->expectRecipients)(TicketImportFinishedNotification::class, ['admin1']);
});
```

3. Write `tests/Feature/Notifications/NotificationInboxTest.php`:

```php
beforeEach(function (): void {
    $this->me = User::factory()->agent()->create();
    $this->someoneElse = User::factory()->agent()->create();
    $this->store = fn (User $user, bool $read = false, string $title = 'Assigned'): DatabaseNotification => $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssignedNotification::class,
        'data' => ['ticket_number' => 'TKT-2026-000001', 'title' => $title, 'body' => 'You have a new ticket.', 'url' => url('/tickets/TKT-2026-000001')],
        'read_at' => $read ? now() : null,
    ]);
});

it('lists only my notifications, newest first, paginated, optionally unread only', function (): void {
    ($this->store)($this->me, title: 'older');
    $this->travel(1)->minutes();
    ($this->store)($this->me, read: true, title: 'newer');
    ($this->store)($this->someoneElse, title: 'not mine');
    Sanctum::actingAs($this->me, ['*']);
    $this->getJson(route('api.v1.notifications.index', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'newer')
        ->assertJsonPath('data.0.type', 'TicketAssignedNotification')
        ->assertJsonPath('data.0.ticket_number', 'TKT-2026-000001')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissing(['title' => 'not mine']);
    $this->getJson(route('api.v1.notifications.index', ['unread' => 1]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'older');
});

it('renders the inbox props and shares my unread count', function (): void {
    ($this->store)($this->me);
    ($this->store)($this->me);
    ($this->store)($this->me, read: true);
    ($this->store)($this->someoneElse);
    $this->actingAs($this->me)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/Index', false)
            ->has('items.data', 3)
            ->where('unreadOnly', false)
            ->where('notifications.unread_count', 2));
});

it('marks my notification as read on web and API', function (): void {
    $apiOne = ($this->store)($this->me);
    $webOne = ($this->store)($this->me);
    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read', $apiOne->id))->assertOk()->assertJsonPath('data.id', $apiOne->id);
    $this->actingAs($this->me)
        ->from(route('notifications.index'))
        ->post(route('notifications.read', $webOne->id))
        ->assertRedirect(route('notifications.index'));
    expect($apiOne->fresh()->read_at)->not->toBeNull()
        ->and($webOne->fresh()->read_at)->not->toBeNull();
});

it("answers 404 for another user's notification and leaves it unread", function (): void {
    $theirs = ($this->store)($this->someoneElse);
    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read', $theirs->id))->assertNotFound();
    $this->actingAs($this->me)->post(route('notifications.read', $theirs->id))->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('marks all of my notifications as read and leaves others alone', function (): void {
    ($this->store)($this->me);
    $theirs = ($this->store)($this->someoneElse);
    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read-all'))->assertNoContent();
    ($this->store)($this->me);
    $this->actingAs($this->me)
        ->from(route('notifications.index'))
        ->post(route('notifications.read-all'))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHas('success');
    expect($this->me->unreadNotifications()->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();
});
```

4. Run the three files and confirm they fail (`Class "App\Notifications\TicketCreatedNotification" not found`, `Route [api.v1.notifications.index] not defined`).

5. Create `app/Notifications/BaseNotification.php`. The `notifications` queue keeps user-facing mail clear of imports (TD-072), and `afterCommit()` stops a notification from announcing a rolled-back change (TD-071).

```php
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->line($this->body($notifiable))
            ->action($this->actionText(), $this->url());
    }
    /** @return array{ticket_number: string|null, title: string, body: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_number' => $this->ticketNumber(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url(),
        ];
    }

    abstract protected function title(object $notifiable): string;

    abstract protected function body(object $notifiable): string;

    abstract protected function url(): string;

    protected function ticketNumber(): ?string
    {
        return null;
    }

    protected function actionText(): string
    {
        return 'View ticket';
    }
}
```

6. Create `app/Notifications/TicketNotification.php`, the shared base for the seven ticket notifications:

```php
abstract class TicketNotification extends BaseNotification
{
    public function __construct(public readonly Ticket $ticket)
    {
        parent::__construct();
    }

    protected function url(): string
    {
        return route('tickets.show', $this->ticket);
    }

    protected function ticketNumber(): ?string
    {
        return $this->ticket->number;
    }

    protected function isCustomer(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->getKey() === $this->ticket->customer_id;
    }

    protected function dueAt(): string
    {
        return $this->ticket->sla_due_at
            ->setTimezone((string) config('business_hours.timezone'))
            ->format('D j M Y H:i T');
    }
}
```

7. Create the seven ticket notifications. Each is `final`, extends `TicketNotification`, and defines `title()` and `body()` as below. Classes with extra constructor arguments call `parent::__construct($ticket)`. `Str` is `Illuminate\Support\Str`.

```php
final class TicketCreatedNotification extends TicketNotification
{
    protected function title(object $notifiable): string
    {
        return $this->isCustomer($notifiable)
            ? sprintf('We received your ticket %s', $this->ticket->number)
            : sprintf('New ticket %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return $this->isCustomer($notifiable)
            ? sprintf('Your ticket "%s" has been created. We will reply as soon as possible.', $this->ticket->subject)
            : sprintf('"%s" is waiting to be assigned.', $this->ticket->subject);
    }
}
```

```php
final class TicketAssignedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public readonly User $agent)
    {
        parent::__construct($ticket);
    }

    protected function title(object $notifiable): string
    {
        return $this->isAgent($notifiable)
            ? sprintf('Ticket %s was assigned to you', $this->ticket->number)
            : sprintf('Your ticket %s has an agent', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return $this->isAgent($notifiable)
            ? sprintf('"%s" is now yours.', $this->ticket->subject)
            : sprintf('%s is now handling "%s".', $this->agent->name, $this->ticket->subject);
    }

    private function isAgent(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->getKey() === $this->agent->getKey();
    }
}
```

`TicketRepliedNotification` reaches customers by mail, so it refuses an internal reply (TD-120):

```php
final class TicketRepliedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public readonly TicketReply $reply)
    {
        if ($reply->is_internal) {
            throw new LogicException('Internal notes use InternalNoteAddedNotification.');
        }
        parent::__construct($ticket);
    }

    protected function title(object $notifiable): string
    {
        return sprintf('New reply on %s', $this->ticket->number);
    }

    protected function body(object $notifiable): string
    {
        return Str::limit($this->reply->body, 160);
    }
}
```

`InternalNoteAddedNotification` has the same constructor (no `is_internal` check) and the same `body()`, with `title()` returning `sprintf('Internal note on %s', $this->ticket->number)`. It overrides `via()` so note text never leaves the system by mail:

```php
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }
```

| Class | `title()` | `body()` |
|---|---|---|
| `TicketResolvedNotification` | `sprintf('Ticket %s was resolved', $this->ticket->number)` | `sprintf('"%s" is resolved. Reopen it from the ticket page if the problem persists.', $this->ticket->subject)` |
| `SlaApproachingNotification` | `sprintf('SLA approaching on %s', $this->ticket->number)` | `sprintf('"%s" is due %s.', $this->ticket->subject, $this->dueAt())` |
| `SlaBreachedNotification` | `sprintf('SLA breached on %s', $this->ticket->number)` | `sprintf('"%s" passed its resolution deadline (%s) and has been escalated.', $this->ticket->subject, $this->dueAt())` |

8. Create `app/Notifications/TicketImportFinishedNotification.php`. The URL is a path, because `admin.imports.show` is defined by P4.3 in the same wave.

```php
final class TicketImportFinishedNotification extends BaseNotification
{
    public function __construct(public readonly TicketImport $import)
    {
        parent::__construct();
    }
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    protected function title(object $notifiable): string
    {
        return sprintf('Import of %s finished', $this->import->original_name);
    }

    protected function body(object $notifiable): string
    {
        return sprintf('%d tickets imported, %d rows failed.', $this->import->succeeded_rows, $this->import->failed_rows);
    }

    protected function url(): string
    {
        return url('/admin/imports/'.$this->import->getKey());
    }

    protected function actionText(): string
    {
        return 'View import';
    }
}
```

9. Create `app/Services/Notifications/Recipients.php`. Users are loaded fresh by id with `id, name, email`, so listeners never read a relation from the event's ticket, and lazy loading can't trigger. Admins are a small, admin-managed set.

```php
final class Recipients
{
    private const COLUMNS = ['id', 'name', 'email'];

    public function find(?int $userId): ?User
    {
        return $userId === null ? null : User::query()->whereKey($userId)->first(self::COLUMNS);
    }
    /** @return Collection<int, User> */
    public function admins(): Collection
    {
        return User::query()->admins()->orderBy('id')->get(self::COLUMNS)->toBase();
    }
    /**
     * @param  iterable<int, User|null>  $users
     * @return Collection<int, User>
     */
    public function excluding(iterable $users, ?User $actor): Collection
    {
        return collect($users)
            ->filter()
            ->unique(static fn (User $user): mixed => $user->getKey())
            ->reject(static fn (User $user): bool => $actor !== null && $user->getKey() === $actor->getKey())
            ->values();
    }
}
```

10. Create the listeners. Each is `final`, takes `Recipients` through constructor promotion (`public function __construct(private readonly Recipients $recipients) {}`), and uses `Illuminate\Support\Facades\Notification`. The `handle` bodies:

```php
final class SendTicketCreatedNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handle(TicketCreated $event): void
    {
        if ($event->imported) {
            return;
        }
        $customer = $this->recipients->find($event->ticket->customer_id);
        if ($customer !== null) {
            Notification::send($customer, new TicketCreatedNotification($event->ticket));
        }
        if (! config('tickets.auto_assign')) {
            Notification::send(
                $this->recipients->excluding($this->recipients->admins(), $event->actor),
                new TicketCreatedNotification($event->ticket),
            );
        }
    }
}
```

```php
    public function handle(TicketAssigned $event): void
    {
        $recipients = $this->recipients->excluding([
            $this->recipients->find($event->agent->id),
            $this->recipients->find($event->ticket->customer_id),
        ], $event->actor);
        Notification::send($recipients, new TicketAssignedNotification($event->ticket, $event->agent));
    }
```

`SendTicketRepliedNotifications` is the listener layer of the internal-note boundary (TD-120). For a note, only the assignee is a candidate, and the customer is rejected even if they were somehow assigned.

```php
    public function handle(TicketReplied $event): void
    {
        $ticket = $event->ticket;
        if ($event->reply->is_internal) {
            $recipients = $this->recipients
                ->excluding([$this->recipients->find($ticket->assigned_agent_id)], $event->author)
                ->reject(static fn (User $user): bool => $user->getKey() === $ticket->customer_id);
            Notification::send($recipients, new InternalNoteAddedNotification($ticket, $event->reply));
            return;
        }
        $targets = match (true) {
            $event->author->id !== $ticket->customer_id => [$this->recipients->find($ticket->customer_id)],
            $ticket->assigned_agent_id !== null => [$this->recipients->find($ticket->assigned_agent_id)],
            default => $this->recipients->admins(),
        };
        Notification::send($this->recipients->excluding($targets, $event->author), new TicketRepliedNotification($ticket, $event->reply));
    }
```

```php
    public function handle(TicketResolved $event): void
    {
        Notification::send(
            $this->recipients->excluding([$this->recipients->find($event->ticket->customer_id)], $event->actor),
            new TicketResolvedNotification($event->ticket),
        );
    }
```

```php
final class SendSlaNotifications
{
    public function __construct(private readonly Recipients $recipients) {}

    public function handleApproaching(TicketSlaApproaching $event): void
    {
        $agent = $this->recipients->find($event->ticket->assigned_agent_id);
        Notification::send(
            $this->recipients->excluding($agent !== null ? [$agent] : $this->recipients->admins(), null),
            new SlaApproachingNotification($event->ticket),
        );
    }

    public function handleBreached(TicketSlaBreached $event): void
    {
        $targets = [$this->recipients->find($event->ticket->assigned_agent_id), ...$this->recipients->admins()->all()];
        Notification::send($this->recipients->excluding($targets, null), new SlaBreachedNotification($event->ticket));
    }
}
```

```php
    public function handle(TicketImportFinished $event): void
    {
        $admin = $this->recipients->find($event->import->user_id);
        if ($admin !== null) {
            Notification::send($admin, new TicketImportFinishedNotification($event->import));
        }
    }
```

11. Create `app/Actions/Notifications/MarkNotificationRead.php` and `MarkAllNotificationsRead.php`. The lookup goes through the owner's relation, so another user's id is a 404, and mark-all is one UPDATE served by `notifications(notifiable_type, notifiable_id, read_at)`.

```php
final class MarkNotificationRead
{
    public function handle(User $user, string $notificationId): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();
        return $notification;
    }
}

final class MarkAllNotificationsRead
{
    public function handle(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => CarbonImmutable::now()]);
    }
}
```

12. Create `app/Services/Notifications/NotificationFeed.php`. The relation already orders newest first.

```php
final class NotificationFeed
{
    /** @return LengthAwarePaginator<int, DatabaseNotification> */
    public function for(User $user, int $perPage, bool $unreadOnly): LengthAwarePaginator
    {
        $relation = $unreadOnly ? $user->unreadNotifications() : $user->notifications();
        return $relation->select(['id', 'type', 'data', 'read_at', 'created_at'])->paginate($perPage)->withQueryString();
    }
}
```

13. Create `app/Http/Requests/Notifications/IndexNotificationsRequest.php` with `authorize(): bool` returning true, rules `per_page` (`sometimes|integer|min:1|max:100`), `page` (`sometimes|integer|min:1`) and `unread` (`sometimes|boolean`), plus `perPage(): int` (`$this->integer('per_page', 20)`) and `unreadOnly(): bool` (`$this->boolean('unread')`).

14. Create `app/Http/Resources/V1/NotificationResource.php`:

```php
/** @mixin DatabaseNotification */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data;
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'ticket_number' => $data['ticket_number'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'url' => $data['url'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

15. Create the controllers. The page prop is `items`, because a page prop called `notifications` would shadow the shared prop.

```php
final class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request, NotificationFeed $feed): Response
    {
        return Inertia::render('notifications/Index', [
            'items' => NotificationResource::collection($feed->for($this->user($request), $request->perPage(), $request->unreadOnly())),
            'unreadOnly' => $request->unreadOnly(),
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationRead $markRead): RedirectResponse
    {
        $markRead->handle($this->user($request), $notification);
        return back();
    }

    public function readAll(Request $request, MarkAllNotificationsRead $markAllRead): RedirectResponse
    {
        $count = $markAllRead->handle($this->user($request));
        return back()->with('success', trans_choice('{1} :count notification marked as read.|[0,*] :count notifications marked as read.', $count, ['count' => $count]));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);
        return $user;
    }
}
```

The API controller (`App\Http\Controllers\Api\V1\NotificationController`) has the same methods and the same `user()` helper. `index` returns `NotificationResource::collection(...)` as an `AnonymousResourceCollection`, `read` returns `NotificationResource::make($markRead->handle(...))`, and `readAll` returns `response()->noContent()`.

16. Create `app/Policies/NotificationPolicy.php`. Everyone who may see tickets has an inbox, and a user with no role is denied. Register it in `AppServiceProvider::boot()` after the `viewDashboard` gate with `Gate::policy(DatabaseNotification::class, NotificationPolicy::class);`, because the notification model lives outside `App\Models` and isn't auto-discovered.

```php
final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TicketsViewOwn->value) || $user->can(Permission::TicketsViewAny->value);
    }
}
```

17. Create the routes. `read-all` is registered before `{notification}/read`, and `whereUuid` keeps the two apart. Every route is guarded by the policy.

```php
Route::middleware('auth')->can('viewAny', DatabaseNotification::class)->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->name('notifications.read');
});
```

`routes/api/v1/notifications.php` is identical except that it uses `App\Http\Controllers\Api\V1\NotificationController` and `Route::middleware('auth:sanctum')`. If `Route::middleware(...)->can(...)` doesn't chain on a route group in this Laravel version, put `->can('viewAny', DatabaseNotification::class)` on each of the three routes instead.

18. In `HandleInertiaRequests::share()`, replace only the `'notifications'` value. It stays lazy, so it runs only when a page renders.

```php
            'notifications' => [
                'unread_count' => fn (): int => $user?->unreadNotifications()->count() ?? 0,
            ],
```

19. Run: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Notifications tests/Feature/App/InertiaSharedPropsTest.php`. All pass. `InertiaSharedPropsTest` still sees `0` for a user without notifications. P4.5 rewrites that test's route in the same wave, so if it fails on the `dashboard` route alone, that's P4.5's change and not yours.

**Proposed commit:** `feat(notifications): notify ticket events and expose the inbox` · `Refs: P4.2` · `Implements: TD-070, TD-071, TD-072, TD-120`

---

## P4.3 — CSV import pipeline and HTTP

**Files:**
- create `config/imports.php`
- modify `app/Enums/ImportStatus.php` (add `label()` and `isFinished()`)
- modify `app/Services/Sla/SlaCalculator.php` (add `applyCurrentLevel()`)
- create `app/Services/Imports/TicketCsvReader.php`, `ImportTicketRowRules.php`, `TicketImportQuery.php`, `ImportErrorCsvWriter.php` (all in `app/Services/Imports/`)
- create `app/Actions/Imports/StartTicketImport.php`, `app/Actions/Imports/ImportTicketRow.php`
- create `app/Jobs/PrepareTicketImport.php`, `app/Jobs/ImportTicketsChunk.php`, `app/Jobs/FinalizeTicketImport.php`
- create `app/Http/Requests/Imports/StoreTicketImportRequest.php`, `app/Http/Requests/Imports/IndexImportErrorsRequest.php`
- create `app/Http/Resources/V1/TicketImportResource.php`, `app/Http/Resources/V1/TicketImportErrorResource.php`
- create `app/Http/Controllers/Web/Admin/TicketImportController.php`, `app/Http/Controllers/Api/V1/TicketImportController.php`
- create `routes/web/imports.php`, `routes/api/v1/imports.php`
- create `tests/Support/Imports/ImportCsvFixture.php`, `tests/Unit/Imports/TicketCsvReaderTest.php`, `tests/Feature/Imports/ImportTicketRowTest.php`, `tests/Feature/Imports/TicketImportPipelineTest.php`, `tests/Feature/Imports/PrepareTicketImportMemoryTest.php`, `tests/Feature/Imports/TicketImportHttpTest.php`

**Consumes:** `TicketNumberGenerator::next(CarbonImmutable)`, `SlaCalculator::applyInitial/pause/levelFor`, `TicketCreated(ticket, actor, imported: true)` (P3's auto-assign and P4.2's notifications both skip it), `TicketImportFinished(import)`, `ImportHeaderInvalid(string $message)`, `TicketImportPolicy::viewAny/view/create`, the `uploads` rate limiter (20 per minute, `AppServiceProvider`).

**Produces:**
- `Imports\StartTicketImport::handle(User $admin, UploadedFile $file): TicketImport` and `Imports\ImportTicketRow::handle(TicketImport $import, array $row, int $rowNumber): ?Ticket`.
- Jobs on queue `imports`: `PrepareTicketImport(int $importId)` (`tries=3`), `ImportTicketsChunk(int $importId, int $startLine, int $endLine)` (`Batchable`, `tries=1`, TD-072 amendment), `FinalizeTicketImport(int $importId)` (`tries=3`).
- `ImportStatus::label(): string` and `ImportStatus::isFinished(): bool`.
- `SlaCalculator::applyCurrentLevel(Ticket $ticket, CarbonImmutable $now): void`. It sets `escalation_level` to `levelFor()`, so an overdue historic ticket arrives already escalated and the next `sla:scan` doesn't send a burst of breach notifications for an import.
- Routes:
  - web: `admin.imports.index` `GET /admin/imports`, `admin.imports.create` `GET /admin/imports/create`, `admin.imports.store` `POST /admin/imports`, `admin.imports.show` `GET /admin/imports/{import}`, `admin.imports.errors.download` `GET /admin/imports/{import}/errors/download`
  - API: `api.v1.imports.store` `POST /api/v1/imports` (202), `api.v1.imports.show` `GET /api/v1/imports/{import}`, `api.v1.imports.errors.index` `GET /api/v1/imports/{import}/errors`
- `TicketImportResource`: `id, original_name, status{value,label}, is_finished, total_rows, processed_rows, succeeded_rows, failed_rows, progress_percent, started_at, finished_at, created_at, user{id,name}` (when loaded). `TicketImportErrorResource`: `id, row_number, errors{field: string[]}, raw{column: string|null}, created_at`.
- Inertia: `admin/imports/Index` with `imports` (paginated `TicketImportResource`, 20 per page, newest first); `admin/imports/Create` with `maxUploadKb: int` and `columns: {required: string[], optional: string[]}`; `admin/imports/Show` with `ticketImport` (`TicketImportResource`) and `importErrors` (paginated `TicketImportErrorResource`, 25 per page, by row number). Both Show props are closures, so a poll with `only: ['ticketImport', 'importErrors']` re-evaluates just those two. The prop is `ticketImport` because `import` is reserved in Vue template expressions.

**CSV format.** The header is case-insensitive, may be in any order, and may start with a UTF-8 BOM: `external_reference?, subject, description, priority, category` (slug), `customer_email, status?, created_at?`. A `created_at` without an offset is read in `config('business_hours.timezone')` and stored as UTC. `row_number` counts CSV records with the header as row 1, so the first data row is row 2. Internally, `SplFileObject::key()` is the 0-based record index (header = 0), and a chunk's `startLine`/`endLine` are inclusive record indices.

**Steps:**

1. Create `tests/Support/Imports/ImportCsvFixture.php`. The mixed file has nine data rows (CSV rows 2–10): two importable and seven rejected for different reasons. It expects category `hardware`, customer `customer@example.com`, agent `agent@example.com` and an existing ticket with reference `EXT-OLD`.

```php
final class ImportCsvFixture
{
    public static function mixed(): string
    {
        return implode("\n", [
            'external_reference,subject,description,priority,category,customer_email,status,created_at',
            'EXT-1,Printer on fire,The office printer is on fire,critical,hardware,customer@example.com,,2026-10-29 17:00',
            'EXT-2,=SUM(A1:A2),Formula in subject,urgent,hardware,customer@example.com,,',
            'EXT-1,Duplicate in file,Same reference as row 2,low,hardware,customer@example.com,,',
            'EXT-OLD,Already imported,Reference exists in the database,low,hardware,customer@example.com,,',
            ',Waiting on customer,No reference and no date,low,hardware,CUSTOMER@example.com,waiting_for_customer,',
            ',Unknown customer,Email has no account,medium,hardware,nobody@example.com,,',
            ',From the future,Created in 2030,medium,hardware,customer@example.com,,2030-01-01 10:00',
            ',Unknown category,Slug does not exist,high,plumbing,customer@example.com,,',
            ',Agent as customer,Email belongs to an agent,high,hardware,agent@example.com,,',
        ])."\n";
    }
    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    public static function row(array $overrides = []): array
    {
        return array_merge([
            'external_reference' => 'EXT-ROW',
            'subject' => 'Printer on fire',
            'description' => 'The office printer is on fire',
            'priority' => 'critical',
            'category' => 'hardware',
            'customer_email' => 'customer@example.com',
            'status' => null,
            'created_at' => '2026-10-29 17:00',
        ], $overrides);
    }

    public static function pendingImport(User $admin, string $path = 'imports/fixture.csv'): TicketImport
    {
        $import = new TicketImport(['original_name' => 'fixture.csv', 'path' => $path]);
        $import->user_id = $admin->id;
        $import->save();
        return $import->refresh();
    }

    public static function run(User $admin, string $csv, string $name = 'tickets.csv'): TicketImport
    {
        return app(StartTicketImport::class)
            ->handle($admin, UploadedFile::fake()->createWithContent($name, $csv))
            ->refresh();
    }
}
```

2. Write `tests/Unit/Imports/TicketCsvReaderTest.php`. It touches only a temp file and never the database.

```php
function csvFixturePath(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tktcsv');
    file_put_contents($path, $contents);
    return $path;
}

function multiLineCsv(): string
{
    return implode("\n", [
        'subject,description,priority,category,customer_email',
        "A,\"line one\nline two\",low,general,a@example.com",
        'B,desc,low,general,b@example.com',
        '',
        'C,desc,low,general,c@example.com',
        'D,desc,low,general,d@example.com',
    ])."\n";
}

it('normalises the header: BOM, case, surrounding spaces and column order', function (): void {
    $path = csvFixturePath("\xEF\xBB\xBF Subject ,DESCRIPTION,priority,Category,customer_email,created_at\n");
    expect((new TicketCsvReader)->header($path))
        ->toBe(['subject', 'description', 'priority', 'category', 'customer_email', 'created_at']);
});

it('rejects an invalid header with a precise message', function (string $contents, string $message): void {
    expect(fn () => (new TicketCsvReader)->header(csvFixturePath($contents)))
        ->toThrow(ImportHeaderInvalid::class, $message);
})->with([
    'missing columns' => ["subject,description,priority\n", 'The CSV header is invalid: missing required columns: category, customer_email.'],
    'unknown and duplicate columns' => ["subject,description,priority,category,customer_email,colour,subject\n", 'The CSV header is invalid: unknown columns: colour; duplicate columns: subject.'],
    'empty file' => ['', 'The CSV file is empty or has no header row.'],
]);

it('counts records, skipping blank lines and keeping a quoted multi-line field as one record', function (): void {
    expect((new TicketCsvReader)->scan(csvFixturePath(multiLineCsv())))->toBe(['rows' => 4, 'last_line' => 5]);
});

it('yields only the requested record range, keyed by record index', function (): void {
    $path = csvFixturePath(multiLineCsv());
    $records = (new TicketCsvReader)->records($path, 2, 4)->all();
    $first = (new TicketCsvReader)->records($path, 1, 1)->all();
    expect(array_keys($records))->toBe([2, 4])
        ->and($records[4][0])->toBe('C')
        ->and($first[1][1])->toBe("line one\nline two");
});
```

3. Write `tests/Feature/Imports/ImportTicketRowTest.php`. The first test is the PRD example: Critical, Thursday 17:00 Asia/Dhaka is due Sunday 10:00 Dhaka (04:00 UTC). By the frozen "now" (Monday) that deadline has passed, so the ticket arrives breached.

```php
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create(['email' => 'customer@example.com']);
    $this->import = ImportCsvFixture::pendingImport($this->admin);
});

it('starts the SLA clock at the CSV created_at, read in business time', function (): void {
    $ticket = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(), 2)?->fresh();
    expect($ticket?->number)->toMatch('/^TKT-2026-\d{6}$/')
        ->and($ticket?->status)->toBe(TicketStatus::Open)
        ->and($ticket?->customer_id)->toBe($this->customer->id)
        ->and($ticket?->created_by_id)->toBe($this->admin->id)
        ->and($ticket?->created_at->toDateTimeString())->toBe('2026-10-29 11:00:00')
        ->and($ticket?->sla_started_at->toDateTimeString())->toBe('2026-10-29 11:00:00')
        ->and($ticket?->sla_warning_at->toDateTimeString())->toBe('2026-11-01 03:30:00')
        ->and($ticket?->sla_due_at->toDateTimeString())->toBe('2026-11-01 04:00:00')
        ->and($ticket?->escalation_level)->toBe(EscalationLevel::Breached);
});

it('starts the SLA clock at import time when created_at is empty', function (): void {
    $ticket = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(['created_at' => null]), 2)?->fresh();
    expect($ticket?->sla_started_at->toDateTimeString())->toBe('2026-11-02 06:00:00')
        ->and($ticket?->escalation_level)->toBe(EscalationLevel::None);
});

it('applies the side effects of the initial status', function (string $status, bool $paused, bool $resolved, bool $closed): void {
    $ticket = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['status' => $status, 'created_at' => null]), 2)
        ?->fresh();
    expect($ticket?->status->value)->toBe($status)
        ->and($ticket?->sla_paused_at !== null)->toBe($paused)
        ->and($ticket?->resolved_at !== null)->toBe($resolved)
        ->and($ticket?->closed_at !== null)->toBe($closed);
})->with([
    'waiting pauses the SLA' => ['waiting_for_customer', true, false, false],
    'resolved stamps resolved_at' => ['resolved', false, true, false],
    'closed stamps both' => ['closed', false, true, true],
]);

it('fires TicketCreated flagged as imported, with the importing admin as actor', function (): void {
    Event::fake([TicketCreated::class]);
    app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(), 2);
    Event::assertDispatched(TicketCreated::class, fn (TicketCreated $event): bool => $event->imported
        && $event->actor?->is($this->admin) === true);
});

it('turns a unique-index race on external_reference into a duplicate error row and rolls the ticket back', function (): void {
    Ticket::factory()->create(['external_reference' => 'EXT-RACE']);
    $ticketsBefore = Ticket::query()->count();
    $sequenceBefore = (int) DB::table('ticket_sequences')->sum('last_number');
    $result = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(['external_reference' => 'EXT-RACE']), 7);
    $error = TicketImportError::query()->where('ticket_import_id', $this->import->id)->sole();
    expect($result)->toBeNull()
        ->and(Ticket::query()->count())->toBe($ticketsBefore)
        ->and((int) DB::table('ticket_sequences')->sum('last_number'))->toBe($sequenceBefore)
        ->and($error->row_number)->toBe(7)
        ->and($error->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($error->raw['external_reference'])->toBe('EXT-RACE');
});
```

4. Write `tests/Feature/Imports/TicketImportPipelineTest.php`. On the sync driver, one `StartTicketImport::handle()` call runs Prepare, every batch chunk and Finalize inline, through the real `job_batches` row and callbacks. Chunks of 3 records split the nine rows into three batch jobs.

```php
beforeEach(function (): void {
    expect(config('queue.default'))->toBe('sync');
    Notification::fake();
    Storage::fake((string) config('imports.disk'));
    config(['imports.chunk_size' => 3]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent()->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);
});

it('imports the valid rows with distinct numbers and finishes with errors', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    $imported = Ticket::query()->where('created_by_id', $this->admin->id)->orderBy('id')->get();
    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and([$import->total_rows, $import->processed_rows, $import->succeeded_rows, $import->failed_rows])->toBe([9, 9, 2, 7])
        ->and($import->batch_id)->not->toBeNull()
        ->and($import->started_at)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull()
        ->and($imported->pluck('subject')->all())->toBe(['Printer on fire', 'Waiting on customer'])
        ->and($imported->pluck('number')->unique())->toHaveCount(2)
        ->and($imported[1]->status)->toBe(TicketStatus::WaitingForCustomer);
});

it('records each rejected row with its row number, messages and raw values', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    $errors = TicketImportError::query()->where('ticket_import_id', $import->id)->orderBy('row_number')->get()->keyBy('row_number');
    expect($errors->keys()->all())->toBe([3, 4, 5, 7, 8, 9, 10])
        ->and($errors[3]->errors)->toBe(['priority' => ['The selected priority is invalid.']])
        ->and($errors[3]->raw['subject'])->toBe('=SUM(A1:A2)')
        ->and($errors[4]->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($errors[5]->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($errors[7]->errors)->toBe(['customer_email' => ['The customer email must belong to an existing customer.']])
        ->and($errors[8]->errors)->toBe(['created_at' => ['The created at field must not be in the future.']])
        ->and($errors[9]->errors)->toBe(['category' => ['The selected category is invalid.']])
        ->and($errors[10]->errors)->toBe(['customer_email' => ['The customer email must belong to an existing customer.']]);
});

it('fires TicketImportFinished exactly once', function (): void {
    Event::fake([TicketImportFinished::class]);
    ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    Event::assertDispatchedTimes(TicketImportFinished::class, 1);
});

it('fails the import on an invalid header without importing anything', function (): void {
    $import = ImportCsvFixture::run($this->admin, "subject,description,priority\nPrinter,Broken,low\n");
    $error = TicketImportError::query()->where('ticket_import_id', $import->id)->sole();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->batch_id)->toBeNull()
        ->and(Ticket::query()->count())->toBe(1)
        ->and($error->row_number)->toBe(1)
        ->and($error->errors)->toBe(['header' => ['The CSV header is invalid: missing required columns: category, customer_email.']]);
});

it('completes a file without rejected rows, including one with no data rows', function (string $csv, int $succeeded): void {
    $import = ImportCsvFixture::run($this->admin, $csv);
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->succeeded_rows)->toBe($succeeded)
        ->and($import->finished_at)->not->toBeNull();
})->with([
    'one valid row' => ["subject,description,priority,category,customer_email\nPrinter,Broken,low,hardware,customer@example.com\n", 1],
    'header only' => ["subject,description,priority,category,customer_email\n\n", 0],
]);
```

5. Write `tests/Feature/Imports/PrepareTicketImportMemoryTest.php`. The fixture is about 6 MB, so reading it whole would exceed the bound. A warm-up run on a tiny file keeps class autoloading out of the measurement.

```php
it('prepares a 10,000-row file in constant memory and batches it by record range', function (): void {
    Storage::fake((string) config('imports.disk'));
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $disk = Storage::disk((string) config('imports.disk'));
    $disk->makeDirectory('imports');
    $description = str_repeat('The printer on floor three is jammed again. ', 13);
    $handle = fopen($disk->path('imports/big.csv'), 'wb');
    fputcsv($handle, TicketCsvReader::COLUMNS, ',', '"', '');
    for ($i = 1; $i <= 10_000; $i++) {
        fputcsv($handle, ["EXT-{$i}", "Subject {$i}", $description, 'low', 'general', 'customer@example.com', '', ''], ',', '"', '');
    }
    fclose($handle);
    expect(filesize($disk->path('imports/big.csv')))->toBeGreaterThan(5_000_000);
    $disk->put('imports/warmup.csv', "subject,description,priority,category,customer_email\nA,B,low,general,c@example.com\n");
    app()->call([new PrepareTicketImport(ImportCsvFixture::pendingImport($admin, 'imports/warmup.csv')->id), 'handle']);
    $import = ImportCsvFixture::pendingImport($admin, 'imports/big.csv');
    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage();
    app()->call([new PrepareTicketImport($import->id), 'handle']);
    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(2 * 1024 * 1024)
        ->and($import->refresh()->status)->toBe(ImportStatus::Processing)
        ->and($import->total_rows)->toBe(10_000);
    Bus::assertBatched(function (PendingBatch $batch): bool {
        /** @var ImportTicketsChunk $first */
        $first = $batch->jobs->first();
        /** @var ImportTicketsChunk $last */
        $last = $batch->jobs->last();
        return $batch->jobs->count() === 20
            && $batch->queue() === 'imports'
            && $batch->allowsFailures()
            && [$first->startLine, $first->endLine] === [1, 500]
            && [$last->startLine, $last->endLine] === [9501, 10000];
    });
});
```

6. Write `tests/Feature/Imports/TicketImportHttpTest.php`:

```php
beforeEach(function (): void {
    Notification::fake();
    Storage::fake((string) config('imports.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent()->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);
});

it('rejects a non-CSV or oversized upload', function (Closure $file): void {
    $this->actingAs($this->admin)->post(route('admin.imports.store'), ['file' => $file()])->assertSessionHasErrors('file');
    expect(TicketImport::query()->count())->toBe(0);
})->with([
    'a PDF' => [fn () => fn (): UploadedFile => UploadedFile::fake()->create('tickets.pdf', 10, 'application/pdf')],
    'larger than 50 MB' => [fn () => fn (): UploadedFile => UploadedFile::fake()->create('tickets.csv', 51201, 'text/csv')],
]);

it('stores the upload privately under a random name and redirects to the import', function (): void {
    $response = $this->actingAs($this->admin)->post(route('admin.imports.store'), [
        'file' => UploadedFile::fake()->createWithContent('tickets.csv', ImportCsvFixture::mixed()),
    ]);
    $import = TicketImport::query()->sole();
    $response->assertRedirect(route('admin.imports.show', $import));
    expect($import->original_name)->toBe('tickets.csv')
        ->and($import->path)->toStartWith('imports/')
        ->and($import->path)->not->toContain('tickets.csv');
    Storage::disk((string) config('imports.disk'))->assertExists($import->path);
});

it('accepts an API upload with 202 and the import resource', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $this->postJson(route('api.v1.imports.store'), ['file' => UploadedFile::fake()->createWithContent('tickets.csv', ImportCsvFixture::mixed())])
        ->assertStatus(202)
        ->assertJsonPath('data.status.value', 'completed_with_errors')
        ->assertJsonPath('data.status.label', 'Completed with errors')
        ->assertJsonPath('data.total_rows', 9)
        ->assertJsonPath('data.failed_rows', 7)
        ->assertJsonPath('data.progress_percent', 100)
        ->assertJsonPath('data.is_finished', true)
        ->assertJsonPath('data.user.id', $this->admin->id);
});

it('renders the index, create and show props', function (): void {
    $older = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed(), 'first.csv');
    $newer = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed(), 'second.csv');
    $this->actingAs($this->admin)->get(route('admin.imports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/imports/Index', false)
            ->where('imports.data.0.id', $newer->id)
            ->where('imports.data.1.id', $older->id)
            ->where('imports.meta.total', 2));
    $this->actingAs($this->admin)->get(route('admin.imports.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/imports/Create', false)
            ->where('maxUploadKb', 51200)
            ->where('columns.required', ['subject', 'description', 'priority', 'category', 'customer_email']));
    $this->actingAs($this->admin)->get(route('admin.imports.show', $newer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/imports/Show', false)
            ->where('ticketImport.id', $newer->id)
            ->where('ticketImport.processed_rows', 9)
            ->where('importErrors.data.0.row_number', 3)
            ->where('importErrors.meta.total', 7));
});

it('shows an import and pages its errors over the API, capping per_page at 100', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    Sanctum::actingAs($this->admin, ['*']);
    $this->getJson(route('api.v1.imports.show', $import))->assertOk()->assertJsonPath('data.id', $import->id);
    $this->getJson(route('api.v1.imports.errors.index', ['import' => $import, 'per_page' => 2]))
        ->assertOk()
        ->assertJsonPath('data.0.row_number', 3)
        ->assertJsonPath('data.1.row_number', 4)
        ->assertJsonPath('meta.total', 7);
    $this->getJson(route('api.v1.imports.errors.index', ['import' => $import, 'per_page' => 101]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('streams the errors as a CSV that neutralises spreadsheet formulas', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    $response = $this->actingAs($this->admin)->get(route('admin.imports.errors.download', $import));
    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Content-Disposition'))->toContain(sprintf('attachment; filename=import-%d-errors.csv', $import->id));
    $lines = explode("\n", trim($response->streamedContent()));
    expect($lines)->toHaveCount(8)
        ->and($lines[0])->toBe('row_number,errors,external_reference,subject,description,priority,category,customer_email,status,created_at')
        ->and($lines[1])->toStartWith('3,"The selected priority is invalid.",EXT-2,\'=SUM(A1:A2),');
});
```

7. Run the five test files and confirm they fail (`Class "App\Services\Imports\TicketCsvReader" not found`, `Route [admin.imports.store] not defined`).

8. Create `config/imports.php`. The disk needs a local path, because the jobs read with `SplFileObject`. `max_upload_kb` is in kilobytes for the `max:` rule, and the chunk size is the number of records per `ImportTicketsChunk`.

```php
return [
    'disk' => env('IMPORTS_DISK', 'local'),
    'max_upload_kb' => (int) env('IMPORTS_MAX_UPLOAD_KB', 51200),
    'chunk_size' => (int) env('IMPORTS_CHUNK_SIZE', 500),
];
```

9. Add these two methods to `app/Enums/ImportStatus.php`:

```php
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }
```

10. Add this public method to `SlaCalculator`, after `levelFor()`. It keeps the rule that only `SlaCalculator` and the scan write `escalation_level`.

```php
    public function applyCurrentLevel(Ticket $ticket, CarbonImmutable $now): void
    {
        $ticket->escalation_level = $this->levelFor($ticket, $now);
    }
```

11. Create `app/Services/Imports/TicketCsvReader.php`. It holds at most one record in memory (TD-090). With `READ_CSV`, a quoted field spanning several lines is still one record, so the indices stay stable between Prepare's counting pass and a chunk's seek. Quotes are escaped by doubling (RFC 4180), with no backslash escape.

```php
final class TicketCsvReader
{
    public const COLUMNS = ['external_reference', 'subject', 'description', 'priority', 'category', 'customer_email', 'status', 'created_at'];

    public const REQUIRED_COLUMNS = ['subject', 'description', 'priority', 'category', 'customer_email'];
    /**
     * @return list<string>
     *
     * @throws ImportHeaderInvalid
     */
    public function header(string $path): array
    {
        $raw = $this->open($path)->current();
        if (! is_array($raw) || $this->isBlank($raw)) {
            throw new ImportHeaderInvalid('The CSV file is empty or has no header row.');
        }
        $raw = array_values($raw);
        $raw[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw[0]);
        $columns = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $raw);
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));
        $unknown = array_values(array_unique(array_diff($columns, self::COLUMNS)));
        $duplicates = array_values(array_unique(array_diff_assoc($columns, array_unique($columns))));
        $problems = array_filter([
            $missing === [] ? null : 'missing required columns: '.implode(', ', $missing),
            $unknown === [] ? null : 'unknown columns: '.implode(', ', array_map(static fn (string $c): string => $c === '' ? '(blank)' : $c, $unknown)),
            $duplicates === [] ? null : 'duplicate columns: '.implode(', ', $duplicates),
        ]);
        if ($problems !== []) {
            throw new ImportHeaderInvalid('The CSV header is invalid: '.implode('; ', $problems).'.');
        }
        return $columns;
    }
    /** @return array{rows: int, last_line: int} */
    public function scan(string $path): array
    {
        $rows = 0;
        $lastLine = 0;
        foreach ($this->records($path, 1, PHP_INT_MAX) as $line => $values) {
            $rows++;
            $lastLine = $line;
        }
        return ['rows' => $rows, 'last_line' => $lastLine];
    }
    /** @return LazyCollection<int, list<string|null>> */
    public function records(string $path, int $startLine, int $endLine): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $startLine, $endLine) {
            $file = $this->open($path);
            $file->seek($startLine);
            while ($file->valid() && $file->key() <= $endLine) {
                $values = $file->current();
                if (is_array($values) && ! $this->isBlank($values)) {
                    yield $file->key() => array_values(array_map(
                        static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                        $values,
                    ));
                }
                $file->next();
            }
        });
    }

    private function open(string $path): SplFileObject
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV);
        $file->setCsvControl(',', '"', '');
        return $file;
    }
    /** @param array<int, mixed> $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }
}
```

12. Create `app/Services/Imports/ImportTicketRowRules.php`. It is built once per chunk with the active category slugs preloaded, so the category check costs no query per row. The unique rule is only a pre-check: the unique index is the real guard, and `ImportTicketRow` handles the race.

```php
final class ImportTicketRowRules
{
    /** @param list<string> $activeCategorySlugs */
    public function __construct(
        private readonly CarbonImmutable $now,
        private readonly string $timezone,
        private readonly array $activeCategorySlugs,
    ) {}
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'external_reference' => ['nullable', 'string', 'max:64', Rule::unique('tickets', 'external_reference')],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:65535'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category' => ['required', 'string', Rule::in($this->activeCategorySlugs)],
            'customer_email' => ['bail', 'required', 'string', 'email', $this->customerExists(...)],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'created_at' => ['nullable', 'string', $this->createdAtIsValid(...)],
        ];
    }

    public static function parseCreatedAt(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function customerExists(string $attribute, mixed $value, Closure $fail): void
    {
        $isCustomer = User::query()
            ->whereHas('roles', static fn ($roles) => $roles->where('name', Role::Customer->value))
            ->where('email', (string) $value)
            ->exists();
        if (! $isCustomer) {
            $fail('The customer email must belong to an existing customer.');
        }
    }

    private function createdAtIsValid(string $attribute, mixed $value, Closure $fail): void
    {
        $createdAt = self::parseCreatedAt((string) $value, $this->timezone);
        if ($createdAt === null) {
            $fail('The created at field must be a valid date.');
        } elseif ($createdAt->greaterThan($this->now)) {
            $fail('The created at field must not be in the future.');
        }
    }
}
```

13. Create `app/Actions/Imports/ImportTicketRow.php`. Each row has its own transaction (TD-053), so a bad row never rolls back its neighbours. The number's year follows the ticket's own creation date. The CSV gives an *initial* status rather than a transition, so `ChangeTicketStatus` (which would log a change that never happened) doesn't apply: the initial status's side effects are set here, and the escalation level comes from the calculator.

```php
final class ImportTicketRow
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
    ) {}
    /** @param array<string, string|null> $row */
    public function handle(TicketImport $import, array $row, int $rowNumber): ?Ticket
    {
        $now = CarbonImmutable::now();
        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, (string) config('business_hours.timezone')) ?? $now;
        $status = TicketStatus::tryFrom((string) ($row['status'] ?? '')) ?? TicketStatus::Open;
        $actor = $import->loadMissing('user')->user;
        try {
            return DB::transaction(function () use ($import, $row, $createdAt, $status, $now, $actor): Ticket {
                $ticket = new Ticket([
                    'subject' => (string) $row['subject'],
                    'description' => (string) $row['description'],
                    'priority' => TicketPriority::from((string) $row['priority']),
                    'category_id' => Category::query()->where('slug', (string) $row['category'])->firstOrFail(['id'])->id,
                    'customer_id' => User::query()->where('email', (string) $row['customer_email'])->firstOrFail(['id'])->id,
                    'created_by_id' => $import->user_id,
                    'external_reference' => $row['external_reference'] ?? null,
                ]);
                $ticket->number = $this->numbers->next($createdAt);
                $ticket->status = $status;
                $ticket->created_at = $createdAt;
                $this->sla->applyInitial($ticket, $createdAt);
                $this->applyInitialStatus($ticket, $status, $now);
                $this->sla->applyCurrentLevel($ticket, $now);
                $ticket->save();
                TicketCreated::dispatch($ticket, $actor, true);
                return $ticket;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'external_reference')) {
                throw $exception;
            }
            $error = new TicketImportError([
                'row_number' => $rowNumber,
                'errors' => ['external_reference' => ['The external reference has already been taken.']],
                'raw' => $row,
            ]);
            $import->errors()->save($error);
            return null;
        }
    }

    private function applyInitialStatus(Ticket $ticket, TicketStatus $status, CarbonImmutable $now): void
    {
        match ($status) {
            TicketStatus::WaitingForCustomer => $this->sla->pause($ticket, $now),
            TicketStatus::Resolved => $ticket->resolved_at = $now,
            TicketStatus::Closed => $ticket->resolved_at = $ticket->closed_at = $now,
            TicketStatus::Open, TicketStatus::InProgress => null,
        };
    }
}
```

14. Create `app/Actions/Imports/StartTicketImport.php`. The file is stored under a random name on the private disk, and the client's filename is kept only as metadata. Counters and `pending` come from the model's defaults. If the row can't be saved, the stored file is deleted.

```php
final class StartTicketImport
{
    public function handle(User $admin, UploadedFile $file): TicketImport
    {
        $disk = (string) config('imports.disk');
        $path = $file->store('imports', $disk);
        if ($path === false) {
            throw new RuntimeException('The import file could not be stored.');
        }
        $import = new TicketImport(['original_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'path' => $path]);
        $import->user_id = $admin->id;
        try {
            $import->save();
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
        PrepareTicketImport::dispatch($import->id);
        return $import->refresh();
    }
}
```

15. Create `app/Jobs/PrepareTicketImport.php`. Only a pending import is prepared, so a retry after the batch was dispatched does nothing. `batch_id` is also stamped in `finally`, because on the sync driver the batch finishes inside `dispatch()`. `ticket_imports` has no message column, so the reason for a failure is stored as error row 1.

```php
final class PrepareTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(TicketCsvReader $reader): void
    {
        $import = TicketImport::query()->find($this->importId);
        if ($import === null || $import->status !== ImportStatus::Pending) {
            return;
        }
        $path = Storage::disk((string) config('imports.disk'))->path($import->path);
        try {
            $reader->header($path);
        } catch (ImportHeaderInvalid $exception) {
            $this->markFailed($import, ['header' => [$exception->getMessage()]]);
            return;
        }
        ['rows' => $rows, 'last_line' => $lastLine] = $reader->scan($path);
        $import->status = ImportStatus::Processing;
        $import->total_rows = $rows;
        $import->started_at = CarbonImmutable::now();
        $import->save();
        if ($rows === 0) {
            FinalizeTicketImport::dispatch($import->id);
            return;
        }
        $importId = $import->id;
        $stampBatch = static fn (Batch $batch): int => TicketImport::query()->whereKey($importId)->whereNull('batch_id')->update(['batch_id' => $batch->id]);
        $batch = Bus::batch($this->chunks($importId, $lastLine))
            ->name('ticket-import-'.$importId)
            ->onQueue('imports')
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($importId, $stampBatch): void {
                $stampBatch($batch);
                FinalizeTicketImport::dispatch($importId);
            })
            ->dispatch();
        $stampBatch($batch);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Preparing ticket import failed.', ['import_id' => $this->importId, 'exception' => $exception]);
        $import = TicketImport::query()->find($this->importId);
        if ($import !== null && ! $import->status->isFinished()) {
            $this->markFailed($import, ['import' => ['The import could not be prepared. See the application log.']]);
        }
    }
    /** @return list<ImportTicketsChunk> */
    private function chunks(int $importId, int $lastLine): array
    {
        $size = max(1, (int) config('imports.chunk_size'));
        $jobs = [];
        for ($start = 1; $start <= $lastLine; $start += $size) {
            $jobs[] = new ImportTicketsChunk($importId, $start, min($start + $size - 1, $lastLine));
        }
        return $jobs;
    }
    /** @param array<string, list<string>> $errors */
    private function markFailed(TicketImport $import, array $errors): void
    {
        DB::transaction(function () use ($import, $errors): void {
            $import->errors()->save(new TicketImportError(['row_number' => 1, 'errors' => $errors, 'raw' => []]));
            $import->status = ImportStatus::Failed;
            $import->finished_at = CarbonImmutable::now();
            $import->save();
            TicketImportFinished::dispatch($import);
        });
    }
}
```

16. Create `app/Jobs/ImportTicketsChunk.php`. It has one attempt, because a retry would re-import committed rows that have no reference (TD-072 amendment). Per-row failures are caught, so the job fails only on infrastructure errors. Values are trimmed and the enum, slug and email columns lower-cased before validation. Counters use an atomic `incrementEach`, so parallel chunks never lose an update. A null ticket is a duplicate whose error row `ImportTicketRow` has already written.

```php
final class ImportTicketsChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    private const LOWERCASE = ['priority', 'status', 'category', 'customer_email'];

    public int $tries = 1;

    public function __construct(
        public int $importId,
        public int $startLine,
        public int $endLine,
    ) {
        $this->onQueue('imports');
    }

    public function handle(TicketCsvReader $reader, ImportTicketRow $importRow): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }
        $import = TicketImport::query()->with('user')->find($this->importId);
        if ($import === null) {
            return;
        }
        $path = Storage::disk((string) config('imports.disk'))->path($import->path);
        $header = $reader->header($path);
        $rules = new ImportTicketRowRules(
            CarbonImmutable::now(),
            (string) config('business_hours.timezone'),
            Category::query()->active()->pluck('slug')->all(),
        );
        foreach ($reader->records($path, $this->startLine, $this->endLine) as $line => $values) {
            $this->importRecord($import, $importRow, $rules, $header, $values, $line + 1);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Ticket import chunk failed.', [
            'import_id' => $this->importId,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'exception' => $exception,
        ]);
        $error = new TicketImportError([
            'row_number' => $this->startLine + 1,
            'errors' => ['chunk' => [sprintf('Rows %d to %d stopped unexpectedly and may be partly imported. See the application log.', $this->startLine + 1, $this->endLine + 1)]],
            'raw' => [],
        ]);
        $error->ticket_import_id = $this->importId;
        $error->save();
    }
    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $values
     */
    private function importRecord(TicketImport $import, ImportTicketRow $importRow, ImportTicketRowRules $rules, array $header, array $values, int $rowNumber): void
    {
        if (count($values) !== count($header)) {
            $this->recordFailure($import, $rowNumber, ['row' => [sprintf('The row has %d columns but the header has %d.', count($values), count($header))]], $values);
            return;
        }
        $row = $this->normalise(array_combine($header, $values));
        $validator = Validator::make($row, $rules->rules());
        if ($validator->fails()) {
            $this->recordFailure($import, $rowNumber, $validator->errors()->toArray(), $row);
            return;
        }
        try {
            /** @var array<string, string|null> $validated */
            $validated = $validator->validated();
            $ticket = $importRow->handle($import, $validated, $rowNumber);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($import, $rowNumber, ['row' => ['The row could not be imported because of an unexpected error.']], $row);
            return;
        }
        $this->increment($import, $ticket === null ? 'failed_rows' : 'succeeded_rows');
    }
    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private function normalise(array $row): array
    {
        foreach ($row as $column => $value) {
            $value = $value === null || trim($value) === '' ? null : trim($value);
            $row[$column] = $value !== null && in_array($column, self::LOWERCASE, true) ? strtolower($value) : $value;
        }
        return $row;
    }
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<int|string, string|null>  $raw
     */
    private function recordFailure(TicketImport $import, int $rowNumber, array $errors, array $raw): void
    {
        DB::transaction(function () use ($import, $rowNumber, $errors, $raw): void {
            $import->errors()->save(new TicketImportError(['row_number' => $rowNumber, 'errors' => $errors, 'raw' => $raw]));
            $this->increment($import, 'failed_rows');
        });
    }

    private function increment(TicketImport $import, string $outcomeColumn): void
    {
        TicketImport::query()->whereKey($import->id)->toBase()->incrementEach(['processed_rows' => 1, $outcomeColumn => 1]);
    }
}
```

17. Create `app/Jobs/FinalizeTicketImport.php`. The status update is conditional on `processing`, so a duplicate Finalize changes nothing and fires nothing.

```php
final class FinalizeTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $import = TicketImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }
        $batch = $import->batch_id !== null ? Bus::findBatch($import->batch_id) : null;
        $status = match (true) {
            $batch?->hasFailures() === true => ImportStatus::Failed,
            $import->failed_rows > 0 => ImportStatus::CompletedWithErrors,
            default => ImportStatus::Completed,
        };
        $updated = TicketImport::query()
            ->whereKey($import->id)
            ->where('status', ImportStatus::Processing->value)
            ->update(['status' => $status->value, 'finished_at' => CarbonImmutable::now()]);
        if ($updated === 1) {
            TicketImportFinished::dispatch($import->refresh());
        }
    }
}
```

18. Create `app/Services/Imports/TicketImportQuery.php`, the read side that keeps controllers free of queries. The error list is served by `(ticket_import_id, row_number)`, and the download reads 1,000 rows at a time.

```php
final class TicketImportQuery
{
    /** @return LengthAwarePaginator<int, TicketImport> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return TicketImport::query()->with('user:id,name')->latest('id')->paginate($perPage);
    }
    /** @return LengthAwarePaginator<int, TicketImportError> */
    public function errors(TicketImport $import, int $perPage): LengthAwarePaginator
    {
        return $import->errors()->orderBy('row_number')->orderBy('id')->paginate($perPage)->withQueryString();
    }
    /** @return LazyCollection<int, TicketImportError> */
    public function allErrors(TicketImport $import): LazyCollection
    {
        return $import->errors()->orderBy('row_number')->orderBy('id')->lazy(1000);
    }
}
```

19. Create `app/Services/Imports/ImportErrorCsvWriter.php`. It guards against CSV injection: a spreadsheet must never run an uploaded value as a formula (TD-120).

```php
final class ImportErrorCsvWriter
{
    public function __construct(private readonly TicketImportQuery $imports) {}
    /** @param resource $stream */
    public function write(TicketImport $import, $stream): void
    {
        fputcsv($stream, ['row_number', 'errors', ...TicketCsvReader::COLUMNS], ',', '"', '');
        foreach ($this->imports->allErrors($import) as $error) {
            $line = [(string) $error->row_number, implode(' | ', Arr::flatten($error->errors))];
            foreach (TicketCsvReader::COLUMNS as $column) {
                $line[] = (string) ($error->raw[$column] ?? '');
            }
            fputcsv($stream, array_map(self::cell(...), $line), ',', '"', '');
        }
    }

    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
```

20. Create the requests. `StoreTicketImportRequest`: `authorize()` returns true (the route's `can:create` runs first); its rules are `'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('imports.max_upload_kb')]`; and `csv(): UploadedFile` returns `$this->file('file')`, throwing `LogicException` if that isn't an `UploadedFile`. `IndexImportErrorsRequest`: `authorize()` returns true; rules `per_page` (`nullable|integer|min:1|max:100`) and `page` (`nullable|integer|min:1`); and `perPage(): int` returns `$this->integer('per_page', 25)`.

21. Create the resources:

```php
/** @mixin TicketImport */
final class TicketImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $finished = $this->status->isFinished();
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'is_finished' => $finished,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'succeeded_rows' => $this->succeeded_rows,
            'failed_rows' => $this->failed_rows,
            'progress_percent' => $this->total_rows === 0
                ? ($finished ? 100 : 0)
                : min(100, intdiv($this->processed_rows * 100, $this->total_rows)),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
        ];
    }
}
```

`TicketImportErrorResource` (`@mixin TicketImportError`) returns `id`, `row_number`, `errors`, `raw` and `created_at` (`$this->created_at?->toIso8601String()`).

22. Create `app/Http/Controllers/Web/Admin/TicketImportController.php`:

```php
final class TicketImportController extends Controller
{
    public function __construct(private readonly TicketImportQuery $imports) {}

    public function index(): Response
    {
        return Inertia::render('admin/imports/Index', [
            'imports' => TicketImportResource::collection($this->imports->paginate(20)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/imports/Create', [
            'maxUploadKb' => (int) config('imports.max_upload_kb'),
            'columns' => [
                'required' => TicketCsvReader::REQUIRED_COLUMNS,
                'optional' => array_values(array_diff(TicketCsvReader::COLUMNS, TicketCsvReader::REQUIRED_COLUMNS)),
            ],
        ]);
    }

    public function store(StoreTicketImportRequest $request, StartTicketImport $start): RedirectResponse
    {
        $admin = $request->user();
        assert($admin instanceof User);
        $import = $start->handle($admin, $request->csv());
        return to_route('admin.imports.show', $import)->with('success', 'Import started. Progress updates on this page.');
    }

    public function show(Request $request, TicketImport $import): Response
    {
        return Inertia::render('admin/imports/Show', [
            'ticketImport' => fn (): array => TicketImportResource::make($import->load('user:id,name'))->resolve($request),
            'importErrors' => fn (): AnonymousResourceCollection => TicketImportErrorResource::collection($this->imports->errors($import, 25)),
        ]);
    }

    public function downloadErrors(TicketImport $import, ImportErrorCsvWriter $writer): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($import, $writer): void {
                $stream = fopen('php://output', 'wb');
                $writer->write($import, $stream);
                fclose($stream);
            },
            sprintf('import-%d-errors.csv', $import->id),
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
```

23. Create `app/Http/Controllers/Api/V1/TicketImportController.php`:
- `store(StoreTicketImportRequest $request, StartTicketImport $start): JsonResponse` returns `TicketImportResource::make($import->load('user:id,name'))->response()->setStatusCode(202)`. It is 202 because the import continues in the background and clients poll `show`.
- `show(TicketImport $import): TicketImportResource` returns the import with `user:id,name` loaded.
- `errors(IndexImportErrorsRequest $request, TicketImport $import, TicketImportQuery $imports): AnonymousResourceCollection` returns `TicketImportErrorResource::collection($imports->errors($import, $request->perPage()))`.

24. Create the routes:

```php
Route::middleware('auth')->prefix('admin/imports')->name('admin.imports.')->group(function (): void {
    Route::get('/', [TicketImportController::class, 'index'])->name('index')->can('viewAny', TicketImport::class);
    Route::get('create', [TicketImportController::class, 'create'])->name('create')->can('create', TicketImport::class);
    Route::post('/', [TicketImportController::class, 'store'])->name('store')->middleware('throttle:uploads')->can('create', TicketImport::class);
    Route::get('{import}', [TicketImportController::class, 'show'])->name('show')->can('view', 'import');
    Route::get('{import}/errors/download', [TicketImportController::class, 'downloadErrors'])->name('errors.download')->can('view', 'import');
});
```

```php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('imports', [TicketImportController::class, 'store'])->name('imports.store')->middleware('throttle:uploads')->can('create', TicketImport::class);
    Route::get('imports/{import}', [TicketImportController::class, 'show'])->name('imports.show')->can('view', 'import');
    Route::get('imports/{import}/errors', [TicketImportController::class, 'errors'])->name('imports.errors.index')->can('view', 'import');
});
```

25. Run: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Unit/Imports tests/Feature/Imports tests/Unit/Sla/SlaCalculatorTest.php`. All pass. If the memory test fails, the cause is a read that doesn't stream, not the bound: find it before touching the 2 MB limit.

**Proposed commit:** `feat(import): import tickets from CSV in streamed, batched chunks` · `Refs: P4.3` · `Implements: TD-090, TD-072, TD-053, TD-101, TD-120, TD-130`

---

## P4.4 — Categories and holidays

**Files:**
- create `app/Actions/Admin/CreateCategory.php`, `UpdateCategory.php`, `DeleteCategory.php`, `CreateHoliday.php`, `UpdateHoliday.php`, `DeleteHoliday.php` (all in `app/Actions/Admin/`)
- create `app/Services/Admin/AdminCatalog.php`
- create `app/Http/Requests/Admin/StoreCategoryRequest.php`, `UpdateCategoryRequest.php`, `StoreHolidayRequest.php`, `UpdateHolidayRequest.php`, `IndexHolidaysRequest.php` (all in `app/Http/Requests/Admin/`)
- create `app/Http/Resources/V1/AdminCategoryResource.php`, `app/Http/Resources/V1/HolidayResource.php`
- create `app/Http/Controllers/Web/Admin/CategoryController.php`, `app/Http/Controllers/Web/Admin/HolidayController.php`, `app/Http/Controllers/Api/V1/Admin/CategoryController.php`, `app/Http/Controllers/Api/V1/Admin/HolidayController.php`, `app/Http/Controllers/Api/V1/CategoryController.php`
- create `routes/web/categories.php`, `routes/web/holidays.php`, `routes/api/v1/categories.php`, `routes/api/v1/holidays.php`
- create `tests/Feature/Admin/CategoryManagementTest.php`, `tests/Feature/Admin/HolidayManagementTest.php`

**Consumes:** `CategoryPolicy`, `HolidayPolicy`, `TicketPolicy::create`, `HolidayRepository::flush()` (cache key `sla.holidays`), `CategoryResource`, `SlaCalculator::deadlinesFor` (test only).

**Produces:**
- Actions: `Admin\CreateCategory::handle(array $data): Category`, `Admin\UpdateCategory::handle(Category, array): Category`, `Admin\DeleteCategory::handle(Category): bool` (true = deleted, false = deactivated because tickets reference it), `Admin\CreateHoliday::handle(array): Holiday`, `Admin\UpdateHoliday::handle(Holiday, array): Holiday`, `Admin\DeleteHoliday::handle(Holiday): void`. Every holiday Action flushes the cached holiday list after writing.
- Web routes (`auth`, prefix `admin`): `admin.categories.index|create|store|edit|update|destroy` (`GET categories`, `GET categories/create`, `POST categories`, `GET categories/{category}/edit`, `PATCH categories/{category}`, `DELETE categories/{category}`), and `admin.holidays.*` with the same six shapes.
- API routes (`auth:sanctum`): `api.v1.categories.index` `GET /api/v1/categories` (active categories, for anyone who may create a ticket); `api.v1.admin.categories.index|store|update|destroy` and `api.v1.admin.holidays.index|store|update|destroy` under `/api/v1/admin`. API `destroy` returns 204 when deleted; a category still referenced by tickets is deactivated instead and returned with 200.
- `AdminCategoryResource`: `id, name, slug, is_active, tickets_count`. `HolidayResource`: `id, date` (`Y-m-d`), `weekday` (`Monday`…), `name`.
- Inertia: `admin/categories/Index` (`categories`: paginated `AdminCategoryResource`, 20 per page, by name), `admin/categories/Create` (no props), `admin/categories/Edit` (`category`: `AdminCategoryResource`), `admin/holidays/Index` (`holidays`: paginated `HolidayResource`, 50 per page, by date; `year: int`), `admin/holidays/Create` (no props), `admin/holidays/Edit` (`holiday`: `HolidayResource`). Web writes redirect to the index with a `success` flash; holiday writes redirect to `admin.holidays.index` for the holiday's year.

**Steps:**

1. Write `tests/Feature/Admin/CategoryManagementTest.php`:

```php
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

it('deletes an unused category and deactivates one that tickets reference', function (bool $hasTickets, bool $deleted): void {
    $category = Category::factory()->create(['is_active' => true]);
    if ($hasTickets) {
        Ticket::factory()->create(['category_id' => $category->id]);
    }
    expect(app(DeleteCategory::class)->handle($category))->toBe($deleted)
        ->and(Category::query()->whereKey($category->id)->value('is_active'))->toBe($deleted ? null : false);
})->with([
    'unused' => [false, true],
    'referenced' => [true, false],
]);

it('lists categories with ticket counts and renders the edit props', function (): void {
    $used = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    Category::factory()->create(['name' => 'Access', 'slug' => 'access']);
    Ticket::factory()->count(2)->create(['category_id' => $used->id]);
    $this->actingAs($this->admin)->get(route('admin.categories.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/categories/Index', false)
            ->where('categories.data.0.name', 'Access')
            ->where('categories.data.0.tickets_count', 0)
            ->where('categories.data.1.tickets_count', 2));
    $this->actingAs($this->admin)->get(route('admin.categories.edit', $used))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/categories/Edit', false)
            ->where('category.slug', 'billing'));
});

it('creates a category with a normalised slug', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.categories.store'), ['name' => 'Billing', 'slug' => ' Billing '])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');
    expect(Category::query()->where('slug', 'billing')->exists())->toBeTrue();
});

it('rejects a duplicate or malformed slug', function (string $slug): void {
    Category::factory()->create(['slug' => 'taken']);
    $this->actingAs($this->admin)
        ->post(route('admin.categories.store'), ['name' => 'X', 'slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with(['duplicate' => 'taken', 'spaces' => 'two words', 'trailing dash' => 'bad-']);

it('updates a category and lets it keep its own slug', function (): void {
    $category = Category::factory()->create(['slug' => 'billing']);
    $this->actingAs($this->admin)
        ->patch(route('admin.categories.update', $category), ['name' => 'Billing & invoices', 'slug' => 'billing', 'is_active' => false])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHasNoErrors();
    expect($category->fresh()?->name)->toBe('Billing & invoices')
        ->and($category->fresh()?->is_active)->toBeFalse();
});

it('tells the admin whether a category was deleted or deactivated', function (): void {
    $unused = Category::factory()->create();
    $used = Category::factory()->create();
    Ticket::factory()->create(['category_id' => $used->id]);
    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $unused))
        ->assertSessionHas('success', 'Category deleted.');
    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $used))
        ->assertSessionHas('success', 'Category has tickets, so it was deactivated instead of deleted.');
});

it('manages categories over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $id = $this->postJson(route('api.v1.admin.categories.store'), ['name' => 'Billing', 'slug' => 'billing'])
        ->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');
    $this->patchJson(route('api.v1.admin.categories.update', $id), ['name' => 'Billing 2', 'slug' => 'billing-2'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'billing-2');
    $this->getJson(route('api.v1.admin.categories.index'))->assertOk()->assertJsonPath('meta.total', 1);
    $this->deleteJson(route('api.v1.admin.categories.destroy', $id))->assertNoContent();
    $used = Category::factory()->create();
    Ticket::factory()->create(['category_id' => $used->id]);
    $this->deleteJson(route('api.v1.admin.categories.destroy', $used))->assertOk()->assertJsonPath('data.is_active', false);
});

it('lists only active categories on the public endpoint', function (): void {
    Category::factory()->create(['slug' => 'billing', 'is_active' => true]);
    Category::factory()->create(['slug' => 'legacy', 'is_active' => false]);
    Sanctum::actingAs(User::factory()->customer()->create(), ['*']);
    $this->getJson(route('api.v1.categories.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'billing');
});
```

2. Write `tests/Feature/Admin/HolidayManagementTest.php`. The SLA test warms the holiday cache as a running app would, then rebuilds the calendar to simulate the next request. Thursday 17:00 Dhaka is normally due Sunday 10:00 Dhaka; with 2026-11-01 a holiday, it moves to Monday 10:00.

```php
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

it('flushes the cached holiday list after every change', function (Closure $change): void {
    $holiday = Holiday::factory()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);
    app(HolidayRepository::class)->dates();
    $change($holiday);
    expect(Cache::has('sla.holidays'))->toBeFalse();
})->with([
    'create' => [fn () => fn (Holiday $h) => app(CreateHoliday::class)->handle(['date' => '2026-02-21', 'name' => 'Language Day'])],
    'update' => [fn () => fn (Holiday $h) => app(UpdateHoliday::class)->handle($h, ['date' => '2026-12-17', 'name' => 'Victory Day'])],
    'delete' => [fn () => fn (Holiday $h) => app(DeleteHoliday::class)->handle($h)],
]);

it('makes new SLA deadlines skip a holiday added through the admin screen', function (): void {
    $start = CarbonImmutable::parse('2026-10-29 17:00', 'Asia/Dhaka');
    app(HolidayRepository::class)->dates();
    expect(app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start)->dueAt->toDateTimeString())->toBe('2026-11-01 04:00:00');
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-11-01', 'name' => 'Office closed'])
        ->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    app()->forgetInstance(SlaCalculator::class);
    app()->forgetInstance(BusinessCalendar::class);
    expect(app(SlaCalculator::class)->deadlinesFor(TicketPriority::Critical, $start)->dueAt->toDateTimeString())->toBe('2026-11-02 04:00:00');
});

it('lists the holidays of the requested year, defaulting to the current one', function (): void {
    Holiday::factory()->create(['date' => '2026-12-16']);
    Holiday::factory()->create(['date' => '2026-02-21']);
    Holiday::factory()->create(['date' => '2027-02-21']);
    $this->actingAs($this->admin)->get(route('admin.holidays.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/holidays/Index', false)
            ->where('year', 2026)
            ->has('holidays.data', 2)
            ->where('holidays.data.0.date', '2026-02-21')
            ->where('holidays.data.0.weekday', 'Saturday'));
    $this->actingAs($this->admin)->get(route('admin.holidays.index', ['year' => 2027]))
        ->assertInertia(fn (Assert $page) => $page->where('year', 2027)->has('holidays.data', 1));
});

it('creates, updates and deletes a holiday, returning to its year', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]))
        ->assertSessionHas('success');
    $holiday = Holiday::query()->sole();
    $this->actingAs($this->admin)
        ->patch(route('admin.holidays.update', $holiday), ['date' => '2026-12-16', 'name' => 'Bijoy Dibos'])
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete(route('admin.holidays.destroy', $holiday))
        ->assertRedirect(route('admin.holidays.index', ['year' => 2026]));
    expect(Holiday::query()->count())->toBe(0);
});

it('rejects a duplicate or malformed date', function (string $date): void {
    Holiday::factory()->create(['date' => '2026-12-16']);
    $this->actingAs($this->admin)
        ->post(route('admin.holidays.store'), ['date' => $date, 'name' => 'Something'])
        ->assertSessionHasErrors('date');
})->with(['duplicate' => '2026-12-16', 'not Y-m-d' => '16/12/2026']);

it('manages holidays over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $id = $this->postJson(route('api.v1.admin.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertCreated()
        ->assertJsonPath('data.weekday', 'Wednesday')
        ->json('data.id');
    $this->getJson(route('api.v1.admin.holidays.index', ['year' => 2026]))->assertOk()->assertJsonPath('data.0.id', $id);
    $this->patchJson(route('api.v1.admin.holidays.update', $id), ['date' => '2026-12-17', 'name' => 'Victory Day'])
        ->assertOk()
        ->assertJsonPath('data.date', '2026-12-17');
    $this->deleteJson(route('api.v1.admin.holidays.destroy', $id))->assertNoContent();
});
```

3. Run both files and confirm they fail (`Class "App\Actions\Admin\DeleteCategory" not found`).

4. Create the category Actions. `CreateCategory::handle(array $data): Category` creates a `new Category` filled with `name`, `slug` and `is_active` (default true), and saves it. `UpdateCategory::handle(Category $category, array $data): Category` fills the same keys, keeping the current `is_active` when it is absent. Both use `/** @param array{name: string, slug: string, is_active?: bool} $data */`. `DeleteCategory` locks the category row, so a concurrent ticket insert's foreign-key check on it waits until the Action decides. Tickets keep their category (FK restrict, TD-025).

```php
final class DeleteCategory
{
    public function handle(Category $category): bool
    {
        return DB::transaction(function () use ($category): bool {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($locked->tickets()->exists()) {
                $locked->is_active = false;
                $locked->save();
                $category->is_active = false;
                $category->syncOriginal();
                return false;
            }
            $locked->delete();
            return true;
        });
    }
}
```

5. Create the holiday Actions. Each one is a single write followed by a flush, so that the next `BusinessCalendar` build reads the new list (TD-045). For example:

```php
final class CreateHoliday
{
    public function __construct(private readonly HolidayRepository $holidays) {}
    /** @param array{date: string, name: string} $data */
    public function handle(array $data): Holiday
    {
        $holiday = Holiday::query()->create(['date' => $data['date'], 'name' => $data['name']]);
        $this->holidays->flush();
        return $holiday->refresh();
    }
}
```

`UpdateHoliday::handle(Holiday $holiday, array $data): Holiday` runs `$holiday->update([...])`, flushes, and returns `$holiday->refresh()`. `DeleteHoliday::handle(Holiday $holiday): void` runs `$holiday->delete()`, then flushes.

6. Create `app/Services/Admin/AdminCatalog.php`, which holds the read queries so controllers stay query-free:

```php
final class AdminCatalog
{
    /** @return LengthAwarePaginator<int, Category> */
    public function categories(int $perPage = 20): LengthAwarePaginator
    {
        return Category::query()->withCount('tickets')->orderBy('name')->orderBy('id')->paginate($perPage);
    }
    /** @return Collection<int, Category> */
    public function activeCategories(): Collection
    {
        return Category::query()->active()->orderBy('name')->limit(500)->get(['id', 'name', 'slug', 'is_active']);
    }
    /** @return LengthAwarePaginator<int, Holiday> */
    public function holidays(int $year, int $perPage = 50): LengthAwarePaginator
    {
        return Holiday::query()
            ->whereBetween('date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->orderBy('date')
            ->paginate($perPage)
            ->withQueryString();
    }
}
```

7. Create the requests. Every one returns `true` from `authorize()` (the route's `can:` runs first).
   - `StoreCategoryRequest` is not `final`, so the update request can extend it. Its `prepareForValidation()` merges `slug` as `strtolower(trim(...))` when it is a string. Its rules are `name` (`required|string|max:100`), `slug` (`required|string|max:100`, `regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/`, `$this->uniqueSlug()`) and `is_active` (`sometimes|boolean`). `protected function uniqueSlug(): Unique` returns `Rule::unique('categories', 'slug')`.
   - `UpdateCategoryRequest` (final, extends it) overrides `uniqueSlug()` with `->ignore($this->route('category'))`.
   - `StoreHolidayRequest` (not final) has rules `date` (`required|date_format:Y-m-d`, `$this->uniqueDate()`) and `name` (`required|string|max:100`). `uniqueDate()` returns `Rule::unique('holidays', 'date')`.
   - `UpdateHolidayRequest` (final) overrides `uniqueDate()` with `->ignore($this->route('holiday'))`.
   - `IndexHolidaysRequest` has the rule `year` (`nullable|integer|between:2000,2100`). Its `year(): int` returns `$this->integer('year')` when filled, otherwise `CarbonImmutable::now((string) config('business_hours.timezone'))->year`.

8. Create the resources. `AdminCategoryResource` returns `id`, `name`, `slug`, `(bool) is_active` and `'tickets_count' => $this->whenCounted('tickets', fn (): int => (int) $this->tickets_count, 0)`. `HolidayResource` returns `id`, `'date' => $this->date->format('Y-m-d')`, `'weekday' => $this->date->format('l')` and `name`.

9. Create the web controllers. `CategoryController`:

```php
final class CategoryController extends Controller
{
    public function index(AdminCatalog $catalog): Response
    {
        return Inertia::render('admin/categories/Index', ['categories' => AdminCategoryResource::collection($catalog->categories())]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/categories/Create');
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create): RedirectResponse
    {
        $create->handle($request->validated());
        return to_route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Request $request, Category $category): Response
    {
        return Inertia::render('admin/categories/Edit', [
            'category' => AdminCategoryResource::make($category->loadCount('tickets'))->resolve($request),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $update): RedirectResponse
    {
        $update->handle($category, $request->validated());
        return to_route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category, DeleteCategory $delete): RedirectResponse
    {
        return to_route('admin.categories.index')->with('success', $delete->handle($category)
            ? 'Category deleted.'
            : 'Category has tickets, so it was deactivated instead of deleted.');
    }
}
```

`HolidayController` follows the same shape:
- `index(IndexHolidaysRequest $request, AdminCatalog $catalog)` renders `admin/holidays/Index` with `year` and `holidays`.
- `create()` renders `admin/holidays/Create`.
- `edit(Request, Holiday)` renders `admin/holidays/Edit` with `holiday` (resolved resource).
- `store`, `update` and `destroy` call their Action and return `to_route('admin.holidays.index', ['year' => (int) $holiday->date->format('Y')])` with the flash `Holiday added. New SLA deadlines will skip it.`, `Holiday updated.` or `Holiday deleted.`.

10. Create the API controllers:
- `Api\V1\Admin\CategoryController`:
  - `index(AdminCatalog)` returns `AdminCategoryResource::collection($catalog->categories())`.
  - `store` returns the created category (`->loadCount('tickets')`) with 201.
  - `update` returns the resource.
  - `destroy(Category $category, DeleteCategory $delete): Response|AdminCategoryResource` returns `response()->noContent()` when deleted, and otherwise `AdminCategoryResource::make($category->loadCount('tickets'))`.
- `Api\V1\Admin\HolidayController`: `index(IndexHolidaysRequest, AdminCatalog)` returns `HolidayResource::collection($catalog->holidays($request->year()))`; `store` returns 201; `update` returns the resource; `destroy` returns 204.
- `Api\V1\CategoryController::index(AdminCatalog $catalog): AnonymousResourceCollection` returns `CategoryResource::collection($catalog->activeCategories())`.

11. Create the routes. `routes/web/categories.php`:

```php
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('viewAny', Category::class);
    Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create')->can('create', Category::class);
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store')->can('create', Category::class);
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit')->can('update', 'category');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update')->can('update', 'category');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->can('delete', 'category');
});
```

`routes/web/holidays.php` is the same with `HolidayController`, `Holiday::class`, `holidays` paths and `{holiday}`. `routes/api/v1/categories.php`:

```php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('create', Ticket::class);
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index')->can('viewAny', Category::class);
        Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store')->can('create', Category::class);
        Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update')->can('update', 'category');
        Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy')->can('delete', 'category');
    });
});
```

`routes/api/v1/holidays.php` contains only the inner `admin` group, with the four holiday routes, inside `auth:sanctum`.

12. Run: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Admin/CategoryManagementTest.php tests/Feature/Admin/HolidayManagementTest.php`. All pass.

**Proposed commit:** `feat(admin): manage categories and holidays with SLA cache invalidation` · `Refs: P4.4` · `Implements: TD-011, TD-025, TD-045, TD-101`

---

## P4.5 — Dashboard statistics

**Files:**
- create `app/Services/Dashboard/DashboardStats.php`
- create `app/Http/Resources/V1/DashboardResource.php`
- create `app/Http/Controllers/Web/DashboardController.php`, `app/Http/Controllers/Api/V1/DashboardController.php`
- modify `routes/web/dashboard.php`; create `routes/api/v1/dashboard.php`
- modify `tests/Feature/App/InertiaSharedPropsTest.php`
- create `tests/Feature/Dashboard/DashboardStatsTest.php`, `tests/Feature/Dashboard/DashboardEndpointsTest.php`

**Consumes:** `Gate 'viewDashboard'`, `SlaQuery::whereBreached/whereApproaching`, `TicketStatus::active()/finished()`, `TicketListResource` (safe without relations; it uses `whenLoaded`), and P1's `tickets(status, resolved_at)` index.

**Produces:**
- `DashboardStats::summary(): array{totals: array{open:int, unassigned:int, critical:int, breached:int, approaching:int, resolved:int}, by_priority: list<array{priority:string, count:int}>, by_agent: list<array{agent_id:int, name:string, active:int, breached:int}>}`. It runs three queries and is cached as `dashboard:staff` for 60 seconds. The dashboard is staff-only, and all staff see the same figures, so there is one scope. This replaces README §3.4's `for(User $viewer)`, whose viewer argument would go unused.
- `DashboardStats::myTickets(User $viewer, int $limit = 5): Collection<int, Ticket>` returns the viewer's active assigned tickets, soonest deadline first. It is uncached.
- `totals.open`, `unassigned`, `critical`, `breached` and `approaching` count active tickets with `SlaQuery`'s semantics. `resolved` counts Resolved or Closed tickets with `resolved_at` in the last 30 days. `by_priority` always lists all four priorities, critical first. `by_agent` lists every agent, idle ones included, by active count descending, then name.
- Routes: web `dashboard` `GET /dashboard` and API `api.v1.dashboard` `GET /api/v1/dashboard`, both `can:viewDashboard`.
- API body: `data = {totals, by_priority, by_agent, my_tickets: TicketListResource[]}`. Inertia `dashboard/Index` has the props `stats` (the `summary()` array) and `myTickets` (`TicketListResource[]`). Both are closures, so a poll can reload them alone.

**Steps:**

1. Write `tests/Feature/Dashboard/DashboardStatsTest.php`. The fixture set:
   - t1: open, unassigned, low, within SLA
   - t2: in progress, Alpha, critical, breached
   - t3: waiting, Alpha, high, past due but paused
   - t4: open, Bravo, medium, approaching
   - t5: open, Bravo, critical, within SLA
   - t6: resolved 2 days ago
   - t7: closed, resolved 40 days ago
   - t8: closed, resolved yesterday

```php
beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $now = CarbonImmutable::now();
    $category = Category::factory()->create();
    $customer = User::factory()->customer()->create();
    $this->alpha = User::factory()->agent([$category->id])->create(['name' => 'Agent Alpha']);
    $this->bravo = User::factory()->agent([$category->id])->create(['name' => 'Agent Bravo']);
    $this->charlie = User::factory()->agent([$category->id])->create(['name' => 'Agent Charlie']);
    $make = fn (TicketStatus $status, TicketPriority $priority, ?User $agent, CarbonImmutable $warning, CarbonImmutable $due, array $extra = []): Ticket => Ticket::factory()->create([
        'category_id' => $category->id,
        'customer_id' => $customer->id,
        'status' => $status,
        'priority' => $priority,
        'assigned_agent_id' => $agent?->id,
        'sla_warning_at' => $warning,
        'sla_due_at' => $due,
        'sla_paused_at' => null,
        'resolved_at' => null,
        'closed_at' => null,
        ...$extra,
    ]);
    $make(TicketStatus::Open, TicketPriority::Low, null, $now->addHours(2), $now->addHours(4));
    $make(TicketStatus::InProgress, TicketPriority::Critical, $this->alpha, $now->subHours(2), $now->subHour());
    $make(TicketStatus::WaitingForCustomer, TicketPriority::High, $this->alpha, $now->subHours(3), $now->subHour(), ['sla_paused_at' => $now->subHours(5)]);
    $make(TicketStatus::Open, TicketPriority::Medium, $this->bravo, $now->subMinutes(10), $now->addHour());
    $make(TicketStatus::Open, TicketPriority::Critical, $this->bravo, $now->addHour(), $now->addHours(2));
    $make(TicketStatus::Resolved, TicketPriority::Critical, $this->alpha, $now->subDays(4), $now->subDays(3), ['resolved_at' => $now->subDays(2)]);
    $make(TicketStatus::Closed, TicketPriority::Low, $this->bravo, $now->subDays(45), $now->subDays(44), ['resolved_at' => $now->subDays(40), 'closed_at' => $now->subDays(39)]);
    $make(TicketStatus::Closed, TicketPriority::High, $this->alpha, $now->subDays(3), $now->subDays(2), ['resolved_at' => $now->subDay(), 'closed_at' => $now->subDay()]);
});

it('computes the totals with the same SLA semantics as SlaQuery', function (): void {
    $totals = app(DashboardStats::class)->summary()['totals'];
    expect($totals)->toBe(['open' => 5, 'unassigned' => 1, 'critical' => 2, 'breached' => 1, 'approaching' => 1, 'resolved' => 2])
        ->and($totals['breached'])->toBe(SlaQuery::whereBreached(Ticket::query(), CarbonImmutable::now())->count())
        ->and($totals['approaching'])->toBe(SlaQuery::whereApproaching(Ticket::query(), CarbonImmutable::now())->count());
});

it('lists every priority, critical first, counting active tickets only', function (): void {
    expect(app(DashboardStats::class)->summary()['by_priority'])->toBe([
        ['priority' => 'critical', 'count' => 2],
        ['priority' => 'high', 'count' => 1],
        ['priority' => 'medium', 'count' => 1],
        ['priority' => 'low', 'count' => 1],
    ]);
});

it('lists active and breached tickets per agent, including idle agents', function (): void {
    expect(app(DashboardStats::class)->summary()['by_agent'])->toBe([
        ['agent_id' => $this->alpha->id, 'name' => 'Agent Alpha', 'active' => 2, 'breached' => 1],
        ['agent_id' => $this->bravo->id, 'name' => 'Agent Bravo', 'active' => 2, 'breached' => 0],
        ['agent_id' => $this->charlie->id, 'name' => 'Agent Charlie', 'active' => 0, 'breached' => 0],
    ]);
});

it('runs three queries on a cold cache and none on a warm one', function (): void {
    DB::enableQueryLog();
    app(DashboardStats::class)->summary();
    $cold = count(DB::getQueryLog());
    DB::flushQueryLog();
    app(DashboardStats::class)->summary();
    $warm = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($cold)->toBe(3)->and($warm)->toBe(0);
});

it('serves cached figures for 60 seconds', function (): void {
    $first = app(DashboardStats::class)->summary();
    Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_agent_id' => null]);
    expect(app(DashboardStats::class)->summary())->toBe($first);
    $this->travel(61)->seconds();
    expect(app(DashboardStats::class)->summary()['totals']['open'])->toBe(6);
});

it("returns the viewer's active assigned tickets, soonest deadline first", function (): void {
    expect(app(DashboardStats::class)->myTickets($this->alpha)->pluck('status')->all())
        ->toBe([TicketStatus::InProgress, TicketStatus::WaitingForCustomer])
        ->and(app(DashboardStats::class)->myTickets($this->charlie))->toHaveCount(0);
});
```

2. Write `tests/Feature/Dashboard/DashboardEndpointsTest.php`:

```php
beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));
    $this->agent = User::factory()->agent()->create(['name' => 'Agent Alpha']);
    $this->breached = Ticket::factory()->breached()->assignedTo($this->agent)->create([
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::Critical,
    ]);
    Ticket::factory()->create(['status' => TicketStatus::Open, 'assigned_agent_id' => null]);
});

it('renders the dashboard props', function (): void {
    $this->actingAs($this->agent)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Index', false)
            ->where('stats.totals.open', 2)
            ->where('stats.totals.breached', 1)
            ->has('stats.by_priority', 4)
            ->where('stats.by_agent.0.name', 'Agent Alpha')
            ->where('myTickets.0.number', $this->breached->number)
            ->where('myTickets.0.sla.state.value', 'breached'));
});

it('returns the same figures from the API', function (): void {
    Sanctum::actingAs($this->agent, ['*']);
    $this->getJson(route('api.v1.dashboard'))
        ->assertOk()
        ->assertJsonPath('data.totals.unassigned', 1)
        ->assertJsonPath('data.by_priority.0', ['priority' => 'critical', 'count' => 1])
        ->assertJsonPath('data.by_agent.0.agent_id', $this->agent->id)
        ->assertJsonPath('data.my_tickets.0.number', $this->breached->number)
        ->assertJsonStructure(['data' => ['totals', 'by_priority', 'by_agent', 'my_tickets' => [['number', 'subject', 'status', 'priority', 'sla']]]]);
});
```

3. Replace `tests/Feature/App/InertiaSharedPropsTest.php`. Once `dashboard` is gated, its user (who has no permissions) gets a 403, so the test renders through its own probe route instead. The assertions are unchanged.

```php
it('shares only whitelisted user fields, role names, placeholder abilities, flash and the unread count', function (): void {
    Route::middleware(['web', 'auth'])->get('/_shared-props-probe', fn (): Response => Inertia::render('SharedPropsProbe'));
    $user = User::factory()->create();
    $user->assignRole(RoleModel::findOrCreate('example-role'));
    $this->actingAs($user)
        ->withSession(['success' => 'Saved.'])
        ->get('/_shared-props-probe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SharedPropsProbe', false)
            ->has('auth.user', fn (Assert $sharedUser) => $sharedUser
                ->where('id', $user->id)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->where('roles', ['example-role']))
            ->where('auth.can', [
                'viewDashboard' => false,
                'manageImports' => false,
                'manageUsers' => false,
                'manageCategories' => false,
                'manageHolidays' => false,
                'createOnBehalf' => false,
            ])
            ->has('flash', fn (Assert $flash) => $flash
                ->where('success', 'Saved.')
                ->where('error', null))
            ->where('notifications.unread_count', 0));
});
```

4. Run the three files and confirm the new ones fail (`Class "App\Services\Dashboard\DashboardStats" not found`).

5. Create `app/Services/Dashboard/DashboardStats.php`.
   - The totals use one conditional-aggregate query. Its `WHERE` (`status IN active OR (status IN finished AND resolved_at >= ?)`) becomes ranges on `tickets(status, resolved_at)`, so finished history is never scanned. The CASE expressions mirror `SlaQuery`, and the test checks that they agree.
   - `by_priority` is served by `tickets(priority, status)`.
   - `by_agent` starts from the agents and counts through `tickets(assigned_agent_id, status)`, reusing `SlaQuery::whereBreached` inside `withCount`. Agents are a small, admin-managed set.
   - The cached value holds only arrays and scalars.

```php
final class DashboardStats
{
    public const CACHE_KEY = 'dashboard:staff';

    public const CACHE_TTL_SECONDS = 60;
    /** @return array{totals: array<string, int>, by_priority: list<array{priority: string, count: int}>, by_agent: list<array{agent_id: int, name: string, active: int, breached: int}>} */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $now = CarbonImmutable::now();
            return [
                'totals' => $this->totals($now),
                'by_priority' => $this->byPriority(),
                'by_agent' => $this->byAgent($now),
            ];
        });
    }
    /** @return Collection<int, Ticket> */
    public function myTickets(User $viewer, int $limit = 5): Collection
    {
        return Ticket::query()
            ->select(['id', 'number', 'subject', 'status', 'priority', 'sla_due_at', 'sla_warning_at', 'sla_paused_at', 'escalation_level', 'created_at', 'updated_at'])
            ->where('assigned_agent_id', $viewer->id)
            ->whereIn('status', self::values(TicketStatus::active()))
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
    /** @return array<string, int> */
    private function totals(CarbonImmutable $now): array
    {
        $active = self::values(TicketStatus::active());
        $finished = self::values(TicketStatus::finished());
        $activeIn = implode(', ', array_fill(0, count($active), '?'));
        $finishedIn = implode(', ', array_fill(0, count($finished), '?'));
        $resolvedSince = $now->subDays(30);
        $row = (array) Ticket::query()->toBase()
            ->selectRaw(implode(', ', [
                "SUM(CASE WHEN status IN ({$activeIn}) THEN 1 ELSE 0 END) AS open",
                "SUM(CASE WHEN status IN ({$activeIn}) AND assigned_agent_id IS NULL THEN 1 ELSE 0 END) AS unassigned",
                "SUM(CASE WHEN status IN ({$activeIn}) AND priority = ? THEN 1 ELSE 0 END) AS critical",
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_due_at <= ? THEN 1 ELSE 0 END) AS breached",
                "SUM(CASE WHEN status IN ({$activeIn}) AND sla_paused_at IS NULL AND sla_warning_at <= ? AND sla_due_at > ? THEN 1 ELSE 0 END) AS approaching",
                "SUM(CASE WHEN status IN ({$finishedIn}) AND resolved_at >= ? THEN 1 ELSE 0 END) AS resolved",
            ]), [
                ...$active,
                ...$active,
                ...$active, TicketPriority::Critical->value,
                ...$active, $now,
                ...$active, $now, $now,
                ...$finished, $resolvedSince,
            ])
            ->where(static function (QueryBuilder $query) use ($active, $finished, $resolvedSince): void {
                $query->whereIn('status', $active)
                    ->orWhere(static function (QueryBuilder $query) use ($finished, $resolvedSince): void {
                        $query->whereIn('status', $finished)->where('resolved_at', '>=', $resolvedSince);
                    });
            })
            ->first();
        $keys = ['open', 'unassigned', 'critical', 'breached', 'approaching', 'resolved'];
        return array_combine($keys, array_map(static fn (string $key): int => (int) ($row[$key] ?? 0), $keys));
    }
    /** @return list<array{priority: string, count: int}> */
    private function byPriority(): array
    {
        $counts = Ticket::query()->toBase()
            ->select('priority')
            ->selectRaw('COUNT(*) AS priority_count')
            ->whereIn('status', self::values(TicketStatus::active()))
            ->groupBy('priority')
            ->get()
            ->pluck('priority_count', 'priority');
        $priorities = TicketPriority::cases();
        usort($priorities, static fn (TicketPriority $a, TicketPriority $b): int => $b->rank() <=> $a->rank());
        return array_map(
            static fn (TicketPriority $priority): array => ['priority' => $priority->value, 'count' => (int) ($counts[$priority->value] ?? 0)],
            $priorities,
        );
    }
    /** @return list<array{agent_id: int, name: string, active: int, breached: int}> */
    private function byAgent(CarbonImmutable $now): array
    {
        $active = self::values(TicketStatus::active());
        return User::query()
            ->select(['id', 'name'])
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles->where('name', Role::Agent->value))
            ->withCount([
                'assignedTickets as active_count' => static fn (Builder $tickets): Builder => $tickets->whereIn('status', $active),
                'assignedTickets as breached_count' => static fn (Builder $tickets): Builder => SlaQuery::whereBreached($tickets, $now),
            ])
            ->orderByDesc('active_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(static fn (User $agent): array => [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'active' => (int) $agent->getAttribute('active_count'),
                'breached' => (int) $agent->getAttribute('breached_count'),
            ])
            ->values()
            ->all();
    }
    /**
     * @param  list<TicketStatus>  $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (TicketStatus $status): string => $status->value, $statuses);
    }
}
```

6. Create `app/Http/Resources/V1/DashboardResource.php`:

```php
/** @property array{stats: array<string, mixed>, my_tickets: Collection<int, Ticket>} $resource */
final class DashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource['stats'],
            'my_tickets' => TicketListResource::collection($this->resource['my_tickets']),
        ];
    }
}
```

7. Create the controllers. Web:

```php
final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): Response
    {
        $viewer = $request->user();
        assert($viewer instanceof User);
        return Inertia::render('dashboard/Index', [
            'stats' => fn (): array => $stats->summary(),
            'myTickets' => fn (): array => TicketListResource::collection($stats->myTickets($viewer))->resolve($request),
        ]);
    }
}
```

The API `__invoke(Request $request, DashboardStats $stats): DashboardResource` returns `DashboardResource::make(['stats' => $stats->summary(), 'my_tickets' => $stats->myTickets($viewer)])`.

8. Replace `routes/web/dashboard.php` and create `routes/api/v1/dashboard.php`:

```php
Route::get('dashboard', DashboardController::class)->middleware('auth')->can('viewDashboard')->name('dashboard');
```

```php
Route::get('dashboard', DashboardController::class)->middleware('auth:sanctum')->can('viewDashboard')->name('dashboard');
```

P4.6 removes `GET dashboard` from `RouteAudit::AUTHENTICATED_ONLY`, now that the route is gated.

9. Run: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Dashboard tests/Feature/App`. All pass, including `RouteLoadingTest` (the `dashboard` route is still in the `web` group) and `HomeRedirectTest`.

**Proposed commit:** `feat(dashboard): add cached staff dashboard statistics for web and API` · `Refs: P4.5` · `Implements: TD-100, TD-021, TD-023, TD-011`

---

## P4.6 — Authorization matrix and end-to-end internal-note test

**Files:**
- modify `tests/Feature/Authorization/AuthorizationMatrixTest.php` (append; the ROADMAP gives this file to each plan's last wave)
- modify `tests/Support/RouteAudit.php` (add the P4 bindings; remove `GET dashboard` from `AUTHENTICATED_ONLY`)
- create `tests/Feature/Notifications/InternalNoteEndToEndTest.php`

**Consumes:** every route from P4.2–P4.5; `Tests\Support\AuthorizationMatrix` (`actingAsRole`, `asGuest`, `expectAccess`); `Tests\Support\Imports\ImportCsvFixture::pendingImport`; P3's `api.v1.tickets.replies.store` (fields `body`, `is_internal`; it returns 201, per T07 §3 Produces).

**Steps:**

1. In `tests/Support/RouteAudit.php`, delete the `'GET dashboard',` line from `AUTHENTICATED_ONLY`, and add these entries to `bindings()` (import `Category`, `Holiday`, `Str` and `ImportCsvFixture`). A no-role user is refused by the `can:` middleware before the model is touched, so a random UUID is enough for `{notification}`.

```php
            'notification' => static fn (): string => (string) Str::uuid(),
            'import' => static fn (): string => (string) ImportCsvFixture::pendingImport(User::factory()->admin()->create())->id,
            'category' => static fn (): string => (string) Category::factory()->create()->id,
            'holiday' => static fn (): string => (string) Holiday::factory()->create()->id,
```

2. Append to `tests/Feature/Authorization/AuthorizationMatrixTest.php` (add any missing imports: `Category`, `Holiday`, `User`, `TicketAssignedNotification`, `Str`, `ImportCsvFixture`).
   - Each route lists the expected status per role and for guests.
   - Admin writes with an empty payload stop at validation (web 302, API 422). An agent or customer gets 403 on the same request, which proves the `can:` middleware runs before validation.
   - `{notification}` is owned by the acting user (for guests, by another user).
   - Web guests are redirected to login (302), and API guests get 401.

```php
dataset('p4 routes', function (): array {
    $everyone = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => $status, 'customer' => $status, 'guest' => $guest];
    $staff = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => $status, 'customer' => 403, 'guest' => $guest];
    $admin = fn (int $status, int $guest): array => ['admin' => $status, 'agent' => 403, 'customer' => 403, 'guest' => $guest];
    $routes = [
        ['GET', '/notifications', $everyone(200, 302)],
        ['POST', '/notifications/{notification}/read', $everyone(302, 302)],
        ['POST', '/notifications/read-all', $everyone(302, 302)],
        ['GET', '/api/v1/notifications', $everyone(200, 401)],
        ['POST', '/api/v1/notifications/{notification}/read', $everyone(200, 401)],
        ['POST', '/api/v1/notifications/read-all', $everyone(204, 401)],
        ['GET', '/dashboard', $staff(200, 302)],
        ['GET', '/api/v1/dashboard', $staff(200, 401)],
        ['GET', '/admin/imports', $admin(200, 302)],
        ['GET', '/admin/imports/create', $admin(200, 302)],
        ['POST', '/admin/imports', $admin(302, 302)],
        ['GET', '/admin/imports/{import}', $admin(200, 302)],
        ['GET', '/admin/imports/{import}/errors/download', $admin(200, 302)],
        ['POST', '/api/v1/imports', $admin(422, 401)],
        ['GET', '/api/v1/imports/{import}', $admin(200, 401)],
        ['GET', '/api/v1/imports/{import}/errors', $admin(200, 401)],
        ['GET', '/admin/categories', $admin(200, 302)],
        ['GET', '/admin/categories/create', $admin(200, 302)],
        ['POST', '/admin/categories', $admin(302, 302)],
        ['GET', '/admin/categories/{category}/edit', $admin(200, 302)],
        ['PATCH', '/admin/categories/{category}', $admin(302, 302)],
        ['DELETE', '/admin/categories/{category}', $admin(302, 302)],
        ['GET', '/api/v1/categories', $everyone(200, 401)],
        ['GET', '/api/v1/admin/categories', $admin(200, 401)],
        ['POST', '/api/v1/admin/categories', $admin(422, 401)],
        ['PATCH', '/api/v1/admin/categories/{category}', $admin(422, 401)],
        ['DELETE', '/api/v1/admin/categories/{category}', $admin(204, 401)],
        ['GET', '/admin/holidays', $admin(200, 302)],
        ['GET', '/admin/holidays/create', $admin(200, 302)],
        ['POST', '/admin/holidays', $admin(302, 302)],
        ['GET', '/admin/holidays/{holiday}/edit', $admin(200, 302)],
        ['PATCH', '/admin/holidays/{holiday}', $admin(302, 302)],
        ['DELETE', '/admin/holidays/{holiday}', $admin(302, 302)],
        ['GET', '/api/v1/admin/holidays', $admin(200, 401)],
        ['POST', '/api/v1/admin/holidays', $admin(422, 401)],
        ['PATCH', '/api/v1/admin/holidays/{holiday}', $admin(422, 401)],
        ['DELETE', '/api/v1/admin/holidays/{holiday}', $admin(204, 401)],
    ];
    $cases = [];
    foreach ($routes as [$method, $uri, $statuses]) {
        foreach ($statuses as $who => $status) {
            $cases["{$who} · {$method} {$uri} → {$status}"] = [$who, $method, $uri, $status];
        }
    }
    return $cases;
});

it('authorizes every P4 route', function (string $who, string $method, string $uri, int $status): void {
    $matrix = AuthorizationMatrix::for($this);
    $user = $who === 'guest' ? null : $matrix->actingAsRole(Role::from($who));
    if ($user === null) {
        $matrix->asGuest();
    }
    $owner = $user ?? User::factory()->customer()->create();
    $placeholders = [
        '{notification}' => (string) $owner->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TicketAssignedNotification::class,
            'data' => ['ticket_number' => null, 'title' => 't', 'body' => 'b', 'url' => url('/')],
        ])->id,
        '{import}' => (string) ImportCsvFixture::pendingImport(User::factory()->admin()->create())->id,
        '{category}' => (string) Category::factory()->create()->id,
        '{holiday}' => (string) Holiday::factory()->create()->id,
    ];
    $response = $matrix->expectAccess($method, strtr($uri, $placeholders), $status);
    if ($who === 'guest' && $status === 302) {
        $response->assertRedirect(route('login'));
    }
})->with('p4 routes');
```

3. Write `tests/Feature/Notifications/InternalNoteEndToEndTest.php`. It goes through P3's real reply endpoint and P4.2's real listeners and inbox. Nothing is faked: the sync queue writes the database notifications, and the customer's own inbox must stay empty.

```php
it('never lets an internal note reach the customer, from reply endpoint to inbox', function (): void {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $colleague = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create([
        'customer_id' => $customer->id,
        'created_by_id' => $customer->id,
    ]);
    Sanctum::actingAs($colleague, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $ticket), ['body' => 'Do not tell the customer', 'is_internal' => true])->assertCreated();
    Sanctum::actingAs($customer, ['*']);
    $this->getJson(route('api.v1.notifications.index'))->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($agent, ['*']);
    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'InternalNoteAddedNotification');
    Sanctum::actingAs($colleague, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $ticket), ['body' => 'Public answer'])->assertCreated();
    Sanctum::actingAs($customer, ['*']);
    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'TicketRepliedNotification')
        ->assertJsonMissing(['body' => 'Do not tell the customer']);
});
```

4. Run: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest tests/Feature/Authorization tests/Feature/Notifications/InternalNoteEndToEndTest.php`. This includes P2's `RouteProtectionTest`, which must now pass for every P4 route. All pass. A failing matrix row is a real authorization gap: report it with the route and the actual status, and don't change the expected value to match.

**Proposed commit:** `test(auth): cover automation and admin routes in the route audit and matrix` · `Refs: P4.6` · `Implements: TD-010, TD-012, TD-120`

---

## Produces for P5

Pages P5 must create (nothing in P4 creates Vue files). Each one is rendered by the server today. Paginated props have the `Paginated<T>` shape from `resources/js/types/tickets.ts` (`{data, links, meta}`). Enum fields are `{value, label}`.

| Page component | Route name (web) | Props |
|---|---|---|
| `notifications/Index` | `notifications.index` (`?unread=1&page=&per_page=`) | `items: Paginated<NotificationItem>`, `unreadOnly: boolean` |
| `admin/imports/Index` | `admin.imports.index` | `imports: Paginated<TicketImportItem>` |
| `admin/imports/Create` | `admin.imports.create` (form posts multipart `file` to `admin.imports.store`) | `maxUploadKb: number`, `columns: { required: string[]; optional: string[] }` |
| `admin/imports/Show` | `admin.imports.show` | `ticketImport: TicketImportItem`, `importErrors: Paginated<TicketImportErrorItem>`. Poll with `only: ['ticketImport', 'importErrors']` until `ticketImport.is_finished`. Link `admin.imports.errors.download`. |
| `admin/categories/Index` | `admin.categories.index` | `categories: Paginated<AdminCategory>` |
| `admin/categories/Create` | `admin.categories.create` (posts `name, slug, is_active?` to `admin.categories.store`) | none |
| `admin/categories/Edit` | `admin.categories.edit` (patches `admin.categories.update`; deletes `admin.categories.destroy`) | `category: AdminCategory` |
| `admin/holidays/Index` | `admin.holidays.index` (`?year=`) | `holidays: Paginated<HolidayItem>`, `year: number` |
| `admin/holidays/Create` | `admin.holidays.create` (posts `date` as `Y-m-d`, `name` to `admin.holidays.store`) | none |
| `admin/holidays/Edit` | `admin.holidays.edit` (patches `admin.holidays.update`; deletes `admin.holidays.destroy`) | `holiday: HolidayItem` |
| `dashboard/Index` | `dashboard` (staff only; replaces the kit's `pages/Dashboard.vue`, which P5 deletes) | `stats: DashboardStats`, `myTickets: TicketListItem[]` |

Types (P5 owns `resources/js/types/notifications.ts`, `imports.ts`, `admin.ts` and `dashboard.ts`):
```ts
type NotificationItem = { id: string; type: string; ticket_number: string | null; title: string; body: string; url: string | null; read_at: string | null; created_at: string | null };
type TicketImportItem = { id: number; original_name: string; status: EnumValue<'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed'>; is_finished: boolean; total_rows: number; processed_rows: number; succeeded_rows: number; failed_rows: number; progress_percent: number; started_at: string | null; finished_at: string | null; created_at: string | null; user?: UserSummary };
type TicketImportErrorItem = { id: number; row_number: number; errors: Record<string, string[]>; raw: Record<string, string | null>; created_at: string | null };
type AdminCategory = { id: number; name: string; slug: string; is_active: boolean; tickets_count: number };
type HolidayItem = { id: number; date: string; weekday: string; name: string };
type DashboardStats = { totals: { open: number; unassigned: number; critical: number; breached: number; approaching: number; resolved: number }; by_priority: { priority: TicketPriorityValue; count: number }[]; by_agent: { agent_id: number; name: string; active: number; breached: number }[] };
```

Shared prop for the navigation badge: `notifications.unread_count: number`, already typed in `SharedProps`. The actions are `notifications.read` (`POST`, UUID) and `notifications.read-all` (`POST`); both redirect back, and read-all flashes `success`. Navigation visibility uses the existing `auth.can.viewDashboard`, `manageImports`, `manageCategories` and `manageHolidays`. Web validation errors arrive as Inertia `errors` keyed by field (`file`, `name`, `slug`, `is_active`, `date`).

## Acceptance criteria

1. `php artisan sla:scan` exits 0 and prints `SLA scan at …: n approaching, m breached.`. It streams candidates with `chunkById(500)` on an id-only select, re-checks each chunk under `FOR UPDATE`, and updates with `escalation_level < N`.
2. A ticket with `warning ≤ now < due` reaches level 1 with one `TicketSlaApproaching`. A ticket with `due ≤ now` reaches level 2 with one `TicketSlaBreached` and `escalated_at = now`. A 0 → 2 jump fires only `TicketSlaBreached`. Repeated runs fire nothing new. Paused and finished tickets are never escalated. A ticket paused between selection and lock isn't escalated, and a ticket whose level was lowered escalates again.
3. Each escalation writes exactly one `sla_approaching` or `escalated` activity entry with a null causer, through `RecordTicketActivity`.
4. `routes/console.php` schedules `sla:scan` every minute with `withoutOverlapping()->onOneServer()`, and a test asserts it.
5. All eight notification classes are queued on `notifications` with `afterCommit()`. Their database payload is exactly `{ticket_number, title, body, url}`. `InternalNoteAddedNotification` and `TicketImportFinishedNotification` use the database channel only, and `TicketRepliedNotification` refuses an internal reply.
6. The listeners send exactly the spec §11 recipients in the P4.2 table. The actor and the reply author are never notified, except for the customer's own creation receipt. Imported tickets notify nobody. A customer receives nothing for an internal note.
7. The notification index lists only the caller's notifications, newest first, paginated (`per_page` ≤ 100), and supports `unread=1`. Marking another user's notification answers 404 and changes nothing. Read-all updates only the caller's rows. Every Inertia page shares `notifications.unread_count`.
8. A CSV upload is validated (`file`, `mimes:csv,txt`, max `imports.max_upload_kb` = 51200 KB) and stored under a random name on the private imports disk. It creates a `pending` import and dispatches `PrepareTicketImport` on `imports`. The web redirects to `admin.imports.show`, and the API returns 202 with `TicketImportResource`.
9. `PrepareTicketImport` streams the file: preparing a 10,000-row (~6 MB) file grows peak memory by less than 2 MB. An invalid header fails the import with error row 1 and one `TicketImportFinished`. A valid one sets `processing`, `total_rows` and `started_at`, and dispatches a named batch of record-range `ImportTicketsChunk` jobs on `imports` with `allowFailures()` and a `finally` that dispatches `FinalizeTicketImport`.
10. Each row is validated (active category slug, an existing customer's email, enum priority and status, `created_at` parseable and not in the future, `external_reference` ≤ 64 characters and unused) and imported in its own transaction. The import reuses the number generator and `SlaCalculator`, starts the SLA at `created_at` (read in business time), sets `created_by_id` to the admin, lands at its current escalation level, and fires `TicketCreated(imported: true)`. A unique-index race on `external_reference` becomes an error row, and the ticket and its sequence number roll back.
11. Rejected rows are stored as `(row_number, errors, raw)`. Counters change only through atomic increments. Finalize sets `completed`, `completed_with_errors` or `failed` exactly once and fires `TicketImportFinished` once.
12. The errors CSV is streamed with `nosniff` as an attachment, and formula-leading cells are prefixed with `'`. The API pages errors by row number with `per_page` ≤ 100.
13. Deleting a category that tickets reference deactivates it instead. Slugs are lower-case kebab and unique. `api.v1.categories.index` lists only active categories, to anyone who may create a ticket.
14. Every holiday create, update and delete flushes `sla.holidays`. A holiday added through the admin route moves the next SLA deadline computed for that date. Holiday dates are unique `Y-m-d` values, listed per business-time year.
15. `DashboardStats::summary()` returns the six totals, all four priorities (critical first) and every agent with active and breached counts. It runs three queries cold and none warm, is cached for 60 seconds, and its breached and approaching counts equal `SlaQuery`'s. `/dashboard` and `/api/v1/dashboard` serve it with the viewer's five soonest active assigned tickets.
16. P2's `RouteProtectionTest` passes with P4's routes: guests are turned away, and a user with no role gets 403 or 404 everywhere, notification routes included. The P4.6 matrix passes for every route in this plan: admin-only routes give 403 to agents and customers, the dashboard gives 403 to customers, web guests are redirected to login, and API guests get 401.
17. An internal note posted through P3's reply endpoint never appears in the customer's notification inbox, while the assignee receives it and a later public reply does reach the customer.
18. No new migrations; no comments in code; every new PHP file has `declare(strict_types=1);`; Actions, Services, Jobs and Listeners are `final`.
