# Support Ticket & SLA Management System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Laravel 12 support-ticket system with business-hours SLAs, escalation, concurrency-safe assignment, and both an Inertia/Vue UI and a versioned REST API, as specified in `PRD.md`.

**Architecture:** Thin Web (Inertia) and API (`/api/v1`) controllers share FormRequests, Policies and single-purpose Actions. Pure services handle the SLA and business-hours math. Domain events drive the activity log, queued notifications and auto-assignment. See TD-001.

**Tech stack:** Laravel 12, PHP 8.3, MySQL 8, Inertia 2, Vue 3 + TypeScript, Tailwind, shadcn-vue, Sanctum, spatie/laravel-permission, spatie/laravel-activitylog, dedoc/scramble, Pest 3, Larastan, Pint.

**Required reading for every task:**
- `CLAUDE.md` (guardrails)
- `docs/superpowers/specs/2026-10-29-support-ticket-sla-design.md` (spec)
- `TECHNICAL_DECISIONS.md` (TD-xxx)
- this file's **Interface Contract**

## Global constraints

- PHP `^8.3`, Laravel `^12.0`, MySQL `8.0`, Node `>=20`.
- Packages, with these exact names:
  - `laravel/sanctum`
  - `spatie/laravel-permission:^6.0`
  - `spatie/laravel-activitylog:^4.10`
  - `dedoc/scramble`
  - `pestphp/pest:^3.0`
  - `pestphp/pest-plugin-laravel`
  - `larastan/larastan:^3.0`
  - `laravel/pint`
- **No other runtime packages** without a new TD entry.
- `declare(strict_types=1);` in every PHP file under `app/`, `database/`, `routes/` and `tests/`.
- Storage and time: store UTC (`APP_TIMEZONE=UTC`), and compute business time in `config('business_hours.timezone')` (default `Asia/Dhaka`).
- Ticket number format: `TKT-{YYYY}-{%06d}`. The prefix comes from `config('tickets.number_prefix')` (default `TKT`).
- SLA minutes: low 2880, medium 1440, high 480, critical 120. Warning threshold 75%.
- Business hours: Sunday–Thursday 09:00–18:00. Friday and Saturday are closed.
- Attachments: at most 10 MB (10240 KB) per file and 5 files per reply. Allowed extensions: `pdf,png,jpg,jpeg,gif,txt,csv,doc,docx,xls,xlsx,zip`.
- CSV import: at most 50 MB (51200 KB), chunk size 500 rows.
- Pagination: default 20, max 100.
- Test database: MySQL `techzu_testing`.
- The gate must pass before every commit (see CLAUDE.md).

---

## 1. Waves & dependency graph

```
Wave 0   T00 Bootstrap
           │
Wave 1   T01 Schema, enums, models, seeders
           │
Wave 2   ┌─ T02 Calendar & SLA ─┐
         ├─ T03 Ticket number ──┤   (parallel, max 3)
         └─ T04 Authorization ──┘
           │
Wave 3   T05 Ticket lifecycle   (has its own internal mini-waves)
           │
Wave 4   ┌─ T06 Assignment ──────┐
 (4a)    ├─ T07 Conversation ────┤   (parallel, max 4)
         ├─ T08 Search & filter ─┤
         └─ T09 Escalation ──────┘
 (4b)    ┌─ T10 Notifications ───┐
         ├─ T11 CSV import ──────┤   (parallel, max 4; may start as 4a slots free up)
         ├─ T12 Dashboard ───────┤
         └─ T13 Admin config ────┘
           │
Wave 5   T14 Hardening & documentation
```

| Task | File | Depends on | Wave |
|---|---|---|---|
| T00 | [T00-bootstrap.md](T00-bootstrap.md) | — | 0 |
| T01 | [T01-schema-models.md](T01-schema-models.md) | T00 | 1 |
| T02 | [T02-business-calendar-sla.md](T02-business-calendar-sla.md) | T01 | 2 |
| T03 | [T03-ticket-number.md](T03-ticket-number.md) | T01 | 2 |
| T04 | [T04-authorization.md](T04-authorization.md) | T01 | 2 |
| T05 | [T05-ticket-lifecycle.md](T05-ticket-lifecycle.md) | T02, T03, T04 | 3 |
| T06 | [T06-assignment.md](T06-assignment.md) | T05 | 4a |
| T07 | [T07-conversation-attachments.md](T07-conversation-attachments.md) | T05 | 4a |
| T08 | [T08-search-filtering.md](T08-search-filtering.md) | T05 | 4a |
| T09 | [T09-sla-escalation.md](T09-sla-escalation.md) | T05 | 4a |
| T10 | [T10-notifications.md](T10-notifications.md) | T05 | 4b |
| T11 | [T11-csv-import.md](T11-csv-import.md) | T05 | 4b |
| T12 | [T12-dashboard.md](T12-dashboard.md) | T05 | 4b |
| T13 | [T13-admin-config.md](T13-admin-config.md) | T05 | 4b |
| T14 | [T14-hardening-docs.md](T14-hardening-docs.md) | T00–T13 | 5 |

Wave 4 tasks are independent of each other by construction: separate route files, separate component folders, auto-discovered listeners, and events defined in T05. If two wave-4 tasks seem to need the same file, the file-ownership table (§4) decides who owns it. The other task stops and reports.

## 2. Execution workflow (per task)

For each task (`superpowers:subagent-driven-development`):

1. **Worktree:** the controller creates it:
   ```bash
   git worktree add ../techzu-wt/Txx -b task/Txx-<slug> main
   ```
   Each worktree needs its own `.env` (copied from `.env.example`) with `DB_DATABASE=techzu_wt_txx`. `phpunit.xml` values take precedence over `.env`, so tests must be run with the database on the command line: `DB_DATABASE=techzu_wt_txx_testing ./vendor/bin/pest --parallel`. That keeps parallel worktrees from sharing a database.
2. **Implementer subagent:** given its task file, CLAUDE.md, the spec and this contract.
   - Runs the subtasks in the order of the task's mini-waves.
   - Works test-first (red → green → refactor).
   - Commits per subtask on the task branch (`Txx.n: ...`).
3. **Spec reviewer subagent:** has **not** seen the implementation reasoning. Checks every acceptance criterion and every "Produces" interface against this contract, and reports PASS or a list of gaps.
4. **Code-quality reviewer subagent:** checks against CLAUDE.md (architecture, security, database, style rules), naming consistency, and missing tests for risky logic. Reports PASS or a list of findings.
5. **Fix loop:** the implementer fixes the findings, and the reviewers re-check only those findings.
6. **Gate (controller runs it in the worktree):**
   ```bash
   ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
   npm run build && npx vue-tsc --noEmit   # if resources/js changed
   ```
7. **Merge:** squash-merge into `main` as one commit, `Txx: <summary>`, with the TD list in the body. Then remove the worktree.
8. **Next wave** starts only after every task in its prerequisite wave has merged.

**Parallelism limit:** at most 4 implementers run concurrently. Subtasks inside a task may run in parallel *only* where the task file's mini-wave table says so, and only by separate subagents in the same worktree working on disjoint files.

**Escalation rule:** if an implementer finds that the contract (§3) is wrong or incomplete, they **stop and report**. The controller amends this README in a separate commit (`plan: amend contract ...`), and only then does work continue.

### Task file template

Every `Txx-*.md` file has these sections, in this order:
1. **Header:** objective (one sentence), depends on, wave, the TDs implemented.
2. **Files:** create / modify / test. Exact paths. Plus "must not touch".
3. **Interfaces:** consumes and produces. Exact signatures, consistent with §3.
4. **Subtasks & mini-waves:** a table of subtask → files → depends on → parallel-safe with.
5. **Each subtask:** bite-sized steps (write the failing test → run it, expect FAIL → implement → run it, expect PASS → commit), with complete code and exact commands.
6. **Acceptance criteria:** a checklist.
7. **Verification:** the gate commands and the expected outcome.

---

## 3. Interface contract

Names below are **binding**. Task files may add private helpers, but every public name that crosses a task boundary is listed here.

### 3.1 Enums — `app/Enums` (T01)

All are string-backed unless stated otherwise.

```php
enum Role: string { case Admin = 'admin'; case Agent = 'agent'; case Customer = 'customer';
    public function label(): string; }

enum Permission: string {
    case TicketsCreate = 'tickets.create';
    case TicketsCreateOnBehalf = 'tickets.create.on_behalf';
    case TicketsViewAny = 'tickets.view.any';
    case TicketsViewOwn = 'tickets.view.own';
    case TicketsReply = 'tickets.reply';
    case TicketsNotesInternal = 'tickets.notes.internal';
    case TicketsClaim = 'tickets.claim';
    case TicketsUpdateAny = 'tickets.update.any';
    case TicketsUpdateAssigned = 'tickets.update.assigned';
    case TicketsUpdateOwn = 'tickets.update.own';
    case TicketsAssignAny = 'tickets.assign.any';
    case TicketsHandoffOwn = 'tickets.handoff.own';
    case DashboardView = 'dashboard.view';
    case ImportsManage = 'imports.manage';
    case UsersManage = 'users.manage';
    case CategoriesManage = 'categories.manage';
    case HolidaysManage = 'holidays.manage';
    /** @return list<self> */ public static function forRole(Role $role): array;  // the seeded baseline (TD-011)
}

enum TicketStatus: string {
    case Open = 'open'; case InProgress = 'in_progress'; case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved'; case Closed = 'closed';
    public function label(): string;
    /** @return list<self> */ public function allowedTransitions(): array;
    public function canTransitionTo(self $to): bool;
    public function isActive(): bool;               // Open, InProgress, WaitingForCustomer
    /** @return list<self> */ public static function active(): array;
    /** @return list<self> */ public static function finished(): array;  // Resolved, Closed
}

enum TicketPriority: string {
    case Low = 'low'; case Medium = 'medium'; case High = 'high'; case Critical = 'critical';
    public function label(): string;
    public function rank(): int;                    // low=1 … critical=4, for sorting
}

enum SlaState: string { case Within = 'within'; case Approaching = 'approaching'; case Breached = 'breached';
    case Paused = 'paused'; case Stopped = 'stopped'; public function label(): string; }

enum EscalationLevel: int { case None = 0; case Approaching = 1; case Breached = 2; }

enum AssignmentMethod: string { case Manual = 'manual'; case Claimed = 'claimed'; case Automatic = 'automatic'; }

enum ActivityType: string {
    case Created = 'created'; case Updated = 'updated'; case Assigned = 'assigned'; case Claimed = 'claimed';
    case Unassigned = 'unassigned'; case StatusChanged = 'status_changed'; case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed'; case Replied = 'replied'; case InternalNote = 'internal_note';
    case SlaPaused = 'sla_paused'; case SlaResumed = 'sla_resumed'; case SlaApproaching = 'sla_approaching';
    case Escalated = 'escalated'; case Imported = 'imported';
    /** @param array<string,mixed> $properties */ public function describe(array $properties): string;
    public function isStaffOnly(): bool;            // true for InternalNote
}

enum ImportStatus: string { case Pending = 'pending'; case Processing = 'processing'; case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors'; case Failed = 'failed'; }
```

### 3.2 Domain exceptions — `app/Exceptions/Domain` (base + renderer: T00; subclasses: T01)

```php
abstract class DomainException extends \RuntimeException {
    abstract public function status(): int;        // HTTP status
    abstract public function errorCode(): string;  // stable snake_case code
}
final class InvalidStatusTransition extends DomainException  // 422 'invalid_status_transition'
    { public static function between(TicketStatus $from, TicketStatus $to): self; }
final class TicketAlreadyClaimed extends DomainException     // 409 'ticket_already_claimed'
    { public static function for(string $ticketNumber): self; }
final class AgentNotEligible extends DomainException         // 422 'agent_not_eligible'
final class TicketClosed extends DomainException             // 422 'ticket_closed'
final class ImportHeaderInvalid extends DomainException      // 422 'import_header_invalid'
final class CannotChangeOwnRole extends DomainException       // 422 'cannot_change_own_role' (created by T13)
// AgentNotEligible, TicketClosed, ImportHeaderInvalid and CannotChangeOwnRole take an optional message: `new X($message)`.
```
T00 creates the abstract `DomainException` and its renderer, tested with a test-only subclass. T01 creates the five concrete subclasses alongside the enums they reference.

Rendering (in `bootstrap/app.php`, T00):
- `expectsJson()` → `{"message": ..., "code": ...}` with `status()`.
- Otherwise → `back()->with('error', $message)`.

### 3.3 Models — `app/Models` (T01)

| Model | Key relations and methods |
|---|---|
| `User` (`HasRoles`, `HasApiTokens`, `Notifiable`, `HasFactory`) | `categories(): BelongsToMany<Category>` (pivot `agent_category`), `assignedTickets(): HasMany<Ticket>` (`assigned_agent_id`), `customerTickets(): HasMany<Ticket>` (`customer_id`), `isStaff(): bool` (has role admin or agent), `isAdmin(): bool`, `scopeAvailableAgents(Builder $q): void` (role agent + `is_available`), `scopeAdmins(Builder $q): void`. Fillable: `name, email, password, is_available`. Casts: `is_available` bool, `last_assigned_at` immutable_datetime, `password` hashed. |
| `Category` | `agents(): BelongsToMany<User>`, `tickets(): HasMany<Ticket>`, `scopeActive`. Fillable: `name, slug, is_active`. |
| `Ticket` | `getRouteKeyName(): 'number'`; relations `category()`, `customer()` (`customer_id`), `creator()` (`created_by_id`), `assignedAgent()` (`assigned_agent_id`), `replies(): HasMany<TicketReply>`, `attachments(): HasMany<Attachment>`, `activities(): MorphMany<Activity>` (subject). Scope `scopeVisibleTo(Builder $q, User $user): void` (customers: `customer_id = user.id`; staff: everything). `activitiesVisibleTo(User $user): MorphMany` (log_name `ticket`; hides `ActivityType::isStaffOnly()` events from non-staff). Helpers `isActive(): bool`, `isPaused(): bool`, `slaState(?CarbonImmutable $now = null): SlaState`. Fillable: `subject, description, priority, category_id, customer_id, created_by_id, external_reference`. Casts: `status` TicketStatus, `priority` TicketPriority, `escalation_level` EscalationLevel, all `*_at` immutable_datetime. |
| `TicketReply` | `ticket()`, `author()`, `attachments()`; `scopeVisibleTo(Builder $q, User $user): void` (customers: `is_internal = false`). Fillable: `body, is_internal`. |
| `Attachment` | `ticket()`, `reply()`, `uploader()` (`uploaded_by`). `const UPDATED_AT = null`. Fillable: none; set by `AttachmentStorer`. |
| `Holiday` | Fillable `date, name`; cast `date` immutable_date. |
| `TicketImport` | `user()`, `errors(): HasMany<TicketImportError>`; cast `status` ImportStatus. |
| `TicketImportError` | `import(): BelongsTo<TicketImport>` (`ticket_import_id`); casts `errors` array, `raw` array. `const UPDATED_AT = null`. |

Factories (T01): `UserFactory` with states `admin()`, `agent(array $categoryIds = [])`, `customer()`, `unavailable()`. These states assign the spatie role through `afterCreating`, so `RolesAndPermissionsSeeder` must already have run. Also `CategoryFactory`, `TicketFactory` (states `assignedTo(User)`, `status(TicketStatus)`, `priority(TicketPriority)`, `breached()`, `approaching()`, `paused()`), `TicketReplyFactory` (state `internal()`), `AttachmentFactory`, `HolidayFactory`.

Seeders (T01): `RolesAndPermissionsSeeder` (idempotent; uses `Permission::forRole`), `DemoSeeder` (1 admin, 4 agents across 3 categories, 5 customers, about 60 tickets in mixed states), and `DatabaseSeeder` calling both. Demo credentials: `admin@example.com`, `agent1@example.com`…`agent4@example.com`, `customer1@example.com`…`customer5@example.com`, all with password `password`.

Test bootstrap (T01): `tests/Pest.php` uses `RefreshDatabase` for `Feature`, and a `beforeEach` seeds `RolesAndPermissionsSeeder` and calls `app(PermissionRegistrar::class)->forgetCachedPermissions()`.

### 3.4 Services

```php
// T02 — app/Services/Sla
final class BusinessCalendar {
    /** @param array<string, list<array{0:string,1:string}>> $week  lowercase weekday => [['09:00','18:00'], ...]
     *  @param list<string> $holidays  'Y-m-d' dates in business timezone */
    public function __construct(array $week, string $timezone, array $holidays = []);
    public function addBusinessMinutes(CarbonImmutable $from, int $minutes): CarbonImmutable; // returns UTC
    public function businessMinutesBetween(CarbonImmutable $from, CarbonImmutable $to): int;  // 0 if $to <= $from
    public function isBusinessTime(CarbonImmutable $at): bool;
}
final class HolidayRepository { /** @return list<string> */ public function dates(): array; public function flush(): void; } // cache key 'sla.holidays'
final readonly class SlaDeadlines { public function __construct(public CarbonImmutable $dueAt, public CarbonImmutable $warningAt); }
final class SlaCalculator {
    /** @param array<string,int> $resolutionMinutes keyed by TicketPriority value */
    public function __construct(BusinessCalendar $calendar, array $resolutionMinutes, int $warningPercent);
    public function deadlinesFor(TicketPriority $priority, CarbonImmutable $start): SlaDeadlines;
    public function applyInitial(Ticket $ticket, CarbonImmutable $start): void;          // sets sla_started_at, sla_due_at, sla_warning_at (no save)
    public function recalculateForPriority(Ticket $ticket, CarbonImmutable $now): void;  // from sla_started_at; keeps pause; resets escalation_level via levelFor (no save)
    public function pause(Ticket $ticket, CarbonImmutable $now): void;                   // no save
    public function resume(Ticket $ticket, CarbonImmutable $now): void;                  // shifts due/warning; resets escalation_level via levelFor (no save)
    public function levelFor(Ticket $ticket, CarbonImmutable $now): EscalationLevel;     // level the ticket *should* have now
}
// T02 — app/Providers/SlaServiceProvider.php binds BusinessCalendar (built from config + HolidayRepository, scoped) and SlaCalculator (singleton per request).

// T03 — app/Services/Tickets
final class TicketNumberGenerator { public function next(CarbonImmutable $at): string; } // opens its own (nested) transaction

// T05 — app/Services/Sla
final class SlaQuery {   // index-backed predicates; reused by T08, T09, T12
    public static function whereState(Builder $q, SlaState $state, CarbonImmutable $now): Builder;
    public static function whereBreached(Builder $q, CarbonImmutable $now): Builder;     // active, not paused, sla_due_at <= now
    public static function whereApproaching(Builder $q, CarbonImmutable $now): Builder;  // active, not paused, warning <= now < due
}
// T05 — app/Services/Attachments
final class AttachmentStorer {
    /** @param list<UploadedFile> $files @return Collection<int, Attachment> */
    public function storeMany(Ticket $ticket, ?TicketReply $reply, User $uploader, array $files, bool $isInternal): Collection;
    /** @param Collection<int, Attachment> $attachments */ public function deleteFiles(Collection $attachments): void; // compensation
}
// T05 — app/Services/Tickets (consumed by T06 to render TicketResource)
final class TicketPageData { public function loadSummary(Ticket $ticket): Ticket; /* plus T05-internal page builders */ }
// T06 — app/Services/Assignment
final class AgentSelector { public function selectFor(Ticket $ticket, bool $lock = false): ?User; }
// T08 — app/Services/Tickets
final class TicketFilter { /** @param array<string,mixed> $filters validated */
    public function apply(Builder $query, array $filters, User $viewer, CarbonImmutable $now): Builder; }
// T12 — app/Services/Dashboard
final class DashboardStats { /** @return array{totals: array<string,int>, by_priority: list<array{priority:string,count:int}>, by_agent: list<array{agent_id:int,name:string,active:int,breached:int}>} */
    public function for(User $viewer): array; }
```

### 3.5 Actions — `app/Actions` (single public `handle`)

```php
// T05
Tickets\CreateTicket::handle(User $actor, array $data, array $files = []): Ticket
    // $data: subject, description, priority (string), category_id, customer_id? (staff only; else actor)
    // tx: number + SLA + insert + attachments; fires TicketCreated
Tickets\UpdateTicket::handle(User $actor, Ticket $ticket, array $data): Ticket           // subject, description, category_id; fires TicketUpdated
Tickets\ChangeTicketStatus::handle(?User $actor, Ticket $ticket, TicketStatus $to): Ticket // null actor = system; fires TicketStatusChanged (+TicketResolved)
Tickets\ChangeTicketPriority::handle(User $actor, Ticket $ticket, TicketPriority $to): Ticket // fires TicketPriorityChanged
// T06
Assignment\ClaimTicket::handle(User $agent, Ticket $ticket): Ticket                        // throws TicketAlreadyClaimed
Assignment\AssignTicket::handle(User $actor, Ticket $ticket, User $agent): Ticket          // throws AgentNotEligible
Assignment\AssignTicketAutomatically::handle(Ticket $ticket): ?User
// T07
Conversation\AddReply::handle(User $author, Ticket $ticket, string $body, bool $isInternal, array $files = []): TicketReply // throws TicketClosed
// T11
Imports\StartTicketImport::handle(User $admin, UploadedFile $file): TicketImport
Imports\ImportTicketRow::handle(TicketImport $import, array $row, int $rowNumber): ?Ticket
// T13
Admin\SyncAgentProfile::handle(User $admin, User $agent, array $categoryIds, bool $isAvailable): User
Admin\ChangeUserRole::handle(User $admin, User $user, Role $role): User
```

### 3.6 Events — `app/Events` (T05 creates **all** of them)

All events are `final`, use `Dispatchable` and `SerializesModels`, implement `ShouldDispatchAfterCommit`, and have public readonly constructor properties.

```php
TicketCreated(Ticket $ticket, ?User $actor, bool $imported = false)
TicketUpdated(Ticket $ticket, User $actor, array $changes)                 // ['field' => ['from' => x, 'to' => y]]
TicketAssigned(Ticket $ticket, ?User $actor, User $agent, ?User $previousAgent, AssignmentMethod $method)
TicketStatusChanged(Ticket $ticket, ?User $actor, TicketStatus $from, TicketStatus $to)
TicketResolved(Ticket $ticket, ?User $actor)
TicketPriorityChanged(Ticket $ticket, User $actor, TicketPriority $from, TicketPriority $to)
TicketReplied(Ticket $ticket, TicketReply $reply, User $author)
TicketSlaApproaching(Ticket $ticket)
TicketSlaBreached(Ticket $ticket)
TicketImportFinished(TicketImport $import)
```

### 3.7 Listeners (auto-discovered; owners noted)

| Listener | Owner | Handles | Mode |
|---|---|---|---|
| `RecordTicketActivity` | T05 | every Ticket* event above | sync |
| `QueueAutoAssignment` | T06 | TicketCreated (not imported, if `tickets.auto_assign`) → dispatch `Jobs\AutoAssignTicket` | sync dispatch |
| `ResumeSlaOnCustomerReply` | T07 | none: handled inline in AddReply through ChangeTicketStatus (documented in T07) | — |
| `SendTicketCreatedNotifications`, `SendTicketAssignedNotifications`, `SendTicketRepliedNotifications`, `SendTicketResolvedNotifications`, `SendSlaNotifications`, `SendImportFinishedNotification` | T10 | the matching events | sync listener, queued notifications |

### 3.8 Jobs, commands, schedule

- `Jobs\AutoAssignTicket(int $ticketId)` (T06): `ShouldQueue`, `ShouldBeUnique` (uniqueId = ticketId), queue `default`.
- `Jobs\PrepareTicketImport(int $importId)`, `Jobs\ImportTicketsChunk(int $importId, int $startLine, int $endLine)` (Batchable), `Jobs\FinalizeTicketImport(int $importId)` (T11). Queue `imports`.
- `Console\Commands\ScanSlaCommand` signature `sla:scan` (T09). Scheduled in `routes/console.php` as `Schedule::command('sla:scan')->everyMinute()->withoutOverlapping()->onOneServer();` (T09 owns that line).

### 3.9 Notifications — `app/Notifications` (T10)

`TicketCreatedNotification`, `TicketAssignedNotification`, `TicketRepliedNotification`, `InternalNoteAddedNotification` (database only), `TicketResolvedNotification`, `SlaApproachingNotification`, `SlaBreachedNotification`, `TicketImportFinishedNotification` (database only). All `ShouldQueue`, `->onQueue('notifications')`, `afterCommit()`. The database payload shape is `{ticket_number, title, body, url}`.

### 3.10 Routing

`bootstrap/app.php` (T00) loads:
- `routes/web.php`, then every `routes/web/*.php` inside the `web` middleware group.
- every `routes/api/v1/*.php` with prefix `api/v1`, name prefix `api.v1.` and middleware `api`. Each file applies `auth:sanctum` itself, except `auth.php`.

| File | Owner | Route names (web) | Route names (API, `api.v1.` prefix) |
|---|---|---|---|
| `routes/web/tickets.php`, `routes/api/v1/tickets.php` | T05 | `tickets.index` (`?scope=active\|resolved`), `tickets.create`, `tickets.store`, `tickets.show`, `tickets.update`, `tickets.status`, `tickets.priority` | `tickets.index`, `tickets.store`, `tickets.show`, `tickets.update`, `tickets.status`, `tickets.priority`, `tickets.activities` |
| `routes/web/assignment.php`, `routes/api/v1/assignment.php` | T06 | `tickets.claim`, `tickets.assign` | `tickets.claim`, `tickets.assign` |
| `routes/web/conversation.php`, `routes/api/v1/conversation.php` | T07 | `tickets.replies.store`, `attachments.download` | `tickets.replies.index`, `tickets.replies.store`, `attachments.download` |
| `routes/web/notifications.php`, `routes/api/v1/notifications.php` | T10 | `notifications.index`, `notifications.read`, `notifications.read-all` | same |
| `routes/web/imports.php`, `routes/api/v1/imports.php` | T11 | `admin.imports.index/create/store/show`, `admin.imports.errors.download` | `imports.store`, `imports.show`, `imports.errors.index` |
| `routes/web/dashboard.php`, `routes/api/v1/dashboard.php` | T12 | `dashboard` | `dashboard` |
| `routes/web/admin.php`, `routes/api/v1/admin.php` | T13 | `admin.categories.*`, `admin.holidays.*`, `admin.users.*` (resource, without show) | `categories.index` (any authenticated user), `admin.categories.*`, `admin.holidays.*`, `admin.users.*` |
| `routes/api/v1/auth.php` | T00 | — | `auth.tokens.store` (POST `auth/tokens`, throttle `5,1`), `auth.tokens.destroy` (DELETE `auth/tokens/current`), `auth.me` (GET `me`) |

Tickets are route-bound by `number` (`/tickets/TKT-2026-000001`). For a customer viewing a ticket they don't own, `TicketPolicy::view` returns `Response::denyAsNotFound()` → 404 (TD-012).

### 3.11 Policies (T04) — the methods later tasks call

```php
TicketPolicy: viewAny, view, create, createOnBehalf, update, changeStatus(User, Ticket, ?TicketStatus $to = null),
              changePriority, claim, assign, reply, addInternalNote, viewInternal
TicketReplyPolicy: view(User, TicketReply)
AttachmentPolicy: view(User, Attachment)          // download
CategoryPolicy, HolidayPolicy, UserPolicy, TicketImportPolicy: viewAny, view, create, update, delete (admin capabilities)
DashboardPolicy (Gate 'viewDashboard')            // registered as Gate::define('viewDashboard', ...)
// Public `api.v1.categories.index` (the category picker on the create-ticket form) is authorized with
// TicketPolicy::create: anyone who may create a ticket may list active categories. CategoryPolicy stays admin-only.
```
`changeStatus` with `$to` handles the customer special case: a customer may move their own `Resolved` ticket to `Closed` or `InProgress` (reopen) only.

### 3.12 API Resources — `app/Http/Resources/V1`

| Resource | Owner |
|---|---|
| `UserSummaryResource` (`id, name`), `CategoryResource` | T01 |
| `TicketResource`, `TicketListResource`, `ActivityResource`, `AttachmentResource` | T05 |
| `TicketReplyResource` | T07 |
| `TicketImportResource`, `TicketImportErrorResource` | T11 |
| `NotificationResource` | T10 |
| `HolidayResource`, `AdminUserResource` | T13 |

`TicketResource` fields: `number, subject, description, status{value,label}, priority{value,label}, category, customer, assigned_agent, sla{state, due_at, warning_at, paused_at}, escalation_level, created_at, updated_at, resolved_at, closed_at`, plus `can{...}` when the request has a user.

### 3.13 Inertia pages & shared props

- Shared (`HandleInertiaRequests`, T00, extended in T04): `auth.user{id,name,email,roles[]}`, `auth.can{viewDashboard, manageImports, manageUsers, manageCategories, manageHolidays, createOnBehalf}`, `flash{success,error}`, and `notifications.unread_count`, which T10 fills in through a lazy prop. T00 defines the key as `0`, and T10 replaces the closure in `HandleInertiaRequests`. **T10 is allowed to modify that one closure.**
- Layout: `resources/js/layouts/AppLayout.vue` (T00). Navigation is assembled by `resources/js/config/navigation.ts` (T00) from per-feature modules loaded with `import.meta.glob('./navigation/*.ts', { eager: true })`. Each module default-exports `NavItem[]`, where `type NavItem = { title: string; href: string; icon?: Component; order: number; visible: (page: SharedProps) => boolean }`. Features add **their own file**: `navigation/tickets.ts` (T05), `navigation/dashboard.ts` (T12), `navigation/imports.ts` (T11), `navigation/admin.ts` (T13). No shared file is edited.
- `tickets/Show.vue` (T05) renders the components below, which T05 creates as **stubs**:

  | Stub component | Owner from wave 4 | Props |
  |---|---|---|
  | `components/tickets/AssignmentPanel.vue` | T06 | `ticket: TicketDetail`, `agents: AgentOption[]`, `can: TicketCan` |
  | `components/tickets/ConversationPanel.vue` | T07 | `ticket: TicketDetail`, `replies: ReplyItem[]`, `can: TicketCan` |
  | `components/tickets/AttachmentList.vue` | T07 | `attachments: AttachmentItem[]` |
  | `components/tickets/TicketFilterBar.vue` (on `tickets/Index.vue`) | T08 | `scope`, `filters`, `filterOptions` |

- `TicketController@show` (web, T05) passes props: `ticket` (TicketResource array), `replies` (visible replies with author and attachments, oldest first), `attachments` (visible ticket-level attachments), `activities` (visible, newest first, max 100), `agents` (available agents `{id,name}`, only when `can.assign`), `can` (TicketCan).
- TS types in `resources/js/types/tickets.ts` (T05): `TicketDetail`, `TicketListItem`, `TicketCan`, `ReplyItem`, `AttachmentItem`, `ActivityItem`, `AgentOption`, `Paginated<T>`.

`TicketCan = { update, changeStatus, changePriority, claim, assign, reply, addInternalNote, allowedStatuses: string[] }`

---

## 4. File ownership summary

Files modified by more than one task, and who may touch what:

| File | Created by | Later modifications allowed |
|---|---|---|
| `bootstrap/app.php` | T00 | none (route loading by glob, exception rendering) |
| `app/Http/Middleware/HandleInertiaRequests.php` | T00 | T04 (`auth.can`), T10 (`notifications` closure) |
| `resources/js/config/navigation.ts` | T00 | none (features add `resources/js/config/navigation/<feature>.ts`) |
| `routes/console.php` | T00 | T09 (schedule line) |
| `config/tickets.php` | T03 (`number_prefix`) | T05 (`per_page`), T06 (`auto_assign`) |
| `app/Providers/AppServiceProvider.php` | T00 | T04 (`viewDashboard` gate), T02 registers its own provider in `bootstrap/providers.php` |
| `bootstrap/providers.php` | T00 | T02 (SlaServiceProvider) |
| `components/tickets/{AssignmentPanel,ConversationPanel,AttachmentList}.vue` | T05 (stub) | T06 / T07 (replace) |
| `database/seeders/DemoSeeder.php` | T01 | T14 (polish only) |
| `tests/Support/AuthorizationMatrix.php` (helper: `actingAsRole(Role)`, `expectAccess(string $method, string $uri, int $status)`, and dataset builders) | T04 | none |
| `tests/Feature/Authorization/<Feature>AuthorizationTest.php` | each task for its own routes (T04 creates `TicketPolicyTest`; T05–T13 each create their own file) | none: one file per feature, never shared |
| `docs/API.md`, `docs/TECHNICAL.md`, `README.md` | T14 | — |
| `app/Models/Ticket.php` | T01 (includes `activitiesVisibleTo`) | none |
| `routes/web/dashboard.php` | T00 (placeholder, so the login redirect works) | T12 (replaces it) |
| `routes/web.php` | T00 (includes the role-aware `home` redirect) | none |
| spatie/permission migration | T00 (token abilities need it) | none |
| `app/Http/Requests/Tickets/IndexTicketsRequest.php` | T05 | T08 (`rules()` only) |
| `app/Services/Tickets/TicketListQuery.php` | T05 | T08 (inject `TicketFilter`, replace the `applyFilters()` body) |
| `app/Services/Tickets/TicketPageData.php` | T05 | T08 (index `filterOptions` prop only) |
| `app/Http/Controllers/Web/TicketController.php` | T05 | T08: the one line in `index()` that passes `filterOptions`; nothing else |
| `docs/DEVELOPMENT.md` | T00 | T14 (may link to it) |
| `resources/js/pages/tickets/Index.vue` | T05 | T08 (filter bar props only) |
| `components/tickets/TicketFilterBar.vue` | T05 (stub) | T08 (replaces it) |
| `phpunit.xml` | T00 (suites Unit, Feature, Database, Concurrency; `TICKETS_AUTO_ASSIGN=false`) | none |
| `TECHNICAL_DECISIONS.md` | — | any task: only to record an amendment it was told to make |

---

## 5. Contract notes in task files

Each task file ends with "Contract notes for controller". After the cross-plan review on 2026-10-29:
- Notes marked **Resolved** are settled. Where a resolution changed the contract, it's reflected in §3 and §4 above, in `TECHNICAL_DECISIONS.md` (TD-002, TD-022, TD-044, TD-072, TD-110 amendments) or in the spec.
- Notes **without "Resolved"** are *recorded assumptions and rationale* (for example "a 0→2 escalation jump sends only Breached", "the dashboard's My-tickets strip shows for all staff"). They're accepted as written. At the start of the task, the implementer **verifies each assumption against the merged code**. If one turns out false, the implementer stops and reports (see the escalation rule in §2). They never work around it silently.
- Spec reviewers check a task's open notes as part of its acceptance criteria.
