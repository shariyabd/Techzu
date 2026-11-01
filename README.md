# Techzu Support: Support Ticket & SLA Management System

A help desk web application. Customers open **tickets** and talk to the support team inside each one. Agents claim, answer and resolve tickets before an **SLA** deadline that counts only business hours. Admins manage the team, SLA targets, holidays and bulk imports, and see everything on a dashboard. Everything the web app does is also available through a versioned REST API.

## Table of contents

- [Quick start (local setup)](#quick-start-local-setup)
- [Docker setup](#docker-setup)
- [Login URL and demo accounts](#login-url-and-demo-accounts)
- [New customer sign-up and email verification](#new-customer-sign-up-and-email-verification)
- [Where emails go locally](#where-emails-go-locally)
- [Background services](#background-services)
- [Features](#features)
- [Tech stack](#tech-stack)
- [Architecture at a glance](#architecture-at-a-glance)
- [REST API quick start](#rest-api-quick-start)
- [Environment variables](#environment-variables)
- [Resetting demo data](#resetting-demo-data)
- [Troubleshooting](#troubleshooting)
- [Tests and quality checks](#tests-and-quality-checks)
- [Project documentation](#project-documentation)

## Quick start (local setup)

**Prerequisites**
- **PHP 8.3+** with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `xml`, `dom`, `tokenizer`, `ctype` (`gd` recommended for avatar resizing)
- **Composer 2**
- **Node.js 24.11+ or 22.18+** with **npm** (use npm; the project ships `package-lock.json`)
- **MySQL 8** with an empty database named `techzu` (`utf8mb4`). MySQL is required: search uses full-text indexes.

**Steps** (run from the project folder)

1. Clone the repository:
   ```bash
   git clone <repository-url> techzu && cd techzu
   ```
2. Create the environment file (`copy .env.example .env` on Windows):
   ```bash
   cp .env.example .env
   ```
3. Set the database lines in `.env`. **`DB_USERNAME` must not be empty**: an empty value does not fall back to `root`.
   ```dotenv
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=techzu
   DB_USERNAME=root
   DB_PASSWORD=
   ```
   Everything else (queue, cache, sessions, mail, Reverb, SLA defaults) already has working local values.
4. Install the PHP dependencies and generate the app key:
   ```bash
   composer install
   php artisan key:generate
   ```
5. Create the tables and seed:
   ```bash
   php artisan migrate --seed
   ```
   Seeding is **required**: it creates the roles and permissions, and sign-up fails without them. Demo data (1 admin, 6 agents, 12 customers, 88 tickets over ~40 days) is added only when `APP_ENV=local`, the default. Don't use `composer run setup`: it migrates without seeding.
6. Install and build the front end:
   ```bash
   npm install
   npm run build
   ```
   The build runs `php artisan wayfinder:generate`, so it must come after `.env`, `vendor/` and the database are ready.
7. Start the app and all background services:
   ```bash
   composer run dev
   ```
8. Open **http://127.0.0.1:8000/login** and sign in with a demo account (password `password`).

## Docker setup

Runs everything (Nginx, PHP 8.3, MySQL 8.4, queue worker, scheduler, Reverb and a Mailpit inbox) with only Docker installed: Docker Desktop on macOS/Windows (WSL 2 backend), or Docker Engine with the Compose plugin on Linux.

```bash
git clone <repository-url> techzu && cd techzu
cp .env.docker.example .env        # Windows PowerShell: copy .env.docker.example .env
docker compose up -d --build
```

The first run builds the images (a few minutes), then generates the app key, migrates and seeds the demo data (about a minute). Later starts keep your data and never re-seed. Check with `docker compose ps`: `app`, `mysql` and `mailpit` are `healthy`; `web`, `queue`, `scheduler` and `reverb` are `Up`. `init` runs once per start and exits, which is normal.

| What | URL |
|---|---|
| App, all three portals (same demo accounts as below) | http://localhost:8000/login |
| Mail inbox (Mailpit): verification, reset and ticket emails | http://localhost:8025 |
| API documentation | http://localhost:8000/docs/api |

| Service | Role | Host port (`.env` setting) |
|---|---|---|
| `web` | Nginx, serves the built assets | `8000` (`APP_PORT`, also update `APP_URL`) |
| `app` | PHP-FPM running Laravel | |
| `queue` | worker for `default,broadcasts,notifications,imports` | |
| `scheduler` | `schedule:work` (SLA scan every minute) | |
| `reverb` | WebSockets for live updates | `8080` (`VITE_REVERB_PORT`, needs `--build` after a change) |
| `mysql` | databases `techzu` and `techzu_testing` (user `techzu` / `secret`) | `127.0.0.1:3307` (`DB_FORWARD_PORT`) |
| `mailpit` | catches all outgoing mail | `8025` (`MAILPIT_UI_PORT`) |

| Task | Command |
|---|---|
| Logs | `docker compose logs -f` (or `logs -f queue`, `app`, `init`, …) |
| Stop / start (keeps containers) | `docker compose stop` / `docker compose start` |
| Shut down (keeps data) | `docker compose down` |
| Rebuild after code changes | `docker compose up -d --build` |
| Artisan | `docker compose exec app php artisan <command>` |
| Fresh demo data | `docker compose exec app php artisan migrate:fresh --seed` |
| PHP tests | `docker compose exec app php artisan test --compact` |
| Full reset (deletes database and uploads) | `docker compose down -v && docker compose up -d --build` |

Leave `DB_HOST=mysql` and `REVERB_HOST=reverb` as they are: containers reach each other by these names. If a port is taken, change its setting in `.env`. If `init` fails, read `docker compose logs init`. On Windows, `$'\r': command not found` means the repo was cloned with CRLF line endings: run `git config --global core.autocrlf input` and clone again. The images contain the built code, so use the local setup for day-to-day development with hot reload.

## Login URL and demo accounts

All three portals share one login page: **http://127.0.0.1:8000/login**. Admins and agents land on `/dashboard`; customers land on `/tickets`.

Every demo account uses the password **`password`** and is already email-verified.

| Role | Email | Name | Notes |
|---|---|---|---|
| Admin | `admin@example.com` | Ada Mensah | full access |
| Agent | `agent1@example.com` | Liam Carter | Billing, Technical Support |
| Agent | `agent2@example.com` | Priya Sharma | Technical Support |
| Agent | `agent3@example.com` | Marcus Chen | General Inquiry |
| Agent | `agent4@example.com` | Sofia Rossi | Billing, General Inquiry; **on leave**, never auto-assigned |
| Agent | `agent5@example.com` | Daniel Okafor | Account & Access, Technical Support |
| Agent | `agent6@example.com` | Hannah Weber | Feature Requests, General Inquiry, Billing |
| Customer | `customer1@example.com` … `customer12@example.com` | | each sees only their own tickets |

Other pages: sign-up `/register`, forgot password `/forgot-password`, API docs `/docs/api`, health check `/up`.

## New customer sign-up and email verification

- Sign-up at `/register` always creates a **Customer** account.
- The new user must verify their email before using the app. They land on a **"Verify your email"** page with a **Resend** button.
- Admin-created and seeded users are already verified.
- The API token endpoint refuses unverified users with `403` and code `email_unverified`.

## Where emails go locally

In Docker, every email lands in Mailpit at http://localhost:8025. In the local setup, with `MAIL_MAILER=log` no real mail is sent. Every email (verification, password reset, ticket notifications) is written to `storage/logs/laravel.log`. Search for `Verify Email Address` and open the link in the **same browser**. Locally the app also shows this hint on the verify page and in the toast after sign-up or resend.

Ticket emails are sent by the queue worker, so they only appear while it is running. To send real mail, set the `MAIL_*` lines to an SMTP server and run `php artisan config:clear`.

## Background services

`composer run dev` starts everything in one terminal:

| Process | Command | Port |
|---|---|---|
| server | `php artisan serve` | 8000 |
| queue | `php artisan queue:listen --queue=default,broadcasts,notifications,imports` | |
| scheduler | `php artisan schedule:work` | |
| reverb | `php artisan reverb:start` | 8080 |
| vite | `npm run dev` | 5173 |
| logs | `php artisan pail` (macOS/Linux with `pcntl`) | |

To run them separately: `php artisan serve`, `php artisan queue:work --queue=default,broadcasts,notifications,imports`, `php artisan schedule:work`, `php artisan reverb:start`, `npm run dev`.

| If this isn't running | Effect |
|---|---|
| Queue worker (or started without all four queues) | no auto-assignment, no emails or in-app notifications, imports stay "queued", no live broadcasts |
| Scheduler | nothing escalates (run `php artisan sla:scan` by hand) and nothing is pruned |
| Reverb | no live updates, typing or viewer indicators; reload the page to see changes |

**Scheduled tasks:** `sla:scan` every minute; `activity:prune` 02:00; `notifications:prune` 02:15; `queue:prune-batches` 02:30; `queue:prune-failed` 02:45.

**Production:** run the queue worker and Reverb under Supervisor or systemd, and add one cron entry:
```
* * * * * cd /path/to/techzu && php artisan schedule:run >> /dev/null 2>&1
```
`php artisan storage:link` is not needed: files live on the private disk and are served through permission-checked routes.

## Features

Three roles: **Admin**, **Agent** and **Customer**. Admins can edit each role's permissions.

**Accounts**
- Sign-up (customers only, with email verification), sign-in, password reset. Admins create agents and admins.
- Profile and avatar. Changing the password signs out other sessions and revokes API tokens.

**Tickets**
- Numbers like `TKT-2026-000001`; the counter restarts yearly and numbers never collide.
- Five statuses (Open, In Progress, Waiting for Customer, Resolved, Closed) with enforced transitions. Claiming an Open ticket moves it to In Progress; a customer reply reopens a Waiting or Resolved ticket.
- Four priorities (Low, Medium, High, Critical) that set the SLA; admin-managed categories.
- Customers see only their own tickets; any other ticket returns "not found".

**Assignment**
- Auto-assignment to the available agent in the ticket's category with the fewest open tickets; admins are notified when nobody fits.
- Race-safe claim of unassigned tickets; admin assign/reassign; agent hand-off within the category.
- Sidebar queues (All active, Assigned to me, Unassigned, Resolved & closed, per category) with live counts.

**Conversation**
- Replies and **internal notes**. Customers never see internal notes anywhere: pages, API, emails, history or live updates.
- Attachments: up to 5 files of 10 MB per message, stored privately, downloaded after a permission check, images previewed inline.
- Saved replies (personal and team) with placeholders such as `{customer_name}` and `{ticket_number}`.

**SLA and escalation**
- Resolution targets by priority (Critical 2 h, High 8 h, Medium 24 h, Low 48 h) counted in business hours only (default Sunday–Thursday 09:00–18:00, Asia/Dhaka), skipping holidays.
- The clock pauses while waiting for the customer and keeps the remaining time.
- SLA state (Within, Approaching, Breached, Paused, Stopped) with a live countdown.
- A per-minute scan warns at 75 % (assigned agent, or admins if unassigned) and alerts the agent and all admins on breach. Customers are never told.
- Admins edit targets, threshold, timezone, business-hour slots and holidays, optionally recalculating open tickets.

**Notifications and live updates**
- Email and in-app notifications (new ticket, assigned, needs assignment, reply, internal note, resolved, SLA warning, SLA breach, import finished). The bell updates live with a chime.
- Ticket pages and queues refresh by themselves and show who else is viewing and who is typing.

**Insight and administration**
- Search and filters: full-text keywords, status, priority, category, agent, customer, date range, SLA state, "unresponded", "awaiting reply", eight sort orders.
- Ticket-number search: the header search (`Ctrl/⌘ K` or `/`) and the list's Ticket ID filter accept `TKT-2026-000001`, lower case, or `2026-000001`. A number lookup searches all statuses; customers only find their own tickets.
- Staff dashboard: headline counts, SLA health, breakdowns, 14-day created-vs-resolved trend, "My tickets".
- Agents directory with availability, categories, last seen and click-through workload.
- CSV import (admins) in the background with live progress, a rejected-rows report, a sample file and optional auto-assign.
- Per-ticket activity history; staff-only entries are hidden from customers.
- Users, roles, agent categories and availability, and a per-role permission matrix.

**API**
- REST API at `/api/v1` with Sanctum bearer tokens, the same rules as the web app, and interactive docs at `/docs/api`.

## Tech stack

| Layer | Technology |
|---|---|
| Server | PHP 8.3+, Laravel 13 |
| Database | MySQL 8 (full-text search) |
| Front end | Inertia 3, Vue 3.5 (`<script setup lang="ts">`), TypeScript 5.9 |
| UI | Tailwind CSS 4, shadcn-vue (reka-ui) |
| Build | Vite via vite-plus 1.4, Laravel Wayfinder (typed routes) |
| Real-time | Laravel Reverb 1.12, Laravel Echo |
| Auth and permissions | Laravel Sanctum 4, spatie/laravel-permission 8 |
| Activity history | spatie/laravel-activitylog 4 |
| API docs | dedoc/scramble (OpenAPI) |
| Queue, cache, sessions | Laravel database drivers |
| Tests and quality | Pest 4, Vitest, Larastan level 6, Pint |

## Architecture at a glance

Web pages and `/api/v1` share the same FormRequests, Policies and **Actions**.
- **Controllers** are thin: authorize, validate, call one Action, return a page or a JSON resource.
- **Actions** are the only code that changes data. Each runs in a transaction and dispatches a domain event.
- **Listeners** hold every side effect: activity history, notifications, auto-assignment and broadcasts.

Slow work runs on database queues (`default`, `notifications`, `imports`, `broadcasts`). The scheduler runs the SLA scan every minute and nightly cleanups. Reverb pushes small "this changed" signals; the browser then reloads data through normal permission-checked requests, so nothing private travels over the WebSocket.

Details: [docs/03-architecture.md](docs/03-architecture.md).

## REST API quick start

- Base URL `http://127.0.0.1:8000/api/v1`; send `Accept: application/json`.
- Rate limits: 60 requests/min per user; the token endpoint 5/min.
- Docs: `/docs/api` (open in `local`, admin-only elsewhere), JSON at `/docs/api.json`, exported copy at [docs/api/openapi.json](docs/api/openapi.json).

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/tokens \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"agent1@example.com","password":"password","device_name":"my-laptop"}'
```
Returns `201` with `token`, `token_type: "Bearer"` and `abilities`. Then:
```bash
curl http://127.0.0.1:8000/api/v1/me -H "Accept: application/json" -H "Authorization: Bearer <token>"
```
Revoke the token with `DELETE /api/v1/auth/tokens/current`. More: [docs/07-api.md](docs/07-api.md).

## Environment variables

After editing `.env`, run `php artisan config:clear`. After changing any `VITE_*` value, rebuild the front end.

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY` | | set by `php artisan key:generate` |
| `DB_*` | | MySQL connection; `DB_USERNAME` must not be empty |
| `APP_ENV` | `local` | `local` enables demo seeding, open API docs and local email hints |
| `APP_URL` | `http://127.0.0.1:8000` | base URL for links in emails |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `database` | stored in MySQL |
| `BROADCAST_CONNECTION` | `reverb` | `null` disables live updates |
| `MAIL_MAILER` | `log` | emails go to `storage/logs/laravel.log` |
| `REVERB_*`, `VITE_REVERB_*` | local values | Reverb credentials and host/port (8080), ready to use |
| `BUSINESS_TIMEZONE` | `Asia/Dhaka` | business hours and ticket-number year |
| `TICKET_NUMBER_PREFIX` | `TKT` | ticket number prefix |
| `TICKETS_AUTO_ASSIGN` | `true` | auto-assign new tickets |
| `SLA_MINUTES_LOW` / `MEDIUM` / `HIGH` / `CRITICAL` | `2880` / `1440` / `480` / `120` | default SLA targets in business minutes |
| `SLA_WARNING_THRESHOLD_PERCENT` | `75` | when "approaching" starts |
| `ATTACHMENTS_MAX_KB`, `ATTACHMENTS_MAX_FILES` | `10240`, `5` | attachment limits |

The remaining lines in `.env.example` are tuning values you rarely need to change.

## Resetting demo data

```bash
php artisan migrate:fresh --seed
php artisan optimize:clear
```

This wipes the `techzu` database and rebuilds the demo, with dates relative to today. `php artisan db:seed` on an existing demo does nothing (the demo seeder skips when `admin@example.com` exists). SLA states drift as time passes, so re-seed shortly before a demo.

## Troubleshooting

| Problem | Fix |
|---|---|
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL isn't running or `DB_PORT` is wrong. |
| `SQLSTATE[HY000] [1045] Access denied` | Wrong or empty `DB_USERNAME` / `DB_PASSWORD`; fix, then `php artisan config:clear`. |
| `could not find driver` / `requires ext-…` | A PHP extension is missing; check `php -m` and that the terminal uses the right `php -v`. |
| `npm run build` fails with a PHP or Wayfinder error | `.env`, `vendor/` or the database isn't ready; finish steps 2–5 first. |
| `npm install` warns `EBADENGINE` | Node.js is too old; use 24.11+ or 22.18+ and reinstall `node_modules`. |
| No styles / "Vite manifest not found" | Run `npm run build` or keep `composer run dev` running. |
| Sign-up fails: "There is no role named customer" | The database was migrated without seeding; run `php artisan db:seed`. |
| Stuck on "Verify your email" | Open the link from `storage/logs/laravel.log` in the same browser, or click **Resend**. |
| Tickets not assigned, no notifications, imports stuck "queued" | Queue worker not running, or started without all four queues. |
| Nothing updates live | Reverb isn't running, or the worker skips the `broadcasts` queue. |
| Nothing escalates | Scheduler isn't running. |
| Port 8000, 8080 or 5173 in use | Free it, or use `php artisan serve --port=8001` (and set `APP_URL`); for Reverb change `REVERB_PORT` / `REVERB_SERVER_PORT` and rebuild. |

## Tests and quality checks

Create an empty `techzu_testing` database (`utf8mb4`), then:

```bash
php artisan test --compact        # PHP tests (Pest)
./vendor/bin/pest --parallel      # faster; the MySQL user needs CREATE DATABASE rights
npm run test:js                   # front-end tests
```

Quality checks (also run in CI):

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
npm run build && npm run check && npx vue-tsc --noEmit
```

## Project documentation

| Document | What it covers |
|---|---|
| [docs/README.md](docs/README.md) | index and reading order |
| [docs/01-requirements.md](docs/01-requirements.md) | the original brief |
| [docs/02-product-requirements.md](docs/02-product-requirements.md) | users, requirements, scope |
| [docs/03-architecture.md](docs/03-architecture.md) | layers, request flow, events, jobs, realtime, front end |
| [docs/04-business-logic.md](docs/04-business-logic.md) | every business rule in plain language |
| [docs/05-database-design.md](docs/05-database-design.md) | tables, relations, indexes |
| [docs/06-security-and-authorization.md](docs/06-security-and-authorization.md) | permission matrix, visibility rules, files, rate limits |
| [docs/07-api.md](docs/07-api.md) | the REST API |
| [docs/08-background-processing-and-realtime.md](docs/08-background-processing-and-realtime.md) | queues, scheduler, escalation, imports, Reverb |
| [docs/09-testing-strategy.md](docs/09-testing-strategy.md) | what is tested and why |
| [docs/10-performance-and-operations.md](docs/10-performance-and-operations.md) | caching, pagination, retention, deployment |
| [docs/11-technical-decisions.md](docs/11-technical-decisions.md) | decision log (TD-xxx) |
| [docs/12-traceability-matrix.md](docs/12-traceability-matrix.md) | requirement → rule → code → tests |
| [docs/13-demo-data.md](docs/13-demo-data.md) | what the demo seeder creates |
| [docs/14-core-features-guide.md](docs/14-core-features-guide.md) | beginner-friendly walkthrough of every feature |

Contributor rules: [CLAUDE.md](CLAUDE.md).
