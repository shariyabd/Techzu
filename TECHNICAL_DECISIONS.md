# Technical Decisions

This document records every significant technical decision made for the Support Ticket & SLA Management System. For each one it records **what** was decided, **why**, which **alternatives** were considered and why they were rejected, and the **trade-offs** we accepted.

- Requirements: [`PRD.md`](PRD.md)
- Design spec: [`docs/DESIGN.md`](docs/DESIGN.md)
- Status values: **Accepted** (in force), **Superseded by TD-xxx**, **Amended** (with a dated note)

Guiding principle (from the PRD): *use Laravel features where they are appropriate, not for the sake of using them.* Every decision below should be explainable in one sentence; anything that can't be explained that way was cut.

---

## Index

| Area | Decisions |
|---|---|
| Architecture & stack | TD-001 – TD-005 |
| Authorization & security model | TD-010 – TD-012, TD-120 |
| Database | TD-020 – TD-026 |
| Testing & tooling | TD-030 – TD-034 |
| Ticket lifecycle & SLA | TD-040 – TD-045 |
| Assignment & concurrency | TD-050 – TD-053 |
| Conversation & storage | TD-060 – TD-061 |
| Events, notifications, queues | TD-070 – TD-072 |
| Search | TD-080 |
| CSV import | TD-090 |
| Dashboard & performance | TD-100 – TD-101 |
| Error handling | TD-110 |
| Configuration | TD-130 |
| Development process | TD-140, TD-141 |

---

## Architecture & stack

### TD-001 — Business logic in Actions + Services, with thin controllers shared by the Web and API front ends
**Status:** Accepted

**Context.** The app has two front ends: the Inertia UI and a separate REST API. Both must apply identical rules (assignment, SLA, status transitions, authorization).

**Decision.**
- Single-purpose **Action** classes (`app/Actions/...`) are the only code that changes domain state. Each owns its transaction and dispatches domain events.
- **Services** hold reusable logic that isn't a use case: `BusinessCalendar`, `SlaCalculator`, `TicketNumberGenerator`, `AgentSelector`, `TicketFilter`, `DashboardStats`.
- Controllers in `Http/Controllers/Web` and `Http/Controllers/Api/V1` do only four things: authorize, validate (shared FormRequests), call one Action or Service, and return an Inertia response or an API Resource.

**Why.**
- One source of truth per rule. The web and API cannot drift apart.
- Actions can be unit or feature tested without going through HTTP.
- The folder layout maps one-to-one onto the plan's tasks, which keeps parallel work isolated.

**Alternatives rejected.**
- *Fat controllers / fat models.* Fastest to write, but the rules would have to be written twice (web and API) or the controllers would call each other. Models would become god objects.
- *Full DDD / hexagonal (repositories, domain entities, ports and adapters).* Gives isolation, but works against Eloquent and adds a lot of ceremony (repository interfaces with one implementation each) without benefit at this scale. The PRD explicitly warns against patterns for their own sake.
- *A single `TicketService` with many methods.* Becomes a god class. Single-purpose Actions keep dependencies explicit per use case.

**Trade-offs.** More files than a fat-controller app. Developers must know that "state changes go through Actions"; this rule is enforced by convention and code review (see CLAUDE.md), not by the framework.

---

### TD-002 — Inertia 3 + Vue 3 (TypeScript) via the official Laravel Vue starter kit
**Status:** Accepted

**Decision.** Use the Laravel 13 Vue starter kit (`laravel/vue-starter-kit:dev-main`): Inertia 3, Vue 3 Composition API with `<script setup lang="ts">`, Tailwind, and shadcn-vue components.

**Why.**
- Server-side routing and authorization stay in Laravel. Inertia pages receive props that the server has already authorized, so there's no second auth layer in the client.
- No separate SPA build or deploy, and no CORS.
- The starter kit provides authentication screens, layout and tooling that are already vetted.
- Vue's single-file components keep the many CRUD forms short.

**Alternatives rejected.**
- *React + Inertia.* Equally viable; Vue was chosen for team preference and less form boilerplate.
- *Blade/Livewire.* Simpler, but the requirement asked for a modern SPA-feel UI.
- *A standalone SPA consuming the API.* Doubles the auth surface (token storage in the browser) and the deployment pipeline.

**Starter-kit dependencies (amended 2026-10-29).** The kit brings `inertia-laravel`, `laravel/wayfinder` (typed route helpers for TypeScript) and `laravel/tinker`; these stay.

**Fortify removed (amended 2026-10-29, owner decision; task T00a).**
- The kit's `laravel/fortify` and everything that came with it are removed: two-factor auth, passkeys, email verification, password reset, password confirmation, and the profile/security/appearance settings pages. So are the validation-rule traits in `app/Concerns` that existed only to share rules with Fortify's action classes.
- Authentication is plain Laravel:
  - `LoginController` (`Auth::attempt`, session regenerate, logout)
  - `RegisterController` with a `RegisterCustomer` Action
  - one FormRequest per form, each holding its own `rules()`
  - Sanctum tokens for the API (TD-003), unchanged
- **Why:** the PRD asks for none of those features, and says not to use components for their own sake. Session login plus self-registration is three small classes.
- **Alternatives rejected:** *keeping Fortify with most features disabled.* It still brings its provider, its action classes and the rule traits, which is indirection for two forms.

**Password reset added back; email verification stays out (amended 2026-10-29, owner decision; plan P2).**
- Forgot/reset password uses Laravel's built-in Password broker and the `password_reset_tokens` table. It has one small `PasswordResetController` and its FormRequests, with no Fortify.
- Mail uses the `log` mailer (TD-005), so the reset link appears in `storage/logs/laravel.log`. Switching to SMTP is only a matter of setting `MAIL_MAILER`.
- Email verification is omitted. The demo has no SMTP, seeded accounts are trusted, and the PRD doesn't ask for it. This limitation is documented in the README.
- Roles are fixed (`Admin`, `Agent`, `Customer`). Admins change a user's role through one Action. Permissions are defined in code (`Permission` enum + `RolesAndPermissionsSeeder`).
- **Roles are fixed; the role → permission matrix is admin-editable (amended 2026-10-30, owner decision; see TD-010).** Internal notes stay out of reach for the customer role through a hard guard, so an editable matrix cannot leak them.

**Moved to Laravel 13 (amended 2026-10-29, before any code was written).**
- The only kit tag, v1.0.2 (Feb 2025), is the old Laravel 12 kit without Fortify or Wayfinder.
- The maintained kit is `dev-main`, on Laravel 13 and Inertia 3.
- Laravel 12 is in security-fix-only support, ending around Feb 2027.
- Local PHP is 8.3, so Pest stays on 4 and activitylog on 4.12. Pest 5 and activitylog 5 need PHP 8.4.
- spatie/permission moves to ^8.3. Its v7/v8 breaking changes (renamed event and command classes, type hints) don't affect code that goes through Policies and `can()`. API tokens (TD-003) are issued separately and don't go through Fortify's two-factor flow. This is documented as a known limitation.

**Trade-offs.** It's a JS build step plus a second language in the repo. Frontend tests are limited to type-checking and linting. Behavior is tested at the HTTP/Inertia level (`assertInertia`), because all the rules live on the server.

---

### TD-003 — Separate versioned REST API (`/api/v1`) authenticated with Sanctum personal access tokens
**Status:** Accepted

**Decision.**
- API routes live under `/api/v1/...`. They're authenticated with Sanctum bearer tokens (issued at `POST /api/v1/auth/tokens`) and return JSON through `JsonResource` classes in `Http/Resources/V1`.
- Errors use a consistent `{message, code, errors?}` shape.

**Why.**
- Sanctum is Laravel's first-party, lightweight token system.
- Versioning in the path lets clients pin a contract.
- Resources decouple the database schema from the API contract and give us one place to hide fields. Hiding `is_internal` content from customers happens there as a second layer of defense.

**Alternatives rejected.**
- *Passport / OAuth2.* There are no third-party clients and no delegated-authorization need, so it's heavy for no gain.
- *JWT packages.* Not first-party, and tokens can't be revoked without extra infrastructure.
- *Reusing the web controllers with `wantsJson()` branching.* Mixes two contracts in one class and makes the API's responses depend on the UI.

**Trade-offs.** Two controller sets. Mitigated by TD-001: the controllers are about 10 lines each and call the same Actions.

---

### TD-004 — MySQL 8 as the only production database
**Status:** Accepted

**Why.**
- Available on the local MySQL 8 server and in CI.
- Supports what the design relies on: `FULLTEXT` indexes (TD-080), `SELECT … FOR UPDATE` row locks and `INSERT IGNORE` (TD-022, TD-051), JSON columns (activity properties), and conditional aggregates (TD-100).

**Alternatives rejected.**
- *PostgreSQL.* Technically excellent (better full-text search, partial indexes), but not installed in the target environment. No feature in this system needs it.
- *SQLite.* No real row-level locking, and different FULLTEXT and JSON behavior. Unsuitable for proving the concurrency guarantees.

**Trade-offs.**
- A few queries use MySQL-specific syntax: `MATCH … AGAINST` and `INSERT IGNORE`. These are isolated in `TicketFilter` and `TicketNumberGenerator`, each with a portable fallback where practical.
- MySQL has no partial indexes, so the "active tickets" indexes are composite indexes led by `status`.

---

### TD-005 — Config-driven runtime: local machine during development, Docker packaging last
**Status:** Accepted (amended 2026-10-29: Sail removed by owner decision)

**Decision.**
- Every infrastructure choice stays an `.env` driver switch: `QUEUE_CONNECTION`, `CACHE_STORE`, `MAIL_MAILER`, `ATTACHMENTS_DISK`.
- During development the app runs on the owner's machine with the two standard commands `php artisan serve` and `npm run dev` (amended 2026-10-29, owner decision: Apache dropped):
  - system **MySQL 8** (`127.0.0.1:3306`), with the databases `techzu`, `techzu_testing` and `techzu_testing_agent1..8`;
  - `QUEUE_CONNECTION=sync` locally, so queued work runs inline without a worker. `composer run dev` with `QUEUE_CONNECTION=database` plus `php artisan schedule:work` is the realistic asynchronous setup, documented in the README;
  - the `database` cache and session, and the `log` mailer.
- **No Sail or Docker in T00–T14.** Containerisation (Dockerfile/compose for app, MySQL, queue worker and scheduler) is a separate **final task after T14**, written when the owner asks for it.

**Why.**
- The owner already runs MySQL locally, and `php artisan serve` needs no web-server setup. One database server means no duplicate databases, no port clashes (the earlier Sail plan collided with existing containers on 3306, 3307, 6379 and 8025), and no extra disk use.
- The code never depends on Redis-only features (locks use the database, TD-051), so the `database` drivers lose nothing for correctness.
- Packaging last means it's written against the finished app instead of being maintained through every task.

**Alternatives rejected.**
- *Sail from T00.* It duplicates the owner's MySQL, needs Docker running for every task, and clashed with existing containers.
- *Apache with a PHP 8.3-FPM vhost.* It works, but needs sudo setup that `php artisan serve` doesn't (dropped 2026-10-29).

**Trade-offs.**
- Real email delivery isn't visible; `log` mail writes to `storage/logs`.
- Someone without a local MySQL waits for the final Docker task, or sets up the same two services by hand.

---

## Authorization & security model

### TD-010 — spatie/laravel-permission for roles and capabilities, combined with Laravel Policies for ownership rules
**Status:** Accepted (amended 2026-10-29: originally "role enum column, no package"; changed after review)

**Decision.** Authorization has two layers, each with one job:
1. **spatie/laravel-permission: *what kind of thing* a user may do.**
   - Roles: `admin`, `agent`, `customer`. Role names are referenced through the `App\Enums\Role` enum, never as string literals.
   - Capabilities are named `<resource>.<action>[.<scope>]` and referenced through `App\Enums\Permission`:
     - tickets: `tickets.create`, `tickets.create.on_behalf`, `tickets.view.any`, `tickets.view.own`, `tickets.reply`, `tickets.notes.internal`, `tickets.claim`, `tickets.update.any`, `tickets.update.assigned`, `tickets.update.own`, `tickets.assign.any`, `tickets.handoff.own`
     - admin areas: `dashboard.view`, `imports.manage`, `users.manage`, `categories.manage`, `holidays.manage`
   - The role → permission mapping is defined in one place, `Permission::forRole()`, and `RolesAndPermissionsSeeder` applies it (idempotent; it runs in deploys and in tests). The matrix is fixed and read-only: there is no runtime editing.
2. **Policies: *on which record*.**
   - `TicketPolicy`, `TicketReplyPolicy`, `AttachmentPolicy` and the admin policies combine a capability with ownership or relationship facts. For example:
     ```php
     public function changeStatus(User $user, Ticket $ticket): bool
     {
         return $user->can(Permission::TicketsUpdateAny->value)
             || ($user->can(Permission::TicketsUpdateAssigned->value)
                 && $ticket->assigned_agent_id === $user->id);
     }
     ```
   - Controllers and routes check only Policies (`authorize`, `can:` middleware). They never call `hasRole()` directly, so no rule bypasses the Policies.

**Why.**
- A standard, widely recognized package handles role and permission storage, caching and assignment, so we don't hand-roll it.
- Capabilities are named and centralised, so a role's mapping changes in one place.
- Ownership rules (own ticket, assigned to me, not internal) remain expressible. No permission package can model these on its own, which is why Policies remain the decision point.
- Sanctum token abilities can mirror the permission names (TD-003).

**Alternatives rejected.**
- *Role enum column plus Policies only.* Fewer moving parts and was the original choice, but permissions would be hard-coded per role, with no runtime flexibility, and we'd re-implement what the package gives us for free.
- *Permissions-only checks without Policies.* Can't express ownership, and checks would scatter across controllers.
- *Gates in a service provider.* Policies group the rules per model and are auto-discovered.

**Trade-offs.**
- Two concepts to understand (permission and policy). Mitigated by the strict rule that the Policy is always the entry point.
- Permission checks rely on the package's cache. Tests must seed `RolesAndPermissionsSeeder` and reset the cache (`PermissionRegistrar::forgetCachedPermissions()`), which happens in a shared Pest `beforeEach`.
- Finding agents means joining through `model_has_roles` (indexed by the package). Eligible-agent queries go through a single `User::scopeAvailableAgents()` scope.
- The matrix is not editable at runtime, so the seeded mapping is always the one the matrix test runs against.

**The role → permission matrix becomes admin-editable (amended 2026-10-30, owner decision).**
- Admins who hold `users.manage` may change which permissions each seeded role has, through one admin Action (`UpdateRolePermissions`), from the web and the API. The matrix page loses its read-only marks and becomes checkboxes.
- What stays fixed: the roles themselves, the `Permission` enum (new permission names still go into `RolesAndPermissionsSeeder` only), and what a Role *label* means.
- Two hard guards in the Action: the customer role can never receive `tickets.notes.internal` (the internal-note leak boundary, TD-120), and the admin role can never lose `users.manage` (no lockout of permission management).
- Changes take effect immediately for every signed-in user because the Action resets the spatie permission cache.
- Sanctum token abilities are not re-issued after a matrix change. Nothing checks `tokenCan()`; Policies resolve live permissions through the package, so abilities stay informational (TD-003).

**Alternatives rejected (for the amendment).**
- *Editing the user ↔ permission relation directly.* Bypasses roles, makes the matrix test meaningless, and is the leak-prone design TD-002 originally avoided.
- *Editable list of new permission names.* Arbitrary permission names could collide with policy checks; permissions stay a code-defined enum.

---

### TD-011 — Permission matrix
**Status:** Accepted

| Action | Customer | Agent | Admin |
|---|---|---|---|
| Create ticket | as self | on behalf of a customer | ✓ |
| View ticket | own only | all | all |
| Public reply | own only (not Closed) | all | all |
| Internal notes (view/write) | never | all | all |
| Claim unassigned ticket | ✗ | ✓ | ✓ |
| Update subject/description | own, while Open | assigned to self | all |
| Change status / priority / category | close or reopen own Resolved | assigned to self | all |
| Assign to another agent | ✗ | hand off own ticket | all |
| Dashboard | ✗ | ✓ | ✓ |
| CSV import, users, categories, holidays | ✗ | ✗ | ✓ |

**Why.** This mirrors common help-desk practice (Zendesk, Freshdesk). Agents can see the whole queue and contribute replies and internal notes anywhere, so they can collaborate. *Changing the state* of a ticket is restricted to its owner or an admin, so accountability for a ticket stays clear.

**Alternatives rejected.**
- *Agents see only their own and unassigned tickets.* Stricter, but blocks collaboration and handover.
- *Agents have full access to every ticket.* Ownership becomes meaningless, and the authorization layer has little to enforce.

**Trade-offs.** Agents can read any customer's tickets. That's acceptable for a single-company support desk, and it's documented here as an explicit choice.

---

### TD-012 — Return 404 (not 403) for tickets a customer may not view
**Status:** Accepted

**Decision.**
- Ticket queries start from `Ticket::visibleTo($user)`. When a customer may not view a ticket, `TicketPolicy` denies the request as not found (`Response::denyAsNotFound()`), so another customer's ticket returns **404**. Route binding is not what produces the 404.
- Staff actions forbidden by a Policy return **403**.

**Why.** A 403 confirms that `TKT-2026-000123` exists, which leaks information and enables enumeration. A 404 reveals nothing.

**Alternatives rejected.** *A uniform 403.* Simpler, but reveals that the resource exists.

**Trade-offs.** It's slightly less obvious to debug. Tests assert the exact status codes for each role.

---

### TD-120 — Security hardening
**Status:** Accepted

| Concern | Measure | Proven by |
|---|---|---|
| Server-side authorization | Every route goes through a Policy (`authorize()` / `can` middleware). The matrix test fails when a route is missing coverage. | `SecurityChecklistTest`: *requires authentication on every route that is not public by design*; *keeps guest-only routes to sign-in, registration and password reset*; *answers 403 to agents and customers on every parameterless admin route*. Feature matrices: `TicketRoutesAuthorizationTest`, `AuthorizationMatrixTest`. |
| Internal-note leakage | Three independent layers, each tested: the query scope excludes internal replies for customers, the Resource omits them, and the notification listeners never notify customers about internal notes. | `TicketReplyTest` (*hides internal replies from customers and shows everything to staff*); `TicketReadLayerTest` (*loads only visible replies and attachments for the show page*); `InternalNoteLeakageTest` (*never sends a customer internal content from any page or endpoint*, *refuses to serialize an internal reply for a customer even when a query forgets visibleTo*); `NotificationRecipientsTest` (*sends the customer nothing at all for an internal note*). |
| Mass assignment | Explicit `$fillable`. Roles and permissions are assigned only through admin Actions (`syncRoles`); `assigned_agent_id`, SLA columns, `escalation_level` and `number` are never fillable from requests. They're set only by Actions. | `SecurityChecklistTest`: *ignores privileged fields injected into ticket creation*. `RegisterTest`: *ignores any role sent with the registration form*. `TicketTest`: *never mass-assigns system-controlled ticket columns*. |
| File uploads | Validated MIME type and extension (`File::types`), size limits, random UUID filenames, private disk, streamed downloads with `nosniff` and `Content-Disposition: attachment`. No public URLs (TD-061). | `SecurityChecklistTest`: *rejects a PHP script disguised as a PDF by its content*. `AttachmentStorerTest`: *stores files privately under a uuid name and records metadata*. `ReplyEndpointsTest`: *rejects disallowed or oversized files without storing anything*. `AttachmentDownloadTest`: *streams an attachment as a download with nosniff*. |
| Rate limiting | `throttle:api` (60/min), stricter limits on login and token issuance, and an upload throttle. | `SecurityChecklistTest`: *runs every api/v1 route through the throttled api group*; *throttles every route that accepts uploads*. `LoginTest`: *throttles login after five attempts per minute*. `AuthTokenTest`: *throttles token issuance to five attempts per minute*. |
| Tokens | Sanctum tokens have abilities mirroring the user's permission names (TD-010), and `/auth/tokens/current` can revoke them. | `AuthTokenTest`: *issues a token whose abilities mirror the user permission names*; *revokes only the current token*. |
| Inertia props | Only explicitly selected fields are shared. Never whole models with hidden columns. | `InertiaSharedPropsTest`: *shares only whitelisted user fields, role names, placeholder abilities, flash and the unread count*. |
| Search input | Parameter binding everywhere. FULLTEXT boolean-mode operators in the input are sanitized. Sort columns come from a whitelist. | `FulltextSearchTermTest`: *turns user input into a safe boolean-mode expression*; `TicketKeywordSearchTest`: *neutralises boolean operators and unbalanced quotes typed by the user*; `TicketIndexFilteringTest`: *rejects a sort column outside the whitelist*. |
| 404 not 403 (TD-012) | Policies deny a customer as not found when the ticket is not theirs, so existence is never confirmed. | `ConversationRoutesAuthorizationTest`: *enforces the conversation matrix* (the customer-on-another-customer's-ticket rows answer 404). |
| CSRF / XSS | Laravel CSRF for the web. Vue escapes output by default, and `v-html` is forbidden by the guardrails. | CSRF: framework default for the `web` group; not re-tested. XSS: `SecurityChecklistTest`: *never renders raw HTML in Vue*. |

---

## Database

### TD-020 — Enums stored as `varchar`, cast to PHP backed enums
**Status:** Accepted

**Decision.** `status` and `priority` are `varchar(16|32)` columns cast to backed PHP enums. Role names, permission names and activity event types aren't columns we own (they're stored by the spatie packages), but they too are referenced only through backed enums (`Role`, `Permission`, `ActivityType`), never as string literals.

**Why.**
- Adding a value (for example a new status) is a code change, not an `ALTER TABLE` that rewrites a large tickets table.
- Values stay readable in SQL.
- PHP enums give us type safety, labels and behavior (transitions, colors).

**Alternatives rejected.**
- *MySQL native `ENUM`.* Schema changes on every new value, ordering quirks, and poor portability.
- *Lookup tables (`statuses`).* Joins on every list for values that are part of the code's logic anyway. The state machine can't be data-driven safely.
- *tinyint codes.* Unreadable in SQL and easy to get wrong.

**Trade-offs.** A few bytes more per row than tinyint. The database can't enforce valid values (no CHECK constraint), so only the casts validate them.

---

### TD-021 — Indexing strategy driven by the actual queries
**Status:** Accepted

Each index exists to serve a named query:

| Index | Serves |
|---|---|
| `tickets(number)` unique | lookup by ticket ID, integrity |
| `tickets(status, sla_due_at)` | breach scan, the "breached" filter, the dashboard breached count |
| `tickets(status, sla_warning_at)` | approaching scan and filter |
| `tickets(assigned_agent_id, status)` | "my tickets", agent workload count for auto-assignment, the by-agent dashboard |
| `tickets(customer_id, created_at)` | customer's own ticket list (sorted) |
| `tickets(category_id, status)` | category filter, candidate workload |
| `tickets(priority, status)` | priority filter, critical count, by-priority dashboard |
| `tickets(created_at)` | date-range filter, default sort |
| `tickets(status, resolved_at)` | dashboard "resolved in the last 30 days" count |
| `tickets(updated_at)` | ticket list `sort=updated_at` (P3) |
| `users(name)` | admin user list: sort by name and name prefix search (P2) |
| `tickets FULLTEXT(subject, description)` | keyword search |
| `tickets(external_reference)` unique | CSV de-duplication |
| `ticket_replies(ticket_id, is_internal, created_at)` | conversation timeline with internal notes filtered out |
| `activity_log(subject_type, subject_id, created_at)` | ticket activity timeline (added on top of the package's defaults) |
| `activity_log(created_at)` | chunked retention prune (`activity:prune`) |
| `attachments(ticket_id, is_internal)` | attachment list with visibility |
| `users(is_available)` + package's `model_has_roles(role_id, model_id, model_type)` | finding eligible agents |
| `agent_category(category_id, user_id)` | agents per category |
| `notifications(notifiable_type, notifiable_id, created_at)` | inbox page (newest first, no filesort) and unread count and filter |
| `notifications(read_at, created_at)` | chunked prune of old read notifications (`notifications:prune`) |

**Why.** Indexes cost write throughput and storage, so each one must be justified by a query that the plan's tests or the dashboard exercises. Composite indexes put the equality column (`status`) first and the range column (time) second.

**Alternatives rejected.** *Indexing every foreign key and filter column individually.* MySQL would often pick one single-column index and then filter the rest, which is worse for the combined `status + time` predicates our hot queries use.

**Trade-offs.** Nine secondary indexes on `tickets` slow inserts a little; that's negligible compared with the read volume of a help desk. An `EXPLAIN` audit test (`tests/Feature/Performance/IndexUsageTest.php`) runs the real endpoints and the escalation scan and confirms each query uses its index. The inbox and unread count are served by the `(notifiable_type, notifiable_id, created_at)` index; the separate `(read_at, created_at)` index exists only for the prune command.

---

### TD-022 — Ticket numbers generated from a per-year counter row
**Status:** Accepted (amended 2026-10-29: the upsert replaces `INSERT IGNORE` + `FOR UPDATE`, which deadlocks)

**Decision.**
- `ticket_sequences(year PK, last_number)`. Inside the ticket-creation transaction:
  1. `INSERT … VALUES (:year, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1`
  2. `SELECT last_number … FOR UPDATE`. This reads our own write and doesn't wait, because we already hold the lock.
  3. Format the result as `TKT-{YYYY}-{%06d}`.
- **Why not `INSERT IGNORE` then `SELECT … FOR UPDATE`.** The original design deadlocks. An `INSERT` that hits a duplicate key takes a *shared* lock on the existing row. Two concurrent creations both hold it, then both try to upgrade to an *exclusive* lock, and InnoDB kills one with error 1213. The upsert takes the exclusive lock immediately, so no upgrade is needed.
- The prefix comes from config.
- Padding is a minimum width, so ticket 1,000,000 becomes `TKT-2026-1000000` without breaking anything.
- `tickets.number` is unique as a final guard.

**Why.**
- Correct under concurrency: the row lock serializes number allocation per year, and only for the instant of the increment.
- Restarts every year, matching the `TKT-2026-…` format.
- Exposes nothing about total system volume across years.
- Readable by humans.

**Alternatives rejected.**
- *`MAX(number)+1`.* A race condition. Two requests read the same max, and one fails on the unique index or, without one, duplicates.
- *Derive from `tickets.id` after insert.* Requires a second write, doesn't reset per year, and couples the public ID to the internal primary key.
- *UUID / ULID.* Unique without coordination, but not the requested format and not friendly in support conversations ("my ticket is 01J9Z…").
- *Redis `INCR`.* Fast, but a second source of truth that can diverge from the database (on a flush or failover), and Redis is optional per TD-005.

**Trade-offs.** Every ticket creation briefly serializes on one row per year. At help-desk volumes (far below 100 tickets per second) this is negligible. A rolled-back transaction rolls back the counter too, so gaps appear only when a commit fails, which is acceptable for a support reference number.

---

### TD-023 — SLA state stored as two timestamps (`sla_due_at`, `sla_warning_at`) and derived at query time
**Status:** Accepted

**Decision.**
- When an SLA is (re)calculated, store both the deadline and the moment "approaching" begins.
- The SLA state is a pure function of the current time and these columns:
  - breached: `now ≥ due`
  - approaching: `warning ≤ now < due`
  - within: otherwise
  - paused and stopped tickets are excluded
- The state is exposed both as query scopes (for filtering and counts) and as a model accessor (for display).

**Why.**
- Never stale. No background job needs to flip a status column for the UI to be correct.
- Index-backed range predicates (TD-021) make filtering, pagination and the dashboard counts cheap.
- Business-hours math runs only on writes (create, priority change, resume), never on reads. That matters because a business-hours calculation isn't expressible in SQL.

**Alternatives rejected.**
- *An `sla_status` column updated by the scheduler.* Stale between scans, and the UI would contradict reality for up to one scan interval.
- *Computing the state in PHP per row.* Can't be filtered or paginated in SQL. It would mean loading every active ticket.
- *Storing only `due_at` and computing "approaching" as `due_at - X hours`.* Wrong under business hours: 75% of an 8-hour SLA isn't a fixed wall-clock offset when the window crosses a weekend.

**Trade-offs.** Two columns must be kept consistent. They are only ever written by `SlaCalculator`, called from Actions.

---

### TD-024 — Attachments carry a denormalized `ticket_id` and an `is_internal` flag (not polymorphic)
**Status:** Accepted

**Decision.**
- `attachments(ticket_id, reply_id nullable, is_internal, …)`.
- An attachment on a reply inherits the reply's `is_internal` flag when it's created.

**Why.**
- Authorization is a single check: "can view the ticket" AND "the attachment isn't internal, or the user is staff". There's no polymorphic resolution and no join to replies.
- Listing all of a ticket's files is one indexed query.

**Alternatives rejected.**
- *Polymorphic `attachable_type/id`.* Flexible but unnecessary: attachments only ever belong to tickets or replies. Polymorphic relations can't have real foreign keys and complicate authorization.
- *spatie/medialibrary.* Powerful (conversions, collections) but more than we need, and we'd still write the authorization logic ourselves.

**Trade-offs.** The `is_internal` flag is duplicated. It's set once at creation, and replies are never converted between internal and public, so the duplicate can't drift.

---

### TD-025 — Referential integrity: real foreign keys, explicit delete behavior, no soft deletes
**Status:** Accepted

**Decision.**
- Every relationship is a foreign key constraint.
- Deleting a ticket cascades to its child rows (replies, attachments). Activity log rows are polymorphic (TD-026), so they're cleaned by retention, not by a foreign key. Deleting a user or category with tickets is restricted. A deleted agent's assignment is set to null.
- No soft deletes.

**Why.**
- Data integrity is enforced by the database, not just by application code.
- Tickets are records of what happened. The product flow is to *close* tickets, not delete them, so soft deletes would add `deleted_at IS NULL` to every query, and to every index's effectiveness, for no requirement.
- Users who leave are deactivated (`is_available=false`) rather than deleted.

**Alternatives rejected.** *Soft deletes everywhere.* A common reflex, but it hides integrity problems and complicates the unique constraints (`number`, `external_reference`).

**Trade-offs.** An admin can't "delete" a user who has history. That's intentional.

---

### TD-026 — spatie/laravel-activitylog for audit history, written explicitly from domain-event listeners
**Status:** Accepted (amended 2026-10-29: originally a custom `ticket_activities` table; changed after review)

**Decision.**
- Domain events go to the `RecordTicketActivity` listener, which calls the package's explicit logging API:
  ```php
  activity('ticket')
      ->performedOn($ticket)
      ->causedBy($actor)            // null = system (scheduler, auto-assign)
      ->event(ActivityType::Claimed->value)
      ->withProperties(['from' => null, 'to' => ['id' => $agent->id, 'name' => $agent->name]])
      ->log(ActivityType::Claimed->describe($properties));
  ```
- Log name: `ticket`. `event` holds an `App\Enums\ActivityType` value:
  - `created`, `assigned`, `claimed`, `unassigned`
  - `status_changed`, `priority_changed`, `category_changed`, `updated`
  - `replied`, `internal_note`
  - `sla_paused`, `sla_resumed`, `sla_approaching`, `escalated`
  - `imported`
- **The `LogsActivity` trait is not used on `Ticket`.** Automatic model diffs would also log internal bookkeeping writes (SLA recalculation, escalation level, assignment timestamps) as noise, and they don't capture business intent ("claim" vs "admin assignment").
- `Ticket::activities()` is a `morphMany` to `Spatie\Activitylog\Models\Activity`. The timeline query uses `->where('log_name','ticket')->latest()`, with `causer:id,name` eager-loaded.
- Customers never see `internal_note` entries: `Ticket::activitiesVisibleTo($user)` excludes that event for non-staff.
- Migration adds an index `activity_log(subject_type, subject_id, created_at)` for the timeline query.

**Why.**
- A standard, well-known package for storage and the causer/subject relations. We don't maintain our own table or model; retention is our chunked `activity:prune` command.
- Explicit logging from listeners keeps the business-level timeline the PRD asks for. Every Action produces history automatically through its events.
- The same log can be reused for non-ticket auditing (user role changes, category edits) at no extra cost.

**Alternatives rejected.**
- *A custom `ticket_activities` table.* Gives real foreign keys, but re-implements what the package already provides. This was the original choice.
- *The package's `LogsActivity` trait on models.* Noisy, and has no business intent (see above).
- *Model observers.* Same problem.
- *Event sourcing.* Far beyond what's required.

**Trade-offs.**
- `activity_log` is polymorphic, so there's no foreign key to `tickets` and no cascading delete. Tickets aren't deleted in normal operation (TD-025), so this is acceptable. The retention policy is the chunked `activity:prune` command (days from `activitylog.delete_records_older_than_days`).
- The listener runs **synchronously** after commit, so the history is present as soon as the request returns. If the activity insert fails after the main transaction has committed, the action has succeeded but its history entry is missing. We accept this rare inconsistency instead of coupling the audit write into every transaction. Errors are logged loudly.

---

## Testing & tooling

### TD-030 — Pest 4 with risk-ordered test priorities; Larastan level 6; Pint
**Status:** Accepted

**Decision.** Pest for all tests. Larastan (PHPStan) at level 6 and Pint (Laravel preset) run in CI and in the gate for every task.

**What we test, in priority order:**
1. SLA and business-hours math (unit, with datasets)
2. The authorization matrix across web and API
3. Concurrency (claim race, ticket number uniqueness)
4. Auto-assignment selection
5. The status state machine and its SLA side effects
6. Escalation thresholds and idempotency
7. CSV import (valid, invalid and duplicate rows; streaming)
8. Notification routing (internal notes never reach customers)
9. Query counts (no N+1) on the list and dashboard
10. Endpoint smoke tests (status and shape)

**Why.**
- These are the places where a defect is costly (an SLA breach miscalculated, a data leak, a double assignment) or subtle (time math, races).
- Plain CRUD that Laravel already guarantees gets smoke tests, not exhaustive tests. Choosing *what* to test was part of the brief.
- Pest datasets turn the authorization matrix and the calendar edge cases into readable tables.

**Alternatives rejected.**
- *PHPUnit classes.* Equivalent capability, more verbose for dataset-heavy tests.
- *Coverage-percentage targets.* They reward testing trivial code.
- *Browser (Dusk/Pest browser) tests.* The rules all live on the server; `assertInertia` covers the page contract more cheaply.

**Trade-offs.** No end-to-end UI tests. UI regressions are caught by `vue-tsc` and manual review, not automated clicks.

---

### TD-031 — Tests run against MySQL, not in-memory SQLite
**Status:** Accepted

**Decision.** `phpunit.xml` targets a `techzu_testing` MySQL database with `RefreshDatabase`. CI uses a MySQL 8 service container. Pure unit tests (`tests/Unit`) don't touch the database.

**Why.** The behaviors we most need to prove depend on MySQL: `FOR UPDATE` locking, conditional `UPDATE` affected-row counts under real concurrency, FULLTEXT search, JSON columns, and `INSERT IGNORE`. A green SQLite run would prove nothing about them.

**Alternatives rejected.** *SQLite in-memory.* Faster, but it changes the semantics of exactly the features under test.

**Trade-offs.** Slower test runs, and a MySQL instance is required. This is mitigated by `RefreshDatabase`'s transaction wrapping and by running Pest in parallel (`--parallel`) with per-process databases.

---

### TD-032 — Concurrency tests use two real database connections
**Status:** Accepted

**Decision.**
- The claim-race test opens a second named connection (`mysql_concurrent`).
- It holds a transaction on that connection and attempts the claim on the default connection, or simulates interleaving at the exact point where the two would collide.
- It then asserts exactly one winner, one `TicketAlreadyClaimed`, and a single `assigned` activity.
- Tests that need committed data across connections use `DatabaseTruncation` instead of transactions.

**Why.** Mocking the database can't prove the absence of a race. Only real interleaved connections exercise the conditional update or lock.

**Alternatives rejected.** *Forking PHP processes (`pcntl`).* Non-deterministic and flaky in CI.

**Trade-offs.** These tests are slower and need truncation. They're kept few and targeted.

---

### TD-033 — API documentation generated by Scramble
**Status:** Accepted

**Decision.**
- `dedoc/scramble` generates OpenAPI 3.1 from routes, FormRequests and Resources.
- It's served at `/docs/api` (local or admin-only) and exported to `docs/api/openapi.json`. The raw JSON is at `/docs/api.json`, and the gate is `AuthorizeApiDocs`, which applies `UserPolicy::viewAny`.
- There's no hand-written API guide (amended 2026-10-29): Scramble is the only API documentation, and the README links to it.

**Why.** The documentation is derived from the code that enforces the contract, so it can't drift, and it needs no docblock annotations to maintain.

**Alternatives rejected.**
- *Scribe.* Mature, but relies more on annotations and example-generating calls.
- *Hand-written OpenAPI.* Drifts from the code.
- *A Postman collection only.* Not a specification.

**Trade-offs.** Scramble infers types statically. Complex dynamic responses occasionally need a type hint or a `@response` docblock.

---

### TD-034 — CI pipeline
**Status:** Accepted

GitHub Actions runs: `composer install` → `pint --test` → `phpstan analyse` → `pest --parallel` (MySQL service) → `npm ci && npm run build && npm run check && vue-tsc --noEmit`. The build runs first because it generates Wayfinder's gitignored TypeScript, and `npm run check` is the kit's lint/format check (amended 2026-10-29 for the Laravel 13 kit). The same commands form the local gate every task must pass (TD-140).

---

## Ticket lifecycle & SLA

### TD-040 — Status state machine encoded in the `TicketStatus` enum
**Status:** Accepted

**Decision.**
- Statuses: `Open`, `InProgress`, `WaitingForCustomer`, `Resolved`, `Closed`.
- `TicketStatus::allowedTransitions(): array` is the single source of truth.
- The `ChangeTicketStatus` Action is the only code path that changes status. It validates the transition, applies the side effects (SLA pause/resume, `resolved_at`, `closed_at`) and fires events.
- Transitions:
  - `Open → InProgress | WaitingForCustomer | Resolved`
  - `InProgress → WaitingForCustomer | Resolved`
  - `WaitingForCustomer → InProgress | Resolved`
  - `Resolved → Closed | InProgress` (reopen)
  - `Closed` is terminal
- A customer reply on a ticket in `WaitingForCustomer` moves it to `InProgress`, with the system as actor.
- Two sanctioned exceptions write status outside `ChangeTicketStatus`: the atomic claim and auto-assign conditional update that moves Open to In Progress (TD-051), and ticket creation by CSV import, which sets the status from the row.

**Why.**
- Invalid states (for example re-opening a closed ticket) are impossible to reach through the application.
- The rules live in one readable place, and that place is what the tests check.

**Alternatives rejected.**
- *A state machine package (spatie/laravel-model-states).* Class-per-state is heavy for five states with simple side effects.
- *Free-form status updates with validation in each controller.* The rules would scatter.

**Trade-offs.** Adding a status means updating the enum's transitions and its tests. That's deliberate.

---

### TD-041 — A pure, window-stepping `BusinessCalendar`
**Status:** Accepted

**Decision.**
- `BusinessCalendar` is constructed with a weekly schedule (per weekday, a list of `[start, end]` windows), a timezone and a set of holiday dates.
- `addBusinessMinutes($from, $minutes)` converts to the business timezone, moves to the next open window, uses up `min(remaining, windowEnd − cursor)`, and repeats.
- `businessMinutesBetween($a, $b)` is its inverse.
- Inputs and outputs are UTC `CarbonImmutable`.
- No config reads, facades or `now()` inside. Everything is injected, so it is deterministic and unit-testable without Laravel booting.
- PRD example: Critical (120 min) created Thursday 17:00 → 60 min Thursday, Friday and Saturday off → due **Sunday 10:00**.

**Why.**
- Correct by construction: stepping whole windows instead of minutes is O(number of windows), so a 48-hour SLA crosses about 6 windows.
- Multiple windows per day (a lunch break) and holidays come for free.
- Being pure makes the most failure-prone logic the easiest to test exhaustively.

**Alternatives rejected.**
- *Adding clock hours.* Explicitly wrong per the PRD.
- *Minute-by-minute iteration.* Correct but O(minutes), which is 2,880 iterations for a Low ticket times every recalculation.
- *A package (e.g. cmixin/business-time).* Adds a dependency and global Carbon macros for about 100 lines of code we need to understand and explain anyway. Its holiday integration also doesn't match our database-backed holidays.
- *Computing in SQL.* Not expressible cleanly.

**Trade-offs.**
- One company-wide calendar and timezone (per-team calendars are out of scope).
- DST: we compute in local wall-clock time and convert. Asia/Dhaka has no DST, and for zones with DST, wall-clock windows are the intended behavior.

---

### TD-042 — The SLA pauses in `WaitingForCustomer` and is resumed by recomputing the remaining time
**Status:** Accepted

**Decision.**
- Entering `WaitingForCustomer` sets `sla_paused_at`.
- Leaving it computes `remaining = businessMinutesBetween(paused_at, due_at)` and sets `due_at = addBusinessMinutes(now, remaining)`. `warning_at` is recomputed the same way, floored at zero. `paused_at` is then cleared.
- If the deadline moves later, `escalation_level` is lowered to match the new state, so the ticket can escalate again.
- Resolved and Closed tickets are "stopped" and excluded from every SLA query. Reopening keeps the original deadline. The SLA is not credited for the time the ticket spent resolved.

**Why.**
- Industry-standard behavior: agents aren't penalized for time the ticket was waiting on the customer.
- Recomputing from the remaining business time needs one nullable column, and all the arithmetic stays inside the tested calendar.

**Alternatives rejected.**
- *Never pausing.* Simpler, but unfair breaches skew the dashboard.
- *Accumulating `paused_minutes` and computing `due = start + sla + paused`.* Still needs business-time arithmetic at read time and can't be indexed.

**Trade-offs.**
- Gaming risk (agents could park tickets in WaitingForCustomer). Mitigated by the activity history, which records every pause and resume.
- Reopened tickets may be breached immediately. This is intentional, since they weren't actually resolved.

---

### TD-043 — Priority change recalculates the SLA from the original start
**Status:** Accepted

**Decision.** A priority change recomputes the deadlines from `sla_started_at` (not from now) using the new priority's duration. If the ticket is currently paused, the pause is preserved.

**Why.** Raising a ticket to Critical after 3 business hours should make it due 2 business hours from when it was *created*, which may mean it is breached immediately. Measuring from now would let a re-prioritization quietly extend the deadline.

**Alternatives rejected.** *Measuring from the change time.* Can be gamed and is inconsistent with what the SLA promises the customer.

**Trade-offs.** A ticket can become breached immediately after an upgrade. That's correct, and it gets escalated on the next scan.

Because recalculation starts from `sla_started_at`, business time credited by an earlier, completed pause/resume cycle is lost when the priority later changes; an ongoing pause is kept. Keeping that credit would need an accumulated paused-minutes column.

---

### TD-044 — Escalation by a scheduled, idempotent scan (`sla:scan`, every minute)
**Status:** Accepted

**Decision.**
- `sla:scan` runs every minute with `withoutOverlapping()->onOneServer()`. It streams active, unpaused tickets by id, 500 at a time.
- For each threshold it runs a conditional `UPDATE … SET escalation_level = N WHERE id IN (…) AND escalation_level < N` and dispatches `TicketSlaApproaching` or `TicketSlaBreached` only for rows it actually changed.
- Listeners record the activity and send queued notifications: approaching → the assigned agent (or admins); breached → the agent and the admins.

**Why.**
- Idempotent at the database level, so overlapping runs, retries or multiple servers never send duplicate escalations.
- Self-healing: if the scheduler is down for an hour, the next run catches up.
- Works with any queue driver.

**Alternatives rejected.**
- *A delayed job per ticket (`dispatch()->delay($dueAt)`).* Every priority change or pause/resume would require cancelling and re-dispatching (Laravel has no native cancel). Jobs are lost if the queue is flushed, and delays beyond SQS's 15-minute limit don't work.
- *Computing escalations on read.* Can't send notifications or create history at the right moment.

**Trade-offs.**
- Delivery is **at most once**. Each escalation is committed before its event is dispatched, so a process crash between the two loses that notification rather than ever sending a duplicate. The activity log and the ticket's `escalation_level` remain correct, and the dashboard still shows the breach.
- Up to 1 minute of latency in detecting a breach. That's acceptable for SLAs measured in hours.
- The scan touches only indexed active rows, so cost stays flat as closed tickets accumulate.

---

### TD-045 — SLA rules live only in `config/sla.php`
**Status:** Accepted

**Decision.** `resolution_minutes` per priority and `warning_threshold_percent` are read **only** by `SlaCalculator`. Business hours live in `config/business_hours.php`. Holidays live in the database, because they're operational data that admins edit.

**Why.** The PRD requires that changing SLA rules shouldn't need changes throughout the application. One config file plus one consumer satisfies that.

**Alternatives rejected.** *An `sla_policies` database table editable in an admin UI.* More flexible, but it would need versioning (which policy applied to an old ticket?) and more UI and tests for a requirement the PRD doesn't make. The config's shape can easily be moved into a table later behind the same `SlaCalculator` interface.

**Trade-offs.**
- Changing SLA durations requires a deploy (or an env override).
- Existing tickets keep their stored deadlines. A config change applies only to new or recalculated tickets, which is intended.

---

## Assignment & concurrency

### TD-050 — Auto-assignment: category-qualified agents, least active load, oldest last assignment as tie-break
**Status:** Accepted

**Decision.**
- After `TicketCreated`, the queued `AutoAssignTicket` job asks `AgentSelector` for a candidate.
- Candidates are agents who are available and linked to the ticket's category (`agent_category`). They're ordered by active ticket count (Open, InProgress, WaitingForCustomer) ascending, then `last_assigned_at` ascending (never-assigned agents first), then id.
- If there's no eligible agent, the ticket stays unassigned and appears on the dashboard and in the queue, and a log line is written.
- Auto-assignment can be switched off with `tickets.auto_assign`.

**Why.**
- Category routing sends tickets to agents who have the right skills.
- Least load balances the work.
- The `last_assigned_at` tie-break is a round-robin among equally loaded agents, so ties don't always go to the same agent.
- Every factor is a column we already have and an indexed count.

**Alternatives rejected.**
- *Pure round-robin.* Ignores actual workload: an agent stuck on hard tickets keeps receiving more.
- *Least load without categories.* Sends billing tickets to technical agents.
- *Weighted scoring by priority or skill level.* Harder to explain and tune, and not required.

**Trade-offs.** Load counts all active tickets equally (a Critical ticket weighs the same as a Low one). It's simple, and documented as a known simplification.

---

### TD-051 — Concurrent claim prevented by an atomic conditional UPDATE
**Status:** Accepted

**Decision.**
```sql
UPDATE tickets
   SET assigned_agent_id = :agent, assigned_at = NOW(),
       status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END
 WHERE id = :id AND assigned_agent_id IS NULL
```
- Affected rows = 1 → this agent won; fire `TicketAssigned` (type `claimed`).
- Affected rows = 0 → throw `TicketAlreadyClaimed` (HTTP 409, `code: ticket_already_claimed`). The UI refreshes to show the actual owner.

**Why.**
- MySQL evaluates the `WHERE` and applies the write atomically under a row lock. Two simultaneous claims are serialized by InnoDB, and the second one sees `assigned_agent_id` is no longer NULL.
- One round-trip, no explicit lock held across PHP code, no deadlock risk, and the database is the single source of truth.

**Alternatives rejected.**
- *`SELECT … FOR UPDATE` then `UPDATE` in a transaction.* Also correct, but two round-trips, and the row lock is held across PHP execution. Better suited to multi-step decisions (used in TD-052).
- *Optimistic locking with a `version` column.* Correct, but it adds a column and retry logic for a check the `assigned_agent_id IS NULL` predicate already performs.
- *`Cache::lock()` (Redis).* An external lock that can expire or be lost. Correctness would then depend on cache availability, and Redis is optional (TD-005).
- *A unique constraint.* There's nothing to make unique: a ticket has one nullable assignee column.

**Trade-offs.** None significant. The losing agent gets a clear 409 instead of a silent overwrite. Admin reassignment (`AssignTicket`) deliberately bypasses the `IS NULL` check, since overriding an assignment is its purpose, and it runs under a transaction with `lockForUpdate`.

---

### TD-052 — Auto-assignment uses `lockForUpdate` on the candidate agents within a transaction
**Status:** Accepted

**Decision.**
- `AutoAssignTicket` runs in a transaction. It selects the candidate agents with `lockForUpdate`, computes their loads, picks one, and assigns with the same conditional update as TD-051.
- If the ticket was claimed manually in the meantime (affected rows = 0), the job finishes quietly.

**Why.** Two auto-assign jobs running at once (for two new tickets) would otherwise read the same loads and both pick the same "least loaded" agent. Locking the agent rows serializes the selection, so loads stay accurate. The conditional update still protects against a manual claim happening concurrently.

**Alternatives rejected.** *No lock (accept imbalance).* Imbalance under bursts is exactly when balancing matters most.

**Trade-offs.**
- Auto-assign jobs serialize against each other for the categories they share. That's acceptable at help-desk volume.
- Lock order is consistent (sorted by agent id) to avoid deadlocks.
- The job is `ShouldBeUnique` per ticket.

---

### TD-053 — Transaction boundaries
**Status:** Accepted

**Decision.**
- An Action wraps its work in `DB::transaction()` when it writes more than one row or table, or when it reads and then writes. Examples: CreateTicket (sequence + ticket), AddReply (reply + attachments + status change), AssignTicket, the import of each row.
- Events implement `ShouldDispatchAfterCommit`, and queued notifications use `afterCommit`.
- No transactions are opened in controllers.
- Transactions never contain external I/O such as mail or HTTP calls.

**Why.**
- Transactions are for atomicity, not habit.
- Dispatching after commit means listeners and jobs never act on data that is later rolled back.
- Keeping I/O out of transactions keeps lock duration short.

**Trade-offs.** File storage can't be part of a database transaction. `AddReply` stores files first and deletes them if the commit fails (TD-060).

---

## Conversation & storage

### TD-060 — Replies and internal notes share one table, separated by an `is_internal` flag
**Status:** Accepted

**Decision.**
- `ticket_replies(is_internal bool)`.
- `AddReply` forces `is_internal=false` for customers.
- Visibility is enforced by:
  - `Ticket::replies()->visibleTo($user)`
  - `TicketReplyResource`
  - `AttachmentPolicy`
  - the notification listeners
- Files are written to storage before the database commit. On rollback, a compensating delete removes them.

**Why.**
- One chronological conversation timeline for staff, built from one indexed query.
- The leakage risk is concentrated on a single, well-tested flag.

**Alternatives rejected.** *A separate `internal_notes` table.* Physical separation is arguably safer, but it duplicates attachments, ordering and UI logic, and staff would need two timelines merged in PHP.

**Trade-offs.** A missing `visibleTo` scope would leak data. This is mitigated by the three-layer defense (TD-120), each layer with dedicated tests.

---

### TD-061 — Private disk storage with authorized streaming downloads
**Status:** Accepted

**Decision.**
- Files are stored on the private `local` disk (or S3 via `ATTACHMENTS_DISK`) at `attachments/{ticket_id}/{uuid}.{ext}`.
- Allowed types, extensions, max size (10 MB) and max count (5 per reply) live in `config/attachments.php` and are validated with `File::types()->max()`.
- Downloads go through a controller that checks `AttachmentPolicy::view` and streams with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.

**Why.**
- Files are never reachable by URL guessing. Every access is authorized against the same rules as the ticket.
- UUID filenames prevent path traversal and filename collisions.
- Content sniffing (rather than trusting the client's MIME type) blocks disguised executables.

**Alternatives rejected.**
- *The `public` disk with `storage:link`.* Anyone with the URL could fetch an internal attachment.
- *Signed temporary URLs.* Good for S3 offloading. They can be added later behind the same policy, but they're unnecessary on the local disk.
- *Storing files as database BLOBs.* Bloats the database and its backups.

**Trade-offs.**
- PHP streams the downloads (on the local disk). That's fine at this scale; on S3, the next step is `temporaryUrl()` issued after the policy check.
- No virus scanning; documented as a production follow-up.

---

## Events, notifications, queues

### TD-070 — Domain events drive the side effects; Actions don't call notifiers directly
**Status:** Accepted

**Decision.**
- Actions dispatch domain events: `TicketCreated`, `TicketAssigned`, `TicketReplied`, `TicketStatusChanged`, `TicketPriorityChanged`, `TicketResolved`, `TicketSlaApproaching`, `TicketSlaBreached`.
- Listeners (auto-discovered) handle the side effects:
  - `RecordTicketActivity` (sync)
  - `Send*Notification` (dispatch queued notifications)
  - `QueueAutoAssignment`
- The event classes are created up front, in task T05, as a stable contract.

**Why.**
- Actions stay focused on the state change.
- New side effects (Slack, webhooks) can be added without touching the Actions.
- Defining the events as a contract early lets the notification and feature tasks proceed in parallel.

**Alternatives rejected.**
- *Calling `Notification::send()` inside Actions.* Couples every use case to every channel, and testing an Action would require faking notifications.
- *Model observers.* No business intent (TD-026).

**Trade-offs.** It's an indirection: to see what happens on ticket creation, you read the listeners. This is mitigated by documenting the event → listener flow in `docs/FEATURES.md`.

---

### TD-071 — Queued notifications on the mail and database channels, sent after commit
**Status:** Accepted

**Decision.**
- Every notification implements `ShouldQueue`, runs on the `notifications` queue, and uses `afterCommit()`.
- Channels are `mail` and `database`; internal-note notifications use `database` only.
- The actor of an event is never notified about their own action.
- The recipient rules (see the spec, §11) live in each listener.

**Why.** The PRD requires email to be sent without blocking requests. Database notifications give an in-app notification bell and a durable record.

**Alternatives rejected.**
- *Synchronous mail.* Blocks the request and fails it when SMTP is down.
- *Broadcast / websockets.* Realtime isn't required.

**Trade-offs.** A queue worker must be running. Without one, notifications pile up in `jobs` but aren't lost. Locally `QUEUE_CONNECTION=sync` avoids this (TD-005). The README documents `composer run dev` / `queue:work` and `schedule:work` for the asynchronous setup.

---

### TD-072 — Queue topology and job reliability
**Status:** Accepted

**Decision.**
- Queues:
  - `default`: auto-assign
  - `notifications`
  - `imports`: prepare, chunk and finalize jobs, so a large import never delays notifications
- **Worker priority order: `--queue=default,notifications,imports`.**
  - `default` (auto-assign) goes first, because a ticket's SLA clock runs while it waits for an agent.
  - Bulk imports go last; in production they get their own worker pool.
- Jobs declare `tries=3`, `backoff=[10, 60, 300]`, and `failed()` logging.
- **Exception (amended 2026-10-29): import chunk jobs use `tries=1`.** A chunk commits row by row, so a retry would re-process rows that were already committed. Duplicates are prevented by `external_reference`, but rows without a reference would be duplicated. A failed chunk is recorded on the import instead, and the admin re-uploads the remaining rows.
- Import chunks are `Batchable`.
- `AutoAssignTicket` is `ShouldBeUnique` per ticket.

**Why.**
- Isolating slow bulk work from user-facing latency.
- Bounded retries with backoff handle transient database or SMTP errors.

**Alternatives rejected.**
- *A single queue.* A 100k-row import would delay "your ticket was assigned" emails.
- *Laravel Horizon.* Excellent, but Redis-only, which conflicts with TD-005's database-driver profile. It's documented as optional when running on Redis.

---

## Search

### TD-080 — `TicketFilter` query object with FULLTEXT keyword search and indexed filters
**Status:** Accepted

**Decision.**
- `IndexTicketsRequest` validates: `number`, `q`, `status[]`, `priority[]`, `category_id`, `assigned_agent_id`, `unassigned`, `customer_id`, `created_from`, `created_to`, `sla_state`, `sort` (whitelisted), `per_page` (≤ 100).
- `TicketFilter::apply()` composes the query:
  - `Ticket::visibleTo($user)` first
  - `MATCH(subject, description) AGAINST(? IN BOOLEAN MODE)` for `q`
  - `LIKE` as the fallback on other drivers
  - range scopes for `sla_state`
- Eager loading: `category:id,name`, `customer:id,name`, `assignedAgent:id,name`, and `withCount('replies')`.
- Results use offset pagination.

**Why.**
- One reusable, testable object serves both the web and the API.
- FULLTEXT scales where `LIKE '%term%'` (a full table scan) doesn't.
- Column-limited eager loading prevents N+1 queries and over-fetching. A query-count test enforces it.

**Alternatives rejected.**
- *Laravel Scout + Meilisearch or Algolia.* The best relevance and typo tolerance, but extra infrastructure that the PRD's scope doesn't justify. `TicketFilter` isolates the search, so Scout could replace it later.
- *Model scopes for every filter.* Spreads the filter logic across the model, and shared model edits would conflict between tasks.
- *spatie/laravel-query-builder.* Convenient, but harder to apply our role-scoped visibility and SLA-state semantics.
- *Cursor pagination.* Faster at deep pages, but the UI needs page numbers and totals. It's documented as the fix if deep pagination ever becomes slow.

**Trade-offs.**
- MySQL FULLTEXT has a minimum token length (`innodb_ft_min_token_size=3`) and stopwords, so very short search terms won't match. Exact ticket-number search covers the most common case.
- `COUNT(*)` for the totals costs something on very large filtered sets.

---

## CSV import

### TD-090 — Streamed, chunked, batched import with per-row validation and an error table
**Status:** Accepted

**Decision.**
1. An admin uploads a CSV (≤ 50 MB). `StartTicketImport` stores it privately, creates `ticket_imports(pending)` and dispatches `PrepareTicketImport`.
2. Prepare streams the file (`LazyCollection` + `SplFileObject`), validates the header, counts rows, and dispatches a `Bus::batch` of `ImportTicketsChunk` jobs (500 rows each, by line range) on the `imports` queue.
3. Each chunk validates every row with `ImportTicketRowRules`:
   - Valid rows go through `ImportTicketRow` in **one transaction per row**, reusing the number generator and SLA calculator.
   - Invalid or duplicate rows (`external_reference` already exists) are written to `ticket_import_errors(row_number, errors, raw)`.
   - Counters are updated with atomic increments.
4. `FinalizeTicketImport` (batch `finally`) sets the final status and notifies the admin.
5. The UI polls the progress, lists the errors (paginated) and offers the errors as a streamed CSV download.
6. Imported tickets don't send per-ticket notifications to customers.

**Why.**
- Memory stays constant regardless of file size: no `file()` or full parse.
- Long-running work runs off the request, in parallel chunks, and can be retried per chunk.
- One transaction per row means a bad row never discards 499 good ones.
- De-duplication is enforced by a unique index, not a pre-scan, so it's race-safe across chunks.
- The error table can be queried and paginated, so admins can fix and re-upload only the failed rows.

**Alternatives rejected.**
- *Synchronous import in the request.* Times out and blocks the request.
- *maatwebsite/laravel-excel.* Capable, but a heavy dependency for plain CSV. Its chunked queued import is roughly what we build in about 150 transparent lines, and it obscures the memory and transaction behavior we're asked to explain.
- *One transaction per chunk.* A single bad row would roll back the entire chunk.
- *`LOAD DATA INFILE`.* Fastest, but bypasses validation, ticket numbering and SLA calculation.

**Trade-offs.**
- Per-row transactions and number allocation make imports slower than bulk inserts (around a few hundred rows per second per worker). That's acceptable, and scales with the number of workers.
- Duplicates are detected only through `external_reference`. Rows without one are treated as new by design, since matching on subject and customer is ambiguous.

---

## Dashboard & performance

### TD-100 — Dashboard from a few grouped aggregate queries, cached for 60 seconds
**Status:** Accepted

**Decision.** `DashboardStats` runs:
1. One query using conditional aggregates for the totals: `SUM(CASE WHEN … THEN 1 ELSE 0 END)` over active tickets for open, unassigned, critical, breached and approaching, plus resolved in the last 30 days.
2. A `GROUP BY priority` query.
3. A `GROUP BY assigned_agent_id` query joined to the agents' names.

The result is cached in `Cache::remember('dashboard:'.$scope, 60)`.

**Why.**
- Three indexed queries instead of N count queries or loading models.
- A 60-second cache absorbs repeated refreshes; staleness is irrelevant for a summary view.
- A query-count test enforces the budget.

**Alternatives rejected.**
- *Pre-aggregated counter tables updated by listeners.* Instant reads, but counters drift, need reconciliation, and add more points of failure.
- *No cache.* Fine at small scale. The cache is essentially free and protects against "dashboard on a TV refreshing every 5 seconds".

**Trade-offs.** Counts can be up to 60 seconds stale. Per-ticket views are always live.

---

### TD-101 — General performance rules
**Status:** Accepted

- Eager-load relationships with column lists, and use `withCount` instead of loading collections to count them. `Model::preventLazyLoading()` is on outside production, so N+1 queries fail tests immediately.
- Paginate every list endpoint, capped at `per_page ≤ 100`.
- Scans and imports use `chunkById` / `lazyById` (never `get()` on unbounded sets).
- Only Actions run writes, and none of them run in loops without batching.
- Holidays are cached (`Cache::rememberForever`, invalidated on change), because the calendar consults them on every SLA calculation.

---

## Error handling

### TD-110 — Domain exceptions with stable codes, rendered once
**Status:** Accepted

**Decision.**
- Domain errors extend `App\Exceptions\Domain\DomainException`, which carries an HTTP status and a stable `code`:
  - `InvalidStatusTransition` (422)
  - `TicketAlreadyClaimed` (409)
  - `AgentNotEligible` (422)
  - `TicketClosed` (422)
  - `ImportHeaderInvalid` (422)
  - `CannotChangeOwnRole` (422): an admin can't change their own role
- `bootstrap/app.php` renders them in one place:
  - The API gets JSON `{message, code}`.
  - The web gets `back()->with('error', …)`, and Inertia shows a toast.
- Validation, authorization (403) and not-found (404) errors use the framework defaults.
- `NoEligibleAgent` during auto-assignment is logged, not thrown to a user.

**Why.**
- Business rules fail with meaningful, machine-readable codes. API clients branch on `code`, not on message text.
- Controllers contain no try/catch boilerplate.
- One renderer means one consistent error format.

**Alternatives rejected.**
- *Returning error arrays or booleans from Actions.* Easy to ignore, and adds noise to every caller.
- *Generic `abort(409)` calls inside Actions.* Couples domain code to HTTP.

**Trade-offs.** A small exception hierarchy to maintain.

---

## Configuration

### TD-130 — Domain configuration in dedicated config files, environment-overridable
**Status:** Accepted

| File | Contents |
|---|---|
| `config/sla.php` | resolution minutes per priority, warning threshold % |
| `config/business_hours.php` | timezone, weekly windows |
| `config/tickets.php` | number prefix, auto-assign on/off, per-page max |
| `config/attachments.php` | disk, allowed types/extensions, max size, max files |
| `config/imports.php` | max upload size, chunk size |

**Why.**
- Rules are discoverable in one place, can be changed per environment, and work with `config:cache`.
- Code never calls `env()` outside config files.

**Alternatives rejected.** *Hard-coded constants (they're not explicit and can't be overridden).* *A settings database table for everything (operational data like holidays is the only thing that justifies one).*

---

## Development process

### TD-140 — Plan-driven development on `main` with parallel subagents and one review per plan
**Status:** Accepted (rewritten 2026-10-29 by owner decision; the worktree flow used for T00–T05 is retired)

**Decision.**
- The remaining work is six plans (`docs/plans/P1…P6`), executed in order on `main` in the main checkout, following `docs/plans/ROADMAP.md`. There are no worktrees and no task branches.
- Each plan is split into waves. A wave's subtasks never share a file, and each subtask gets its own implementer subagent, running in parallel. Implementers run only their own test files on their own test database (`techzu_testing_agent<N>`), and never touch git.
- After the last wave, the session runs the full gate **once**. Spec, quality and security reviewers and a documenter then run in parallel on the uncommitted diff. Fixes rerun only the affected tests.
- Nothing is committed until the owner approves. Then the session creates one Conventional Commit per subtask (staging that subtask's files), with a `Refs: Pn.m` trailer and no AI attribution. The owner pushes.
- During development, a column or index on an existing table is added by editing that table's migration (`migrate:fresh` rebuilds the dev and test databases). Nothing has been deployed, so this is safe, and it avoids a trail of one-column migrations.

**Why.**
- The worktree flow ran the full suite after every wave and again for every commit (`rebase --exec`), which cost far more time than it bought.
- Parallelism inside a plan gives the speed-up without the coordination cost of parallel sessions.
- Owner approval before committing keeps the history the team lead reviews clean and per-subtask.

**Alternatives rejected.**
- *Worktrees + task branches + a per-commit gate* (used for T00–T05). Too slow and too complex for one developer.
- *Parallel plan sessions on one checkout.* Their uncommitted changes would mix.
- *One implementer for a whole plan.* Slower, with no parallelism.

**Trade-offs.**
- Only each plan's final state is proven by the gate; intermediate per-subtask commits aren't tested on their own. An optional `git rebase --exec` check can be run once at the end if the owner asks for it.
- Plans must be precise about file ownership within a wave.

---

### TD-141 — Agent tooling: Laravel Boost MCP and Playwright MCP; no Claude-in-Chrome
**Status:** Accepted (2026-10-29)

**Decision.**
- **Laravel Boost** (`laravel/boost:^2.10`, dev only) is installed in T00 with `boost:install --guidelines --mcp`. It gives the implementing agents an MCP server with:
  - documentation search for the exact installed versions
  - the database schema
  - tinker
  - application and browser logs
  - the last error

  Its guidelines are appended **below** our rules in CLAUDE.md, and our rules win where they conflict. The Antigravity files it creates are removed.
- **Playwright MCP** (`.mcp.json`) is the only browser automation. Agents use it for the browser check of UI tasks (ROADMAP.md step 7), against a local `php artisan serve` the session starts and stops, using the seeded data.
- **Claude in Chrome and the built-in Claude browser are denied** in `.claude/settings.json` and in the owner's global settings.

**Why.**
- Agents that look up the installed version's docs make far fewer API mistakes than agents working from memory. That matters most right after a major-version move (Laravel 13, Inertia 3).
- Playwright runs a separate, scriptable browser profile on the local machine. It never touches the owner's real browser sessions.

**Alternatives rejected.**
- *No Boost.* Agents would rely on their training data, which predates parts of this stack.
- *Pest browser tests in the suite.* They would add a heavy, slower layer of tests the owner didn't ask for. The MCP-driven check covers the UI flows without growing the suite.
- *Claude in Chrome.* It drives the owner's real browser profile; explicitly forbidden by the owner.

**Trade-offs.**
- Boost adds dev-only files (`boost.json` and the guideline block) that `boost:update` can change, so review its diffs like any other change.
- Browser checks aren't a committed regression suite. They're a per-plan verification step.

