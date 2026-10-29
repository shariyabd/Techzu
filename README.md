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
