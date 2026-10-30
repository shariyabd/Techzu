# P1 — Setup fixes

> **Self-driving plan.** The session that runs this file is the controller. Read `docs/plans/ROADMAP.md` and `CLAUDE.md` first, then use the `subagent-driven-development` skill: run the **Waves** table below in order, dispatching every subtask of a wave as its own subagent in one message, with the model the table names, and wait for the whole wave before starting the next. Session steps (S0, S1) are done by the controller itself. Then gate once → parallel review → fix → report (ROADMAP steps 4–8). **Don't commit until the owner approves.**

**Goal:** Simplify the schema and config, drop unused dependencies, make the app run with `php artisan serve` + `npm run dev`, and give the repo a root `README.md`.  **Depends on:** — (T00–T05 on `main`).  **Outcome:** `php artisan migrate:fresh --seed` builds the schema from 16 migrations instead of 20; `password_reset_tokens` and the `tickets(status, resolved_at)` index exist for P2 and P4; a new developer follows `README.md` to a running app at <http://127.0.0.1:8000>; `techzu_testing_agent1..8` exist for every later plan's implementers.

## Global constraints
- Nothing is deployed, so the migrations listed below are edited in place (ROADMAP owner decision). This is the plan that may break CLAUDE.md's "don't edit migrations from earlier tasks".
- Migration files this plan rewrites lose the kit's prose docblocks and inline comments (CLAUDE.md §Code style).
- `config/permission.php` is not edited: it already uses the package defaults (`teams` false, default table and column names), so the literal names in the rewritten migration match it.
- Sanctum and Boost are already installed and configured; they are verified, never reinstalled:
  - Sanctum: `laravel/sanctum` 4.3, `config/sanctum.php` (guard `web`, stateful domains include `127.0.0.1:8000`), `User` uses `HasApiTokens`, `2026_10_29_000200_create_personal_access_tokens_table.php` exists.
  - Boost: `laravel/boost` 2.10 (dev), `boost.json` targets `claude_code`, `.mcp.json` registers `laravel-boost` (`php artisan boost:mcp`) next to `playwright`.
- Kept dependencies, checked and in use: `dedoc/scramble` (P6 API docs), `laravel/pail` and `@laravel/multiplex` (used by `php artisan dev` on Linux), `concurrently` (used by `php artisan dev` on Windows), `laravel/pao` (Pest plugin for agent output), every JS runtime package (each is imported under `resources/`). The only unused package is `laravel/chisel` (the kit's install-time feature picker; nothing references it).

## Schema needs for P1
This plan is P1; it applies the needs itself:
- `password_reset_tokens` table (P2 password reset).
- `tickets(status, resolved_at)` index (P4 dashboard "resolved in the last 30 days" count; was T12.1's separate migration).

## Waves
| Wave | Subtask | Model | Runs in parallel with |
|---|---|---|---|
| S0 | Session step: create agent test databases | session | — |
| W1 | P1.1 Migrations, config and tests | sonnet | P1.2 |
| W1 | P1.2 Dependencies, dev setup and README | sonnet | P1.1 |
| S1 | Session steps: local `.env`, rebuild databases, remove `.worktrees/` | session | — |

S0 runs before wave 1 (a small reorder of the owner's list) because P1.1 runs its tests on `techzu_testing_agent1`, which must exist first.

---

## S0 — Create the agent test databases (session)

The credentials are read from `.env` into a temporary option file that only the current user can read; the `trap` deletes it even if `mysql` fails. Nothing is echoed.

```bash
cd /home/shariya/techzu
(
  env_val() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
  cnf="$(mktemp)"
  trap 'rm -f "$cnf"' EXIT
  chmod 600 "$cnf"
  printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' \
    "$(env_val DB_HOST)" "$(env_val DB_PORT)" "$(env_val DB_USERNAME)" "$(env_val DB_PASSWORD)" > "$cnf"
  sql=""
  for n in 1 2 3 4 5 6 7 8; do
    sql="${sql}CREATE DATABASE IF NOT EXISTS techzu_testing_agent${n} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  done
  mysql --defaults-extra-file="$cnf" -e "$sql"
  mysql --defaults-extra-file="$cnf" -N -e "SHOW DATABASES LIKE 'techzu_testing_agent%'"
)
```
Expected: eight rows, `techzu_testing_agent1` … `techzu_testing_agent8`. If access is denied, stop and ask the owner (CLAUDE.md §Databases). Don't start a `mysqld`.

---

## P1.1 — Migrations, config and tests

**Files:**
- modify `database/migrations/0001_01_01_000000_create_users_table.php`
- modify `database/migrations/2026_10_29_000100_create_permission_tables.php`
- modify `database/migrations/2026_10_30_000100_create_activity_log_table.php`
- delete `database/migrations/2026_10_30_000101_add_event_column_to_activity_log_table.php`
- delete `database/migrations/2026_10_30_000102_add_batch_uuid_column_to_activity_log_table.php`
- delete `database/migrations/2026_10_30_000110_add_subject_created_at_index_to_activity_log_table.php`
- delete `database/migrations/2026_10_30_000200_add_assignment_columns_to_users_table.php`
- modify `database/migrations/2026_10_30_000400_create_tickets_table.php`
- modify `config/activitylog.php`
- modify `tests/Feature/Database/SchemaTest.php`
- modify `tests/Feature/Database/RolesAndPermissionsSeederTest.php`
- modify `TECHNICAL_DECISIONS.md` (one row in the TD-021 table)
- modify `docs/ERD.md` (one row in the index table)

**Consumes / Produces:**
- Produces table `password_reset_tokens(email PK, token, created_at nullable)`, the framework default that `config/auth.php` `passwords.users.table` already names. P2's password broker uses it.
- Produces index `tickets(status, resolved_at)` (default name `tickets_status_resolved_at_index`). P4's `DashboardStats` relies on it.
- `activity_log` keeps every column and index it has today (`event`, `batch_uuid`, `log_name` index, `activity_log_subject_created_at_index`), so `RecordTicketActivity` and the activity API are unaffected.
- `users` keeps `is_available` (indexed, default true) and `last_assigned_at`; `User`, `UserFactory` and `DemoSeeder` are unaffected.
- `config('activitylog.table_name')` stays `'activity_log'` and `config('activitylog.database_connection')` stays `null` (the default connection). The keys must stay in `config/activitylog.php`: the package merges its own config under ours with `mergeConfigFrom`, and its defaults still read `ACTIVITY_LOGGER_TABLE_NAME` / `ACTIVITY_LOGGER_DB_CONNECTION`, so removing the keys would bring the env lookups back. `Spatie\Activitylog\Models\Activity` reads both keys in its constructor.

**Steps:**

1. **Failing test first.** In `tests/Feature/Database/SchemaTest.php`, inside `dataset('required indexes', [...])`, insert this row directly after the `'tickets(created_at)'` row:

```php
    'tickets(status, resolved_at)' => ['tickets', ['status', 'resolved_at'], false, 'BTREE'],
    'tickets(updated_at)' => ['tickets', ['updated_at'], false, 'BTREE'],
```

Run it and see it fail:
```bash
DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Database/SchemaTest.php
```

2. **Users migration.** Replace `database/migrations/0001_01_01_000000_create_users_table.php` with the version below. It folds in the two assignment columns and their index from the deleted `000200` migration, and re-adds the framework's `password_reset_tokens` table (removed by T00a, needed again by P2).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_available')->default(true)->index();
            $table->timestamp('last_assigned_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
```

Delete `database/migrations/2026_10_30_000200_add_assignment_columns_to_users_table.php`.

3. **Permission tables.** Replace `database/migrations/2026_10_29_000100_create_permission_tables.php` with literal names. The teams branches go (`teams` is false and `permission.testing` is unset). The cache `forget` at the end goes too: `migrate:fresh` drops the `cache` table, and `RolesAndPermissionsSeeder` already calls `forgetCachedPermissions()`. `foreignId()->constrained()` produces the same foreign key names as the package's `foreign()->references()`, and the primary and index names are kept, so the schema is identical.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
```

4. **Activity log.** Replace `database/migrations/2026_10_30_000100_create_activity_log_table.php` with one migration that has the literal table name, the default connection, the `event` and `batch_uuid` columns, and the TD-021 subject/created_at index. Column order matches what the three follow-ups produced (`event` after `subject_type`, `batch_uuid` after `properties`).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_log_subject_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
```

Delete `2026_10_30_000101_add_event_column_to_activity_log_table.php`, `2026_10_30_000102_add_batch_uuid_column_to_activity_log_table.php` and `2026_10_30_000110_add_subject_created_at_index_to_activity_log_table.php`.

5. **Tickets index.** In `database/migrations/2026_10_30_000400_create_tickets_table.php`, insert after `$table->index('created_at');`:

```php
            $table->index(['status', 'resolved_at']);
            $table->index('updated_at');
```

6. **Activity log config.** In `config/activitylog.php`, replace the two env lookups with plain values:

```php
    'table_name' => 'activity_log',

    'database_connection' => null,
```

`'enabled' => env('ACTIVITY_LOGGER_ENABLED', true)` stays; it is the package's on/off switch and out of scope.

7. **Seeder test.** In `tests/Feature/Database/RolesAndPermissionsSeederTest.php`, the `is_idempotent` test reads the table name from config. Use the literal name the migration now creates:

```php
        ->and(DB::table('role_has_permissions')->count())->toBe($pivotRows);
```

A repo-wide grep (`grep -rn "activitylog\.\|permission\.table_names\|permission\.column_names\|2026_10_30_0001\|add_assignment_columns" tests app database`) must print nothing after this step.

8. **Record the index.** In `TECHNICAL_DECISIONS.md`, in the TD-021 table, insert directly after the `tickets(created_at)` row:

```markdown
| `tickets(status, resolved_at)` | dashboard "resolved in the last 30 days" count |
| `tickets(updated_at)` | ticket list `sort=updated_at` (P3) |
| `users(name)` | admin user list: sort by name and name prefix search (P2) |
```

In `docs/ERD.md`, in the index table, insert directly after the `| tickets | \`(created_at)\` | …` row:

```markdown
| tickets | `(status, resolved_at)` | dashboard "resolved in the last 30 days" count |
| tickets | `(updated_at)` | ticket list sort by last update |
| users | `(name)` | admin user list: sort by name and name prefix search |
```

9. **Run.** The migrations are checked on the agent database (it is a test database, so seeding it is harmless; `RefreshDatabase` rebuilds it on the next test run). `migrate:rollback` proves every `down()` works.

```bash
DB_DATABASE=techzu_testing_agent1 php artisan migrate:fresh --seed --force
DB_DATABASE=techzu_testing_agent1 php artisan migrate:status | grep -c Ran
DB_DATABASE=techzu_testing_agent1 php artisan migrate:rollback --force
DB_DATABASE=techzu_testing_agent1 php artisan migrate --force
DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Database tests/Feature/Activity tests/Feature/Models/UserTest.php
./vendor/bin/pint database/migrations config/activitylog.php tests/Feature/Database
```
Expected: `16` from the `grep -c`, and every test passes. P1.2 runs `composer remove` at the same time; if one run fails with an autoload or "class not found" error, rerun it once.

**Proposed commit:**
```
refactor(db): fold follow-up migrations into their create migrations

Nothing is deployed, so the activity log and user assignment follow-ups
are merged into their create migrations, the permission tables use
literal names, password_reset_tokens returns for password reset, and
tickets gains the (status, resolved_at) index for the dashboard.

Refs: P1.1
Implements: TD-021, TD-026
```

---

## P1.2 — Dependencies, dev setup and README

**Files:**
- modify `composer.json`, `composer.lock` (through `composer remove` only)
- modify `.env.example`
- modify `pint.json`
- modify `vite.config.ts`
- modify `.gitignore`
- create `README.md`
- delete `docs/DEVELOPMENT.md`

**Consumes / Produces:**
- `.env.example` produces `APP_URL=http://127.0.0.1:8000` and `QUEUE_CONNECTION=sync`; every other value is unchanged.
- `README.md` links `docs/FEATURES.md`, `docs/DESIGN.md`, `docs/ERD.md`, `TECHNICAL_DECISIONS.md` and `PRD.md`. `docs/DESIGN.md` is created by the session (the design spec moves there); the link is correct once that move is done.
- Demo accounts in the README come from `database/seeders/DemoSeeder.php` and `UserFactory` (password `password`).

**Steps:**

1. **Remove chisel** (first, so P1.1's test runs settle on the final autoloader quickly):
```bash
grep -rn "chisel\|Chisel" app bootstrap config routes database tests resources
composer remove laravel/chisel --no-interaction
```
Expected: the grep prints nothing; `composer remove` ends with package discovery and no error. Don't add, update or remove any other package.

2. **`.env.example`.** Change exactly two lines:
```dotenv
APP_URL=http://127.0.0.1:8000
```
```dotenv
QUEUE_CONNECTION=sync
```

3. **Worktree leftovers.** Worktrees are no longer used.
- `pint.json` becomes:
```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```
- `vite.config.ts`: delete the `'**/.worktrees/**',` line from `server.watch.ignored`. Nothing else changes.
- `.gitignore`: delete the two lines `# Task worktrees live inside the project for review (docs/plans/README.md §2)` and `/.worktrees/`.

4. **README.** Create `README.md` with exactly this content. It replaces `docs/DEVELOPMENT.md`: the Apache vhost, PHP-FPM pool, worktree databases and Docker notes are dropped; the starter-kit commit, Boost, `laravel/pao` and test-database notes are kept.

````markdown
# Techzu Support

A support-ticket system with SLA tracking: customers open tickets, agents work them inside business-hours SLAs, and admins manage users, categories and holidays. Built with Laravel 13, Inertia 3 and Vue 3, on MySQL 8.

## Documentation
- [PRD.md](PRD.md): the requirements
- [docs/FEATURES.md](docs/FEATURES.md): how each feature behaves, in plain language
- [docs/DESIGN.md](docs/DESIGN.md): the design
- [docs/ERD.md](docs/ERD.md): the database schema and its indexes
- [TECHNICAL_DECISIONS.md](TECHNICAL_DECISIONS.md): the decision log (TD-xxx)

## Requirements
- PHP 8.3 with the `pdo_mysql`, `mbstring`, `openssl` and `fileinfo` extensions
- Composer 2
- Node.js 22 or newer, with npm
- MySQL 8

## Setup
```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Set `DB_USERNAME` and `DB_PASSWORD` in `.env`, then create the two databases as a MySQL user that may create them:
```bash
mysql -u <user> -p -e "CREATE DATABASE techzu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE techzu_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Build the schema and the demo data:
```bash
php artisan migrate:fresh --seed
```
Run the same command again at any time to reset the database to the demo state.

## Running the app
Two terminals:
```bash
php artisan serve
```
```bash
npm run dev
```
Open <http://127.0.0.1:8000> and log in with a demo account.

`.env.example` sets `QUEUE_CONNECTION=sync`, so queued work (auto-assignment, notifications, imports) runs inside the request and no worker is needed.

### Asynchronous alternative
To run queued work in the background, as production does:
1. Set `QUEUE_CONNECTION=database` in `.env`.
2. Run `composer run dev`. It runs `php artisan dev`, which starts `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, `php artisan pail` (live logs) and `npm run dev` together.
3. In another terminal, run the scheduler, which drives the SLA scan:
   ```bash
   php artisan schedule:work
   ```

`queue:listen` there processes only the `default` queue. To process every queue in the decided priority order, run a worker yourself instead:
```bash
php artisan queue:work --queue=default,notifications,imports
```
After changing a job or listener, restart `queue:work`; it keeps the old code in memory.

## Demo accounts
Every demo account uses the password `password`.

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@example.com` | |
| Agent | `agent1@example.com` | Billing, Technical Support |
| Agent | `agent2@example.com` | Technical Support |
| Agent | `agent3@example.com` | General Inquiry |
| Agent | `agent4@example.com` | Billing, General Inquiry; unavailable, so never auto-assigned |
| Customer | `customer1@example.com` … `customer5@example.com` | |

## Tests
Tests always run on MySQL, on the `techzu_testing` database that `phpunit.xml` selects. Each test run rebuilds the schema itself.
```bash
./vendor/bin/pest --parallel
./vendor/bin/pest tests/Feature/Tickets
```
`--parallel` creates and uses its own `techzu_testing_test_N` databases. To use another test database, set it on the command line, where it wins over `phpunit.xml`:
```bash
DB_DATABASE=techzu_testing_agent1 ./vendor/bin/pest tests/Feature/Tickets
```

Checks that must pass before a commit:
```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npm run check && npx vue-tsc --noEmit
```
`npm run build` generates Wayfinder's TypeScript route helpers (`resources/js/{routes,actions,wayfinder}`, gitignored), so on a fresh checkout run it before `vue-tsc`.

When Pest, Pint or PHPStan run under an AI agent, `laravel/pao` replaces their report with a one-line JSON summary such as `{"tool":"pest","result":"passed",...}`. In a terminal you see the normal output.

## Known limitations
- There is no email verification; new accounts can log in immediately.
- Mail uses the `log` mailer, so every email is written to `storage/logs/laravel.log` instead of being sent. Set `MAIL_MAILER` and the `MAIL_*` values in `.env` to send real mail.
- Password reset links therefore appear in `storage/logs/laravel.log`.
- There is no Docker setup yet; the app runs on a local PHP and MySQL.

## Development notes
- The app was scaffolded from `laravel/vue-starter-kit` at commit [`fd5b58f`](https://github.com/laravel/vue-starter-kit/commit/fd5b58fb5672cae0152dd04c16ea37d9f52b1cd5); compare against it before pulling kit changes.
- `laravel/boost` provides the `laravel-boost` MCP server (`php artisan boost:mcp`, registered in `.mcp.json`) and the guidelines at the end of `CLAUDE.md`. `boost.json` targets Claude Code only.
- Contributor and agent rules are in [CLAUDE.md](CLAUDE.md).
````

Delete `docs/DEVELOPMENT.md`.

5. **Run.** There are no PHP tests for this subtask. Check that Pint still reads its config and that nothing references the removed paths:
```bash
./vendor/bin/pint --test app/Models/User.php
grep -rn "worktrees" pint.json vite.config.ts .gitignore
grep -n "chisel" composer.json
php artisan dev:list
```
Expected: Pint passes; both greps print nothing; `dev:list` shows `server`, `queue`, `logs` and `vite`. `vite.config.ts` is verified by the session gate (`npm run build && npm run check`), not here.

**Proposed commits** (two, split by file; both pass the gate on their own):
```
build: remove laravel/chisel and default to a sync queue locally

The app now runs with php artisan serve and npm run dev alone, so
.env.example uses QUEUE_CONNECTION=sync and the serve URL. Worktree
excludes are gone from pint.json, vite.config.ts and .gitignore.

Refs: P1.2
Implements: TD-005
```
Files: `composer.json`, `composer.lock`, `.env.example`, `pint.json`, `vite.config.ts`, `.gitignore`.
```
docs: add README with setup, demo accounts and known limitations

Refs: P1.2
Implements: TD-005
```
Files: `README.md`, `docs/DEVELOPMENT.md` (deleted).

---

## S1 — Local environment and database rebuild (session, after wave 1)

1. **Local `.env`.** Match `.env.example` for the two changed keys without printing the file:
```bash
cd /home/shariya/techzu
sed -i -e 's|^APP_URL=.*|APP_URL=http://127.0.0.1:8000|' -e 's|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=sync|' .env
grep -E "^(APP_URL|QUEUE_CONNECTION)=" .env
php artisan config:clear
```

2. **Rebuild the dev database:**
```bash
php artisan migrate:fresh --seed --force
php artisan migrate:status | grep -c Ran
```
Expected: `16`.

3. **Rebuild the test database:**
```bash
DB_DATABASE=techzu_testing php artisan migrate:fresh --force
```

4. **Remove the empty worktree folder** (it is no longer gitignored): `rmdir .worktrees`. `git worktree list` must show only the main checkout.

5. **Gate** (ROADMAP step 4). `vite.config.ts` changed, so the frontend line runs too:
```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
npm run build && npm run check && npx vue-tsc --noEmit
```

6. **Browser check.** Start `php artisan serve` (port 8000) and `npm run dev` in the background. With Playwright MCP, open <http://127.0.0.1:8000/login>, log in as `admin@example.com` / `password`, and confirm the app shell and the ticket list render with no console errors. Repeat for `customer1@example.com`. Stop both processes, then run `pgrep -af "artisan serve|vite"` and confirm nothing is left.

---

## Acceptance criteria
1. `php artisan migrate:fresh --seed` succeeds on `techzu`, and `migrate:status` lists 16 migrations (was 20). `migrate:rollback` followed by `migrate` also succeeds.
2. `database/migrations` has exactly one activity-log migration, `2026_10_30_000100_create_activity_log_table.php`. It uses the literal table `activity_log` on the default connection, has the `event` and `batch_uuid` columns, and the `activity_log_subject_created_at_index` index. It contains no `config(` call.
3. `2026_10_29_000100_create_permission_tables.php` contains no `config(` call, no teams branch and no comments; the resulting tables, primary keys, indexes and foreign keys are the same as before (the `SchemaTest` rows and `RolesAndPermissionsSeederTest` pass).
4. `2026_10_30_000200_add_assignment_columns_to_users_table.php` is gone; `users.name` (indexed), `users.is_available` (indexed, default true) and `users.last_assigned_at` come from `0001_01_01_000000_create_users_table.php`, which also creates `password_reset_tokens(email PK, token, created_at)`.
5. `tickets` has the `(status, resolved_at)` and `(updated_at)` indexes, and `users` has `(name)`; `SchemaTest` asserts the ticket indexes, and it is recorded in the TD-021 table and in `docs/ERD.md`.
6. `config/activitylog.php` sets `table_name` to `'activity_log'` and `database_connection` to `null`, with no `env()` call for either.
7. `laravel/chisel` is absent from `composer.json` and `composer.lock`; no other package was added, removed or updated.
8. `.env.example` has `APP_URL=http://127.0.0.1:8000` and `QUEUE_CONNECTION=sync`. `pint.json`, `vite.config.ts` and `.gitignore` no longer mention `.worktrees`.
9. `README.md` exists with requirements, setup, running the app, the asynchronous alternative (`composer run dev` with `QUEUE_CONNECTION=database` plus `php artisan schedule:work`), the demo accounts, tests, and known limitations (no email verification; mail goes to the log; password reset links appear in `storage/logs/laravel.log`). It links `docs/FEATURES.md`, `docs/DESIGN.md`, `docs/ERD.md` and `TECHNICAL_DECISIONS.md`, and contains no Apache or Docker setup. `docs/DEVELOPMENT.md` is deleted.
10. `techzu_testing_agent1` … `techzu_testing_agent8` exist on the local MySQL server, and no credential file is left behind.
11. The full gate is green: `pint --test`, `phpstan analyse`, `pest --parallel`, `npm run build`, `npm run check` and `vue-tsc --noEmit`.
12. With only `php artisan serve` and `npm run dev` running, logging in at <http://127.0.0.1:8000> as `admin@example.com` / `password` shows the app, and both processes are stopped afterwards.
