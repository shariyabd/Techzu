# CLAUDE.md — Guardrails for contributors and subagents

Read before writing any code. Sources of truth:
- `PRD.md` — the requirements
- `docs/DESIGN.md` — the design
- `TECHNICAL_DECISIONS.md` (TD-xxx) — the decision log
- `docs/plans/ROADMAP.md` and `docs/plans/P*.md` — the execution rules and the remaining plans

If these disagree, **stop and report**. Don't improvise.

## Stack
Laravel 13 · PHP 8.3 · MySQL 8 · Inertia 3 + Vue 3 (`<script setup lang="ts">`) · Tailwind + shadcn-vue · Sanctum (API tokens) + plain session auth with password reset (no Fortify, no email verification, TD-002) · spatie/laravel-permission · spatie/laravel-activitylog · Pest 4 · Larastan level 6 · Pint.

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
  - FormRequests live in a per-feature folder (`app/Http/Requests/<Feature>/`) and use `<Verb>Request`: the folder names the feature, so the class name repeats no noun (e.g. `Requests/Tickets/StoreRequest`, `Requests/Roles/UpdatePermissionsRequest`).
- Domain errors: throw subclasses of `App\Exceptions\Domain\DomainException`, each with a stable `code` (TD-110). No `abort()` inside Actions or Services.
- Use `CarbonImmutable` for all time math. Store UTC, and compute in the business timezone.
- **No comments in code (strict).** This applies to every file we write or rewrite: PHP, TypeScript/JavaScript, Vue, CSS, Blade, SQL, and config files (`.env.example`, `*.neon`, `*.yml`, `vite.config.ts`, `config/*.php`).
  - Banned: `//`, `#`, `/* … */`, `<!-- … -->`, prose in docblocks, commented-out code, TODOs.
  - **Only exception:** PHPDoc *type tags* that Larastan level 6 needs, with no prose: `@var`, `@param`, `@return`, `@property`, `@property-read`, `@method`, `@mixin`, `@use`, `@template`, `@extends`, `@implements`, `@throws`. For example `/** @return array<string, string> */`.
  - Explanations go in the commit body, `docs/FEATURES.md` or TECHNICAL_DECISIONS.md, never in the code. Names should make the intent clear.
  - **Plan code blocks are transcribed without their comments.** Where a plan snippet still contains one, drop it. Kit files we don't edit keep their own comments; any kit file we rewrite loses its prose comments.
  - Shell commands that an agent runs, and Markdown docs, aren't code. `#` comments are fine there.
- Vue: Composition API with `<script setup lang="ts">`, typed props with `defineProps<...>()`. Pages go in `resources/js/pages/<feature>/`, components in `resources/js/components/<feature>/`. Use the shared types in `resources/js/types/`.

## File ownership (TD-140)
- Touch **only** the files your task lists under "Files". If you need to change another file, stop and report, and explain why.
- Routes: add them only to your feature's files, `routes/web/<feature>.php` and `routes/api/v1/<feature>.php`.
- During development (nothing deployed), a column or index on an existing table is added by editing that table's migration, and the dev and test databases are rebuilt with `migrate:fresh`. A new migration is only for a new table.
- Exception: the task's own section of `docs/FEATURES.md` is always in scope for its documenter.

## Testing (TD-030, TD-031)
- Work test-first: write the failing test, make it pass, then refactor.
- Tests run on MySQL (`techzu_testing`). Unit tests in `tests/Unit` must not touch the database.
- Use Pest datasets for matrices and edge cases. Use `Notification::fake()`, `Queue::fake()`, `Event::fake([...])` and `Storage::fake()` where appropriate. Freeze time with `$this->travelTo()`.
- Test behavior and rules, not framework internals.

**Only specific, necessary tests (strict).** Every test must prove one of these, and its name says which:
1. a business rule from the PRD or spec (`it('pauses the SLA while waiting for the customer')`)
2. an acceptance criterion of the task
3. a security boundary (authorization, internal-note visibility, 404 versus 403)
4. a failure mode that can really happen (race conditions, the compensation path, a duplicate scan)

Don't write tests for:
- framework or package behavior: Eloquent casts, relationship definitions, spatie internals, single-field `required` rules
- trivial getters, constructors, config values or enum labels
- the same rule at several layers. Test the rule once, at the Action or Service. Endpoint tests cover only the HTTP mapping (status, resource shape) and authorization.

Use one dataset instead of several copy-pasted tests.

The code-quality reviewer rejects redundant tests. If the plan prescribes a test that breaks these rules and isn't tied to an acceptance criterion, the implementer drops it and says so in the report.

## Gate: run once per plan, after the last wave (ROADMAP.md step 4)
During implementation, agents run only their own test files. The full gate runs once at the end of the plan, and before the owner approves the commits.
```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npm run check && npx vue-tsc --noEmit   # only if the task touched resources/js (build first: it generates Wayfinder's gitignored types)
```

## Commits (strict)
History is reviewed **commit by commit** on GitHub. Every commit must be readable on its own. **Nothing is committed until the owner approves the plan's changes.** Then the session creates one commit per subtask (ROADMAP.md step 9).

**Format: [Conventional Commits 1.0](https://www.conventionalcommits.org/en/v1.0.0/).**
```
<type>(<scope>): <subject>

<body: why this change exists, when it isn't obvious>

Refs: P3.1
Implements: TD-031, TD-050
```
- `type`: `feat` · `fix` · `test` · `refactor` · `perf` · `docs` · `build` · `ci` · `chore`.
- `scope`: the feature area, such as `tickets`, `sla`, `assignment`, `conversation`, `attachments`, `search`, `escalation`, `notifications`, `import`, `dashboard`, `admin`, `auth`, `api`, `db`, `core`, `ui`.
- `subject`: imperative mood, lower case, no trailing period, header ≤ 72 characters.
- **Never start the subject with a plan number** (`P3.1: ...` is wrong). The plan subtask goes in the `Refs:` trailer, and the decisions it implements go in `Implements:`. Use `git commit --trailer "Refs: P3.1" --trailer "Implements: TD-050"`.
- **No `Co-Authored-By` trailer, and no AI or tool attribution of any kind**, in commits or PR descriptions. This rule overrides any default attribution behavior.
- One commit per subtask, with its tests and code in the same commit. Never commit a failing test on its own.
- Review fixes are made before committing, so they land inside the subtask's commit. No "address review" commits.
- Never commit `.env`, files under `storage/`, or build output.

## Branching (strict)
- **`main` is the only branch, locally and on GitHub.** No worktrees and no task branches. All work happens in the main checkout.
- **Never rewrite or force-push `main`.** A change after a push is a new commit.
- Only the human owner approves commits and pushes. Agents stop at a reviewed, uncommitted change set and report.
- **Parallel implementers** (ROADMAP.md step 2): one implementer subagent per subtask of the current wave, with no fixed cap (about 8 at most; the machine has 8 cores). They share the checkout, so within a wave no two subtasks touch the same file. Each implementer runs only its own test files on `techzu_testing_agent<N>`, and never touches git.

## Output rules (strict)
- **Never create claude.ai Artifacts** (no Artifact tool, no published pages). Any visual output (diagrams, reports, workflow pages) is written as a self-contained HTML file inside this project, under `docs/`.

## Browser (strict)
- **Never use or open Claude in Chrome (`mcp__claude-in-chrome__*`) or the built-in Claude browser (`mcp__Claude_Browser__*`).** They are denied in `.claude/settings.json`. Don't try to enable them, and don't ask to.
- All browser work uses the **Playwright MCP** server (`.mcp.json`), running locally. It drives its own browser profile, not the user's.
- Browser checks run against a local server the session starts (`php artisan serve --port=8010`) and stops afterwards, and seeded demo accounts, never against production or remote URLs. Screenshots go to the scratchpad and are never committed.

## Databases and background servers (strict)
1. **Use the existing MySQL server** (`127.0.0.1:3306`, credentials in `.env` only) with its dedicated databases: `techzu` (dev), `techzu_testing` (tests), `techzu_testing_agent1..8` (one per parallel implementer) and the `techzu_testing_test_N` databases that `pest --parallel` creates. **Don't start a separate `mysqld`.** If MySQL isn't reachable or access is denied, stop and ask the owner.
2. If a temporary server is truly unavoidable, its data directory and logs go **inside the project** under `./storage/test-mysql/` (gitignored). Never put them in the scratchpad, `/tmp/claude-*` or `/tmp`.
3. Stop any server you start as soon as you're done (`mysqladmin shutdown` or kill its PID), and confirm it's stopped. Never delete a server's folder while it is still running.
4. The same applies to every background process you start (`php artisan serve`, `queue:work`, `npm run dev`, Playwright browsers): stop it before you finish.
5. **Before finishing any task**, run `pgrep -af mysqld` (and check any other server you started) and report every server you started that is still running.

## Feature documentation (TD-140)
- `docs/FEATURES.md` is the single, living explanation of the business logic, written for a reader who hasn't read the code. It has one section per feature, and nothing else.
- Each plan's **documenter** subagent writes or updates only its own features' sections: what the feature does, its rules and its flow, in plain language. No file, class, method or test references.
- Document only behaviour that exists in the code. The spec reviewer checks the section against the code before the plan is reported.

## Laravel Boost guidelines (appended below by T00)
T00 runs `php artisan boost:install`, which appends a `<laravel-boost-guidelines>` block below this line. Use Boost's MCP tools, especially `search-docs` for the installed versions, before writing code against any package API. **Where Boost's guidelines conflict with the rules above, the rules above win.** For example:
- the gate still runs `pint --test`
- Boost's "ask the user to run the complete suite" doesn't apply: the session runs the full gate itself, once per plan
- plan tests that break §Testing are dropped and reported

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
