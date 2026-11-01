# 10 · Performance and operations

How the app stays fast as tables grow, what is cached and when the cache is thrown away, and what to know when running it for real. Decisions: TD-021, TD-080, TD-100, TD-101, TD-102 in [11 · Technical decisions](11-technical-decisions.md).

## 1. Query rules

| Rule | How it is enforced |
|---|---|
| No N+1 queries | `Model::preventLazyLoading()` is on outside production, so a lazy load throws in development and tests. Relations are eager-loaded with column lists (`category:id,name`); counts use `withCount` / `withExists`. |
| Constant query counts | `tests/Feature/Performance/*QueryCountTest.php` render lists with few and many rows and assert the same number of queries (ticket index, show page, API list, admin lists, agents directory). |
| Every list paginated | `per_page` defaults to 20 and is capped at 100 (`TICKETS_PER_PAGE`, `TICKETS_PER_PAGE_MAX`). The conversation and timeline are paged too. |
| Unbounded work streamed | scans, recalculation, imports and prunes use `chunkById` / `lazyById`; nothing calls `get()` on an unbounded set. |
| Index-backed hot paths | every hot query has a matching index (see [05 · Database design](05-database-design.md#4-indexes-td-021)); `tests/Feature/Performance/IndexUsageTest.php` checks the plans with `EXPLAIN` on a few thousand tickets. |
| Aggregates in SQL | dashboard totals are one conditional-aggregate query (`SUM(CASE …)`); breakdowns are grouped queries; the agents directory joins one grouped subquery. |
| Keyword search | MySQL FULLTEXT in boolean mode with sanitized prefix terms; `LIKE` only as a fallback on other drivers. |

**Known trade-offs**
- Offset pagination: very deep pages get slower; cursor pagination is the fix if that ever matters.
- `COUNT(*)` for totals costs something on huge filtered sets.
- FULLTEXT ignores words shorter than three characters; number search covers short lookups.

## 2. Caching and invalidation

| What | Key | Invalidated by | Backstop |
|---|---|---|---|
| Dashboard totals and breakdowns | one entry, stamped with the ticket-data version and the business timezone | a new ticket-data version, or a timezone change | `DASHBOARD_CACHE_TTL_SECONDS` (60) |
| Queue counts (tabs and badges) | one entry per visibility scope (`all`, or one customer), stamped with the ticket-data version | a new ticket-data version | `TICKETS_QUEUE_COUNTS_CACHE_TTL_SECONDS` (60) |
| SLA settings | `sla.settings`, forever | saving SLA settings | none needed |
| Holidays | `sla.holidays`, forever | creating, editing or deleting a holiday | none needed |
| Last-seen throttle | `users.{id}.last-seen`, 60 s | expiry | |
| Permissions | spatie permission cache | editing the permission matrix (cache reset) | |

**The ticket-data version.** `TicketDataVersion` keeps one random identifier in the cache. `RotateTicketDataVersion` replaces it whenever a ticket is created, assigned, unassigned, escalated, has its SLA recalculated, or changes status, priority or category. `TicketDataCache` stores each cached value with the version it was computed under; a read with a different version recomputes and overwrites, so stale entries are never served and old keys do not pile up. The TTL is a backstop for counts that change purely with time (a ticket crossing its due time without any event).

Per-ticket pages, lists and "My tickets" are never cached.

## 3. Pagination limits

| List | Default | Max |
|---|---|---|
| Tickets (web and API) | 20 | 100 |
| Conversation | 20 | 100 |
| Timeline | 20 | 100 |
| Users, agents, saved replies, notifications | 20 | 100 |
| Holidays | 50 per page, one year at a time | |
| Import errors | paginated | 100 |
| Ticket page "load older" | up to `TICKETS_SHOW_MAX_PAGES` (50) pages | |

## 4. Retention

| Data | Kept for | Pruned by | How |
|---|---|---|---|
| Activity entries | 365 days | `activity:prune`, daily 02:00 | chunked deletes |
| Read notifications | 90 days (`NOTIFICATIONS_RETENTION_DAYS`); unread kept | `notifications:prune`, daily 02:15 | chunked deletes (`PRUNE_CHUNK_SIZE`, 1000) |
| Finished job batches | 168 hours (`JOB_BATCHES_RETENTION_HOURS`) | `queue:prune-batches`, daily 02:30 | framework command |
| Failed jobs | 168 hours (`FAILED_JOBS_RETENTION_HOURS`) | `queue:prune-failed`, daily 02:45 | framework command |

Tickets, replies and attachments are never pruned.

## 5. Deployment notes

**Environment**
- `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, HTTPS (`APP_URL=https://…`). Browser-history encryption needs a secure origin.
- Real mail: set `MAIL_MAILER` and the `MAIL_*` values; development uses the `log` mailer.
- Choose your own `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, and set `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` to the public WebSocket address (usually 443/https behind a proxy). Rebuild the frontend afterwards: the `VITE_REVERB_*` values are compiled in.
- `REVERB_ALLOWED_ORIGINS` should list your domain instead of `*`.
- Storage: `ATTACHMENTS_DISK`, `AVATARS_DISK` and `IMPORTS_DISK` default to the private `local` disk; on several servers use a shared disk such as S3.

**Build and release**
```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=SlaDefaultsSeeder --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart
```
`RolesAndPermissionsSeeder` is idempotent (it re-applies `Permission::forRole()`). `SlaDefaultsSeeder` writes the config defaults only when no SLA settings are stored. The demo seeder runs only in `local` and `testing`.

**Long-running processes** (keep them alive with Supervisor, systemd or your platform's equivalent)
- `php artisan queue:work --queue=default,broadcasts,notifications,imports`, several workers; consider a separate worker pool for `imports`.
- `* * * * * php artisan schedule:run` (cron). `sla:scan` uses `onOneServer()`, which needs a shared cache store across servers (the `database` store qualifies).
- `php artisan reverb:start` behind a TLS-terminating proxy.

**Safety nets**
- `DB::prohibitDestructiveCommands()` blocks `migrate:fresh`, `db:wipe` and similar in production.
- Domain errors are not reported to the log; real exceptions are. Failed jobs log a line and land in `failed_jobs`.

**Docker**
- A local Docker Compose stack ships with the repository (`compose.yaml`, a multi-stage `Dockerfile`, `docker/entrypoint.sh`, `docker/nginx`, `docker/php`, `docker/mysql/init`, and `.env.docker.example` to copy to `.env`). It is meant for running the app locally, not as a production setup.
- Services: `web` (Nginx, port 8000), `app` (PHP-FPM 8.3), `init` (runs once per start: generates the app key, migrates, and seeds the demo data on the first run only), `queue` (`queue:work` on `default,broadcasts,notifications,imports`), `scheduler` (`schedule:work`), `reverb` (port 8080), `mysql` (8.4, also creates the testing database) and `mailpit` (catches all mail; inbox at http://localhost:8025).
- Data lives in named volumes (`mysql-data`, `storage`), so restarts keep it. Commands and troubleshooting: README → Docker setup.

## 6. Monitoring checklist

- `failed_jobs` stays empty; `php artisan queue:failed` lists entries.
- The `jobs` table does not grow (a worker is running).
- The scheduler runs: `sla:scan` logs a summary line each minute (`SLA scan at …: N approaching, N breached, N redelivered.`).
- Reverb is reachable from browsers (the ticket page shows presence avatars).
