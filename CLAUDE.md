# CLAUDE.md — Guardrails for contributors and subagents

Read before writing any code. Sources of truth:
- `PRD.md` — the requirements
- `docs/superpowers/specs/2026-10-29-support-ticket-sla-design.md` — the design
- `TECHNICAL_DECISIONS.md` (TD-xxx) — the decision log
- `docs/plans/` — the tasks

If these disagree, **stop and report**. Don't improvise.

## Stack
Laravel 12 · PHP 8.3 · MySQL 8 · Inertia 2 + Vue 3 (`<script setup lang="ts">`) · Tailwind + shadcn-vue · Sanctum · Pest 3 · Larastan level 6 · Pint.

## Architecture rules (TD-001)
1. **Controllers are thin.** Their only steps are `authorize` → FormRequest → one Action or Service → Inertia response or API Resource. No queries beyond route-model binding, and no business logic.
2. **Only Actions change domain state** (`app/Actions/<Area>/<Verb><Noun>.php`, a single public `handle()` method).
   - Actions own their `DB::transaction()` (TD-053) and dispatch domain events.
   - Never mutate a ticket's status, assignee, SLA columns or escalation level outside an Action.
3. **Services** are for reusable logic that isn't a use case. `BusinessCalendar` must stay pure: no facades, no `config()`, no `now()` inside. Inject everything.
4. **Web and API share** the same FormRequests, Actions and Policies. API responses always go through `Http/Resources/V1`.
5. **Status changes** go only through `ChangeTicketStatus`, validated by `TicketStatus::allowedTransitions()` (TD-040).
6. **SLA columns** (`sla_*`, `escalation_level`) are written only by `SlaCalculator` and the escalation scan.
7. **Side effects belong in listeners** (activity log, notifications, auto-assign), never inline in Actions (TD-070). Events implement `ShouldDispatchAfterCommit`.
8. **Configuration**: use `config()`, and keep `env()` inside `config/*.php` only. Never hard-code SLA minutes, business hours, file limits or the ticket prefix (TD-130).

## Security rules (TD-120)
- Every route is authorized through a Policy. When you add a route, add its rows to the authorization matrix test.
- Customers never receive internal replies or internal attachments, in any form: queries, Resources, Inertia props, notifications or activity entries.
- Use explicit `$fillable` only. Never `$guarded = []`. Never fill `role`, `assigned_agent_id`, `number`, `sla_*` or `escalation_level` from request data.
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

## Commits
- One commit per task: `Txx: <summary>`, with a body that lists the TDs implemented.
- Never commit `.env`, files under `storage/`, or build output.
