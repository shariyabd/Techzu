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

Escalation only happens when the scheduler runs. To see it, run `php artisan schedule:work` in a third terminal, or run `php artisan sla:scan` by hand.

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

## Features

- **Roles.** Admin, Agent and Customer. Permissions are defined in code and seeded; admins change a user's role and can view the permission matrix read-only.
- **Tickets.** Numbers look like `TKT-2026-000001`: the prefix is configurable, and a per-year counter row makes them unique under concurrency. Statuses are Open, In Progress, Waiting for Customer, Resolved and Closed, and only the allowed transitions are accepted. Customers see only their own tickets; asking for anyone else's returns 404.
- **SLA.** Resolution time depends on priority (Low 48 h, Medium 24 h, High 8 h, Critical 2 h, all in business minutes) and is counted only inside business hours, Sunday to Thursday 09:00 to 18:00 Asia/Dhaka, skipping holidays. The clock pauses while a ticket waits for the customer, and a priority change recalculates the deadline from the original start. A ticket is "approaching" after 75% of its time.
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
2. **Least load first:** the agent with the fewest active tickets (Open, In Progress, Waiting for Customer) wins.
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
- Mail uses the `log` mailer, so every email, including password reset links, is written to `storage/logs/laravel.log`. Set `MAIL_MAILER` and the `MAIL_*` values in `.env` to send real mail.
- There is no Docker setup yet; the app runs on a local PHP and MySQL.

## Development notes
- The app was scaffolded from `laravel/vue-starter-kit` at commit [`fd5b58f`](https://github.com/laravel/vue-starter-kit/commit/fd5b58fb5672cae0152dd04c16ea37d9f52b1cd5); compare against it before pulling kit changes.
- `laravel/boost` provides the `laravel-boost` MCP server (`php artisan boost:mcp`, registered in `.mcp.json`) and the guidelines at the end of `CLAUDE.md`. `boost.json` targets Claude Code only.
- Contributor and agent rules are in [CLAUDE.md](CLAUDE.md).
