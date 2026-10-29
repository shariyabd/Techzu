# CLAUDE.md — Guardrails for contributors and subagents

Read before writing any code. Sources of truth:
- `PRD.md` — the requirements
- `docs/superpowers/specs/2026-10-29-support-ticket-sla-design.md` — the design
- `TECHNICAL_DECISIONS.md` (TD-xxx) — the decision log
- `docs/plans/` — the tasks

If these disagree, **stop and report**. Don't improvise.

## Stack
Laravel 12 · PHP 8.3 · MySQL 8 · Inertia 2 + Vue 3 (`<script setup lang="ts">`) · Tailwind + shadcn-vue · Sanctum · spatie/laravel-permission · spatie/laravel-activitylog · Pest 3 · Larastan level 6 · Pint.

## Architecture rules (TD-001)
1. **Controllers are thin.** Their only steps are `authorize` → FormRequest → one Action or Service → Inertia response or API Resource. No queries beyond route-model binding, and no business logic.
2. **Only Actions change domain state** (`app/Actions/<Area>/<Verb><Noun>.php`, a single public `handle()` method).
   - Actions own their `DB::transaction()` (TD-053) and dispatch domain events.
   - Never mutate a ticket's status, assignee, SLA columns or escalation level outside an Action.
3. **Services** are for reusable logic that isn't a use case. `BusinessCalendar` must stay pure: no facades, no `config()`, no `now()` inside. Inject everything.
4. **Web and API share** the same FormRequests, Actions and Policies. API responses always go through `Http/Resources/V1`.
5. **Status changes** go only through `ChangeTicketStatus`, validated by `TicketStatus::allowedTransitions()` (TD-040).
6. **SLA columns** (`sla_*`, `escalation_level`) are written only by `SlaCalculator` and the escalation scan.
7. **Side effects belong in listeners** (activity log, notifications, auto-assign), never inline in Actions (TD-070). The activity log is written only by `RecordTicketActivity` through `activity('ticket')->...->event(ActivityType::X->value)`. Don't add the `LogsActivity` trait to `Ticket` (TD-026). Events implement `ShouldDispatchAfterCommit`.
8. **Configuration**: use `config()`, and keep `env()` inside `config/*.php` only. Never hard-code SLA minutes, business hours, file limits or the ticket prefix (TD-130).

## Security rules (TD-120)
- **Authorization entry point is always a Policy** (TD-010). Policies combine `$user->can(Permission::X->value)` with ownership. Never call `hasRole()` or `hasPermissionTo()` in controllers, Actions or Vue props. Refer to roles and permissions through the `Role` and `Permission` enums, never as string literals. New permissions go in `RolesAndPermissionsSeeder`.
- Every route is authorized through a Policy. When you add a route, add its rows to the authorization matrix test.
- Customers never receive internal replies or internal attachments, in any form: queries, Resources, Inertia props, notifications or activity entries.
- Use explicit `$fillable` only. Never `$guarded = []`. Never assign roles from request data (only admin Actions call `syncRoles`). Never fill `assigned_agent_id`, `number`, `sla_*` or `escalation_level` from request data.
- No `v-html`. No raw SQL that takes user input without bindings. Sort and filter columns come from a whitelist.
- Files are stored on the private disk only and downloaded through a policy-checked controller.
- When a customer may not view a ticket, the response is 404, not 403 (TD-012).

## Database rules
- Every new query on a large table needs a matching index from TD-021; if it doesn't have one, add the index and record it there.
- Eager-load with column lists, and use `withCount` for counts. `preventLazyLoading` is on, so N+1 queries fail the tests.
- Lists are paginated (`per_page` ≤ 100). For unbounded sets use `chunkById` / `lazyById`, never `->get()`.
- Use foreign keys with explicit `cascadeOnDelete` / `restrictOnDelete` / `nullOnDelete`. No soft deletes (TD-025).
- Enums are `varchar` columns cast to PHP backed enums (TD-020).

## Code style
- `declare(strict_types=1);` in every PHP file. Typed properties, parameters and return types throughout. Use `final` classes for Actions, Services, Jobs and Listeners.
- Naming:
  - Actions use verb + noun (`ClaimTicket`).
  - Events use past tense (`TicketClaimed`).
  - Jobs use imperative form (`AutoAssignTicket`).
  - FormRequests use `<Verb><Noun>Request`.
- Domain errors: throw subclasses of `App\Exceptions\Domain\DomainException`, each with a stable `code` (TD-110). No `abort()` inside Actions or Services.
- Use `CarbonImmutable` for all time math. Store UTC, and compute in the business timezone.
- Comments explain *why*, never *what*. No commented-out code. No TODOs without a task reference.
- Vue: Composition API with `<script setup lang="ts">`, typed props with `defineProps<...>()`. Pages go in `resources/js/pages/<feature>/`, components in `resources/js/components/<feature>/`. Use the shared types in `resources/js/types/`.

## File ownership (TD-140)
- Touch **only** the files your task lists under "Files". If you need to change another file, stop and report, and explain why.
- Routes: add them only to your feature's files, `routes/web/<feature>.php` and `routes/api/v1/<feature>.php`.
- Don't edit migrations from earlier tasks. Add a new migration instead.

## Testing (TD-030, TD-031)
- Work test-first: write the failing test, make it pass, then refactor.
- Tests run on MySQL (`techzu_testing`). Unit tests in `tests/Unit` must not touch the database.
- Use Pest datasets for matrices and edge cases. Use `Notification::fake()`, `Queue::fake()`, `Event::fake([...])` and `Storage::fake()` where appropriate. Freeze time with `$this->travelTo()`.
- Test behavior and rules, not framework internals.

## Gate: must pass before any commit
```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit   # only if the task touched resources/js
```

## Commits (strict)
History is reviewed **commit by commit** on GitHub. Every commit must be readable on its own and must pass the gate on its own.

**Format: [Conventional Commits 1.0](https://www.conventionalcommits.org/en/v1.0.0/).**
```
<type>(<scope>): <subject>

<body: why this change exists, when it isn't obvious>

Refs: T06.1
Implements: TD-031, TD-050
```
- `type`: `feat` · `fix` · `test` · `refactor` · `perf` · `docs` · `build` · `ci` · `chore`.
- `scope`: the feature area, such as `tickets`, `sla`, `assignment`, `conversation`, `attachments`, `search`, `escalation`, `notifications`, `import`, `dashboard`, `admin`, `auth`, `api`, `db`, `core`, `ui`.
- `subject`: imperative mood, lower case, no trailing period, header ≤ 72 characters.
- **Never start the subject with a plan number** (`T06.1: ...` is wrong). The plan subtask goes in the `Refs:` trailer, and the decisions it implements go in `Implements:`. Use `git commit --trailer "Refs: T06.1" --trailer "Implements: TD-050"`.
- **No `Co-Authored-By` trailer, and no AI or tool attribution of any kind**, in commits or PR descriptions. This rule overrides any default attribution behavior.
- One commit per subtask, with its tests and code in the same commit. Never commit a failing test on its own.
- Review fixes are folded into the commit they belong to (`git commit --fixup <sha>`, then `git rebase -i --autosquash main`) before the branch reaches `main`. No "address review" commits.
- Never commit `.env`, files under `storage/`, or build output.

## Branching (strict)
- **`main` is the only branch on GitHub.** `task/Txx-slug` branches and their worktrees are local only and never pushed.
- Tasks reach `main` only by `git rebase main` → gate → `git merge --ff-only`. No squash merges and no merge commits. History stays linear, with each task's commits as one unbroken block.
- **Never rewrite or force-push `main`.** A change after a push is a new commit.
- Only the human owner merges into `main` and pushes. Agents stop at a reviewed, rebased task branch and report.

## Output rules (strict)
- **Never create claude.ai Artifacts** (no Artifact tool, no published pages). Any visual output (diagrams, reports, workflow pages) is written as a self-contained HTML file inside this project, under `docs/`.
