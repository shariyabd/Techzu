# Support Ticket & SLA Management System — Design Spec

- **Date:** 2026-10-29
- **Status:** Approved (brainstorming session)
- **Source requirements:** [`PRD.md`](../../../PRD.md)
- **Decision log:** [`TECHNICAL_DECISIONS.md`](../../../TECHNICAL_DECISIONS.md) (TD-xxx references below)

---

## 1. Scope & delivery shape

| Item | Decision |
|---|---|
| Framework | Laravel 12, PHP 8.3 |
| UI | Inertia 2 + Vue 3 (Composition API, `<script setup>`, TypeScript), Tailwind, shadcn-vue — Laravel Vue starter kit (TD-002) |
| API | Separate versioned REST JSON API under `/api/v1`, Sanctum personal access tokens (TD-003) |
| Shared logic | Web and API controllers are thin and call the same Actions, FormRequests and Policies (TD-001) |
| Database | MySQL 8 (dev, test, CI) (TD-004, TD-031) |
| Runtime | Config-driven: Laravel Sail (MySQL, Redis, Mailpit) is the primary setup; plain local (MySQL, `database` queue/cache, `log` mail) is also supported (TD-005) |
| API docs | Scramble (OpenAPI generated from FormRequests and Resources), exported to `docs/api/openapi.json` (TD-033) |
| Testing | Pest 3, Larastan level 6, Pint (TD-030) |

Out of scope (YAGNI): multi-tenant setups, per-team calendars, SLA for first response (only resolution SLA), chat or realtime websockets, ticket merging, customer satisfaction surveys.

## 2. Roles & permission matrix (TD-010, TD-011)

Roles are stored as a `role` column backed by a PHP enum: `admin`, `agent`, `customer`. Every rule is enforced by Policies on the server; the UI only hides controls as a convenience.

| Action | Customer | Agent | Admin |
|---|---|---|---|
| Create ticket | ✓ (as self) | ✓ (on behalf of a customer) | ✓ |
| View ticket | own only | all | all |
| Public reply | own only (not on Closed) | all | all |
| Internal note — view/write | ✗ never | all | all |
| See attachments on internal notes | ✗ never | ✓ | ✓ |
| Claim unassigned ticket | ✗ | ✓ (concurrency-safe) | ✓ |
| Update subject/description | own, while Open | assigned to self | all |
| Change status / priority / category | close own Resolved ticket; reopen own Resolved ticket | assigned to self | all |
| Assign / reassign to another agent | ✗ | hand off own ticket only | all |
| Dashboard | ✗ (sees own tickets list) | ✓ | ✓ |
| CSV import, users, categories, holidays, agent config | ✗ | ✗ | ✓ |

## 3. Architecture (TD-001)

```
app/
  Actions/            single-purpose use cases; own transactions; dispatch events
    Tickets/          CreateTicket, UpdateTicket, ChangeTicketStatus, ChangeTicketPriority
    Assignment/       AssignTicket, ClaimTicket, HandOffTicket, AutoAssignTicket(action)
    Conversation/     AddReply, StoreAttachment
    Imports/          StartTicketImport, ImportTicketRow
  Services/
    Sla/              BusinessCalendar (pure), SlaCalculator, HolidayRepository
    Tickets/          TicketNumberGenerator, TicketFilter
    Assignment/       AgentSelector
    Dashboard/        DashboardStats
  Enums/              Role, TicketStatus, TicketPriority, SlaState, ActivityType, ImportStatus
  Events/             TicketCreated, TicketAssigned, TicketReplied, TicketStatusChanged,
                      TicketResolved, TicketPriorityChanged, TicketSlaApproaching, TicketSlaBreached
  Listeners/          RecordTicketActivity (sync), SendTicket*Notification (queued notifications)
  Notifications/      one class per event, via ['mail','database'], ShouldQueue
  Jobs/               AutoAssignTicket, ImportTicketsChunk, FinalizeTicketImport
  Console/Commands/   ScanSlaCommand (sla:scan)
  Exceptions/Domain/  InvalidStatusTransition, TicketAlreadyClaimed, NoEligibleAgent, ...
  Http/
    Controllers/Web/      Inertia controllers
    Controllers/Api/V1/   JSON controllers
    Requests/             shared FormRequests
    Resources/V1/         API Resources (Inertia props reuse them where practical)
  Policies/           TicketPolicy, TicketReplyPolicy, AttachmentPolicy, admin policies
config/sla.php, config/business_hours.php, config/attachments.php, config/tickets.php
routes/web/*.php, routes/api/v1/*.php   (one file per feature, auto-loaded)
resources/js/pages/<feature>/..., resources/js/components/<feature>/...
```

**Rules:**
- Controllers: authorize → validate (FormRequest) → call one Action/Service → return a Resource or an Inertia response. No business logic in controllers.
- Actions are the only code that changes ticket state. Each runs inside `DB::transaction()` when it touches more than one row or table.
- Events implement `ShouldDispatchAfterCommit`.
- Pure services (`BusinessCalendar`) depend only on constructor-injected values. They read no config, use no facades and never call `now()` directly.

## 4. Data model (TD-020…TD-026)

See `docs/ERD.md` (Mermaid, written in T01).

### users
`id, name, email (unique), password, role (string 16), is_available (bool, default true), last_assigned_at (nullable ts), remember_token, timestamps`
Index: `(role, is_available)`.

### categories
`id, name, slug (unique), is_active (bool), timestamps`.

### agent_category
`user_id FK→users cascade, category_id FK→categories cascade`, PK `(user_id, category_id)`, index `(category_id, user_id)`.

### tickets
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| number | varchar(32) unique | `TKT-YYYY-NNNNNN` |
| subject | varchar(255) | |
| description | text | |
| priority | varchar(16) | enum `TicketPriority` |
| status | varchar(32) | enum `TicketStatus` |
| category_id | FK categories, restrict | |
| customer_id | FK users, restrict | "Created by"/owner |
| created_by_id | FK users, restrict | actual creator (an agent may create for a customer) |
| assigned_agent_id | FK users nullable, null on delete | |
| assigned_at | ts nullable | |
| external_reference | varchar(64) nullable unique | CSV de-duplication |
| sla_started_at | ts | start of the SLA clock (created_at, or the original created date on import) |
| sla_due_at | ts | resolution deadline |
| sla_warning_at | ts | "approaching" begins |
| sla_paused_at | ts nullable | set while WaitingForCustomer |
| escalation_level | tinyint unsigned default 0 | 0 none, 1 approaching, 2 breached |
| escalated_at | ts nullable | |
| resolved_at, closed_at | ts nullable | |
| timestamps | | |

Indexes: `(status, sla_due_at)`, `(status, sla_warning_at)`, `(assigned_agent_id, status)`, `(customer_id, created_at)`, `(category_id, status)`, `(priority, status)`, `(created_at)`, FULLTEXT `(subject, description)`.

### ticket_sequences
`year smallint PK, last_number int unsigned, timestamps` (TD-022).

### ticket_replies
`id, ticket_id FK cascade, author_id FK users restrict, body text, is_internal bool, timestamps`. Index `(ticket_id, is_internal, created_at)`.

### attachments
`id, ticket_id FK cascade, reply_id FK nullable cascade, uploaded_by FK users, disk varchar, path varchar (UUID filename), original_name, mime_type, size_bytes int unsigned, is_internal bool, created_at`. Indexes `(ticket_id, is_internal)`, `(reply_id)`.

### ticket_activities
`id, ticket_id FK cascade, actor_id FK users nullable (null = system), type varchar(32) (ActivityType), properties json, created_at`. Index `(ticket_id, created_at)`. Append-only (no `updated_at`).

### holidays
`id, date (unique), name, timestamps`.

### ticket_imports
`id, user_id FK, original_name, path, status (ImportStatus), total_rows, processed_rows, succeeded_rows, failed_rows, batch_id nullable, started_at, finished_at, timestamps`. Index `(user_id, created_at)`.

### ticket_import_errors
`id, ticket_import_id FK cascade, row_number int unsigned, errors json, raw json, created_at`. Index `(ticket_import_id, row_number)`.

### Framework tables
`notifications, jobs, job_batches, failed_jobs, personal_access_tokens, cache, cache_locks, sessions, password_reset_tokens`.

## 5. Ticket number generation (TD-022)

```
DB::transaction:
  INSERT IGNORE INTO ticket_sequences (year, last_number) VALUES (:y, 0)
  SELECT last_number FROM ticket_sequences WHERE year = :y FOR UPDATE
  UPDATE ticket_sequences SET last_number = last_number + 1 WHERE year = :y
  return sprintf('%s-%d-%06d', prefix, y, n)   // padding is a minimum width; grows beyond 6 digits
```
- The prefix comes from `config('tickets.number_prefix')`; the year is taken in the business timezone.
- Runs inside the `CreateTicket` transaction, so a rollback also rolls back the counter. A gap only appears if a commit fails, which is acceptable.
- `tickets.number` has a unique index as the final guard.

## 6. Status state machine (TD-040)

```
Open ───────► InProgress ◄──────► WaitingForCustomer
  │               │                      │
  └──────────────►├──► Resolved ◄─────────┘
                  │       │   ▲
                  │       ▼   │ (reopen → InProgress)
                  │     Closed (terminal)
```
- `TicketStatus::allowedTransitions()` is the single source of truth.
- `ChangeTicketStatus` rejects any transition not listed there with `InvalidStatusTransition` (422).
- Side effects:
  - `→ WaitingForCustomer`: pause the SLA.
  - `WaitingForCustomer →` (any status): resume the SLA.
  - `→ Resolved`: set `resolved_at`.
  - `→ Closed`: set `closed_at`.
  - Reopen: clear `resolved_at`; the SLA keeps its original deadline. Being Resolved does not pause it retroactively. This is documented in TD-042.
- A customer reply on a `WaitingForCustomer` ticket triggers `ChangeTicketStatus(InProgress)` with the system as actor.
- Replies are rejected on `Closed` tickets.
- Active list = `Open, InProgress, WaitingForCustomer`. Resolved list = `Resolved, Closed`.

## 7. SLA & business hours (TD-041, TD-042, TD-043)

**config/sla.php**
```php
'resolution_minutes' => ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120],
'warning_threshold_percent' => 75,
```
**config/business_hours.php**
```php
'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Dhaka'),
'week' => [ 'sunday' => [['09:00','18:00']], ..., 'thursday' => [['09:00','18:00']],
            'friday' => [], 'saturday' => [] ],
```

**BusinessCalendar** (pure; constructed with the week schedule, the timezone and a set of holiday dates):
- `addBusinessMinutes(CarbonImmutable $from, int $minutes): CarbonImmutable`
- `businessMinutesBetween(CarbonImmutable $a, CarbonImmutable $b): int`
- `isBusinessTime(CarbonImmutable $t): bool`

Both methods walk working windows one window at a time. Inputs and outputs are UTC; the calculation is done in the business timezone. A safety cap (366 days of lookahead) throws if the schedule has no working windows at all.

**SlaCalculator:**
- `initialDeadlines(TicketPriority, CarbonImmutable $start): SlaDeadlines{dueAt, warningAt}`. `warningAt = addBusinessMinutes(start, floor(minutes * pct/100))`.
- `onPriorityChange(Ticket, TicketPriority)`: recompute from `sla_started_at`. If the ticket is paused, recompute and then re-apply the pause semantics.
- `pause(Ticket, now)`: set `sla_paused_at`.
- `resume(Ticket, now)`: `remaining = businessMinutesBetween(paused_at, due_at)` → `due_at = add(now, remaining)`. Recompute `warning_at` the same way, flooring its remaining time at 0. Clear `paused_at`.

**SLA state** (`SlaState` enum, computed by query scopes in `TicketFilter` and as an accessor on `Ticket`):
- `paused`: `sla_paused_at IS NOT NULL`
- `stopped`: status is Resolved or Closed
- `breached`: `now ≥ sla_due_at`
- `approaching`: `sla_warning_at ≤ now < sla_due_at`
- `within`: otherwise

**Required PRD example:** Critical ticket created Thursday 17:00 Asia/Dhaka → due Sunday 10:00 Asia/Dhaka.

## 8. Escalation (TD-044)

- `sla:scan` is scheduled `everyMinute()->withoutOverlapping()->onOneServer()`.
- Two passes over active, unpaused tickets, each streamed by id in batches of 500:
  1. `escalation_level = 0 AND sla_warning_at <= now AND sla_due_at > now` → conditional `UPDATE ... SET escalation_level = 1 WHERE id IN (...) AND escalation_level = 0`. Dispatch `TicketSlaApproaching` only for the affected ids (select-then-update with the conditional `WHERE` re-checked).
  2. `escalation_level < 2 AND sla_due_at <= now` → set level 2 and `escalated_at`, then dispatch `TicketSlaBreached`.
- Listeners record the activity (`sla_approaching`, `escalated`) and notify:
  - Approaching → the assigned agent, or all admins if the ticket is unassigned.
  - Breached → the assigned agent and all admins.
- When a priority change or an SLA resume moves the deadline later, `escalation_level` is reset to match the new state, so the ticket can escalate again.

## 9. Assignment (TD-050, TD-051, TD-052)

- **Manual assign** (admin, or an agent handing off their own ticket): `AssignTicket`. It checks that the target is an available agent, runs in a transaction, and fires `TicketAssigned`.
- **Claim** (`ClaimTicket`): `UPDATE tickets SET assigned_agent_id=:me, assigned_at=now, status=IF(status='open','in_progress',status) WHERE id=:id AND assigned_agent_id IS NULL`. Affected rows = 0 → `TicketAlreadyClaimed` (409). Affected rows = 1 → fire `TicketAssigned`.
- **Auto assign:**
  - `TicketCreated` → the queued `AutoAssignTicket` job, only when `config('tickets.auto_assign')` is true.
  - `AgentSelector::selectFor(Ticket)` considers available agents linked to the ticket's category, ordered by active ticket count ascending, then `last_assigned_at` ascending (nulls first), then id.
  - Inside a transaction it locks the candidate agent rows (`lockForUpdate`) and assigns with the same conditional `UPDATE` as claim. If the ticket was already claimed, the job exits quietly.
  - No eligible agent → log it; the ticket stays in the unassigned queue.
- Updates `users.last_assigned_at` on every assignment.

## 10. Conversation & attachments (TD-060, TD-061)

- `AddReply(ticket, author, body, isInternal, files[])`: a customer's `isInternal` is forced to false. The reply and its attachments are created in one transaction, and the files are stored before the commit.
  - Commit fails → delete the stored files (compensation).
  - Fires `TicketReplied`.
- Uploads go to the private `local` disk (configurable through `ATTACHMENTS_DISK`, S3-ready) under `attachments/{ticket_id}/{uuid}.{ext}`.
- Validation: `config/attachments.php` holds the allowed MIME types and extensions (pdf, png, jpg, jpeg, gif, txt, csv, doc(x), xls(x), zip), a max of 10 MB per file and 5 files per reply. Validation uses `File::types()->max()`. The original filename is stored only as metadata.
- Download: `GET /attachments/{attachment}` (web) and `/api/v1/attachments/{attachment}` → `AttachmentPolicy::view`, which checks that the user can view the ticket and that `!is_internal || user is staff`. The file is streamed with `Storage::download()`, `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. No public URLs.

## 11. Notifications (TD-070, TD-071)

| Event | Recipients | Channels |
|---|---|---|
| TicketCreated | customer (confirmation); admins if not auto-assigned | mail, database |
| TicketAssigned | assigned agent; customer | mail, database |
| TicketReplied (agent, public) | customer | mail, database |
| TicketReplied (customer) | assigned agent, or admins if unassigned | mail, database |
| TicketReplied (internal) | assigned agent, if they are not the author | database only, never the customer |
| TicketResolved | customer | mail, database |
| TicketSlaApproaching | agent, or admins | mail, database |
| TicketSlaBreached | agent + admins | mail, database |

All notifications implement `ShouldQueue`, run on the `notifications` queue and use `afterCommit()`. The actor never notifies themselves.

## 12. Search & filtering (TD-080)

`TicketFilter::apply(Builder, array $validated)` supports these filters:
- `number` (exact or prefix)
- `q` (FULLTEXT `MATCH(subject, description) AGAINST (? IN BOOLEAN MODE)`; falls back to `LIKE` on non-MySQL drivers)
- `status[]`, `priority[]`, `category_id`, `assigned_agent_id`, `unassigned=1`, `customer_id`
- `created_from` / `created_to`
- `sla_state`

Sort uses a whitelist (`created_at`, `sla_due_at`, `priority`, `updated_at`). The validated request goes through `IndexTicketsRequest`. Paginated: `paginate(per_page ≤ 100, default 20)` for the web, and the same for the API with Resource pagination meta. Visibility scope is applied first: `Ticket::visibleTo($user)`, where customers see their own tickets and staff see all.

Eager loading: `with(['category:id,name', 'customer:id,name', 'assignedAgent:id,name'])`, plus `withCount('replies')`. A query-count test enforces this.

## 13. CSV import (TD-090)

1. Admin uploads a CSV (≤ 50 MB). `StartTicketImport` stores the file, creates `ticket_imports(status=pending)` and dispatches `PrepareTicketImport`.
2. `PrepareTicketImport` streams the file with `LazyCollection` and `SplFileObject`, validates the header, counts rows, splits the work into chunks of 500 row ranges (by line offset) and dispatches a `Bus::batch` of `ImportTicketsChunk` jobs with `then`/`finally` → `FinalizeTicketImport`.
3. `ImportTicketsChunk` reads only its range and validates each row with `Validator` against `ImportTicketRowRules`:
   - Columns: `external_reference?, subject, description, priority, category (slug), customer_email, status?, created_at?`.
   - Valid rows go through `ImportTicketRow`, which reuses the number generator and the SLA calculator, with `sla_started_at = created_at` if one is given. Each row is wrapped in its own transaction, so one bad row never rolls back the chunk.
   - Invalid or duplicate (`external_reference` already exists) rows go to `ticket_import_errors`.
   - Counters are updated with atomic `increment()` calls.
4. Imported tickets do **not** fire per-ticket customer notifications (they would flood inboxes). One `TicketImportFinished` database notification goes to the importing admin.
5. The UI shows progress (polling the import record), the error table paginated, and a download of the errors as CSV (streamed).

## 14. Dashboard (TD-100)

`DashboardStats::for(User)` returns:
- totals: open (active), unassigned, critical (active), breached, approaching, resolved (last 30 days)
- by priority (active)
- by agent (active count per agent, plus breached per agent)

These come from about 3 grouped aggregate queries using conditional `SUM(CASE…)`, all backed by the indexes above. Cached for 60 s per role scope. Agents see the same view as admins plus a "My tickets" strip.

## 15. Activity history (TD-026)

The `RecordTicketActivity` listener runs synchronously after commit, on every domain event. `ActivityType` values:
- `created`, `assigned`, `claimed`, `unassigned`
- `status_changed`, `priority_changed`, `category_changed`, `updated`
- `replied`, `internal_note`
- `sla_paused`, `sla_resumed`, `sla_approaching`, `escalated`
- `imported`

`properties` holds `{from, to, agent_id, ...}`. The ticket timeline renders human-readable lines through `ActivityType::describe()`. Customers never see `internal_note` activities.

## 16. Error handling (TD-110)

- Domain exceptions extend `DomainException` and carry an HTTP status and a stable `code`.
- They are rendered in `bootstrap/app.php`:
  - API (`expectsJson`): `{ "message": ..., "code": "ticket_already_claimed" }` with the right status.
  - Web: `back()->with('error', message)`.
- Validation follows Laravel defaults: 422 JSON for the API, Inertia errors bag for the web.
- Authorization failures → 403. Model-not-found → 404; for tickets a customer can't view, return **404, not 403**, so ticket existence isn't revealed (TD-012).
- Jobs: `tries=3`, `backoff=[10,60,300]`, and `failed()` logs. Import chunk failures mark the import `failed` with a message.

## 17. Security (TD-120)

- Server-side Policies on every route, registered through model-policy auto-discovery. Policy coverage is enforced by the matrix test.
- Throttling: `throttle:api` (60/min) on the API, a stricter limit on login and token creation, and uploads limited to 20/min.
- Mass assignment: explicit `$fillable`. `role`, `assigned_agent_id` and the SLA columns are never fillable from requests.
- Files: private disk, MIME sniffing through `File::types`, random filenames, no execution path.
- Internal-note leakage is guarded in three layers: query scope, Resource, and notification listener. Each layer has a dedicated test.
- Sanctum tokens get abilities by role. `HandleInertiaRequests` shares only the user's id, name, role and their own permission booleans.

## 18. Testing strategy (TD-030)

Priority order (critical risk first):
1. `BusinessCalendar` / `SlaCalculator` unit tests: datasets covering the PRD example, a weekend jump, a holiday, starting before or after hours, an exact boundary, multi-day spans, pause/resume, and a priority change.
2. Authorization matrix: datasets of role × action × ownership, run against **web and API** routes, including the internal-note and attachment leakage checks.
3. Concurrency: claim race (two DB connections, a real conditional update) → exactly one winner and a 409 for the loser. Parallel ticket number generation is unique.
4. Auto-assignment selection: category filter, availability, least load, tie-break.
5. Status state machine: allowed and forbidden transitions, SLA pause/resume side effects.
6. Escalation: thresholds, idempotency (running the scan twice → one notification), reset after deadline extension.
7. CSV import: valid, invalid and duplicate rows; counters; error report; memory-safe streaming (a 10k-row fixture generated in the test).
8. Notifications: the right recipients and channels; `Notification::fake()`; internal notes never reach customers.
9. Query efficiency: `DB::enableQueryLog` count assertions on the ticket list and the dashboard.
10. Feature smoke tests for each API endpoint (status codes, Resource shape).

## 19. Deliverables

- Application code (web + API)
- `TECHNICAL_DECISIONS.md`
- `docs/ERD.md` (Mermaid)
- `docs/API.md`, plus `docs/api/openapi.json` (Scramble export) and a live `/docs/api`
- `docs/TECHNICAL.md` (architecture, flows, how to run, queue/scheduler operations)
- `README.md` (setup for Sail and local, demo credentials, test commands)
- `CLAUDE.md` (guardrails for subagents and contributors)
- `docs/plans/` (task-by-task implementation plan)
