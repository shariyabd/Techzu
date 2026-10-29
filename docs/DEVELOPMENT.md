# Development runbook

## Starter kit (TD-002)
Scaffolded with `composer create-project laravel/vue-starter-kit:dev-main` at commit [`fd5b58fb5672cae0152dd04c16ea37d9f52b1cd5`](https://github.com/laravel/vue-starter-kit/commit/fd5b58fb5672cae0152dd04c16ea37d9f52b1cd5). The kit has no tagged Laravel 13 release, so this commit is its version: compare against it before pulling kit changes. `create-project` ran the kit's `install:features` hook with its defaults (registration, email verification, two-factor auth, passkeys and password confirmation all kept).

## Local runtime (TD-005)
Development runs on the owner's machine; there is no Sail, Docker, Redis or Mailpit. Docker packaging is a separate final task after T14.

| | Local |
|---|---|
| Database | system MySQL 8 at `127.0.0.1:3306`: `techzu` (dev), `techzu_testing` (tests), per-worktree `techzu_wt_txx` / `techzu_wt_txx_testing` |
| Web server | Apache 2.4 + a dedicated PHP 8.3-FPM pool, serving the main checkout at <http://techzu.test> |
| Queue / cache / session | `database` |
| Mail | `log` (mail is written to `storage/logs/laravel.log`) |

`.env.example` holds exactly these values; only `DB_USERNAME` and `DB_PASSWORD` are left blank. Agent worktrees can't use the Apache vhost (it points at the main checkout), so they serve themselves with `php artisan serve --port=80NN` for browser checks and stop it when done.

## Apache virtual host (one-time, owner runs with sudo)
```bash
# 1. A PHP 8.3-FPM pool that runs as you, so PHP can write storage/ and bootstrap/cache.
sudo apt install php8.3-fpm
sudo tee /etc/php/8.3/fpm/pool.d/techzu.conf >/dev/null <<'EOF'
[techzu]
user = shariya
group = shariya
listen = /run/php/php8.3-fpm-techzu.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
EOF
sudo systemctl restart php8.3-fpm

# 2. Apache sends PHP for this vhost only to that pool; other sites keep their mod_php (8.1).
sudo a2enmod proxy_fcgi setenvif rewrite
sudo tee /etc/apache2/sites-available/techzu.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName techzu.test
    DocumentRoot /home/shariya/techzu/public
    <Directory /home/shariya/techzu/public>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm-techzu.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog ${APACHE_LOG_DIR}/techzu-error.log
    CustomLog ${APACHE_LOG_DIR}/techzu-access.log combined
</VirtualHost>
EOF

# 3. Let Apache traverse your home directory (execute only; it can't list or read it).
sudo setfacl -m u:www-data:x /home/shariya

# 4. Enable the site and the hostname.
sudo a2ensite techzu
grep -q 'techzu.test' /etc/hosts || echo '127.0.0.1 techzu.test' | sudo tee -a /etc/hosts
sudo apache2ctl configtest && sudo systemctl reload apache2
```
Check: `curl -sI http://techzu.test/login | head -1` prints `HTTP/1.1 200 OK` once T00 has landed and `npm run build` has run.

## Workers
Apache serves only HTTP requests. Queued jobs (auto-assignment, notifications, CSV imports) and the scheduler (`sla:scan` every minute once T09 lands) need two processes, each run by hand in its own terminal:
```bash
php artisan queue:work --queue=default,notifications,imports   # TD-072 queue order
php artisan schedule:work
```
After changing jobs or listeners, run `php artisan queue:restart` (or stop and start the worker), because `queue:work` keeps the old code in memory.

`composer run dev` doesn't replace them. It runs `php artisan dev`, which starts `serve`, `queue:listen --tries=1 --timeout=0` on the `default` queue only, `pail` and Vite: nothing would process `notifications` or `imports`, and nothing runs the scheduler. With Apache serving the app, use `npm run dev` for Vite hot reload, or `npm run build`. Agents stop every worker, `serve` or Vite process they start before they finish (CLAUDE.md §Databases and background servers).

## Tests
Tests always run on MySQL. `phpunit.xml` sets `DB_DATABASE=techzu_testing` and `TICKETS_AUTO_ASSIGN=false`. Pest 4 runs both Pest closures and the kit's PHPUnit test classes.

```bash
./vendor/bin/pest --parallel        # every suite: Unit, Feature, Concurrency, Database
./vendor/bin/pest tests/Concurrency  # one suite
```

`phpunit.xml` `<env>` values take precedence over `.env`. A worktree therefore selects its own test database on the command line, where shell variables win:
```bash
DB_DATABASE=techzu_wt_t05_testing ./vendor/bin/pest --parallel
```

## Gate (must pass before every commit)
```bash
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
npm run build && npm run check && npx vue-tsc --noEmit   # if resources/js changed
```
`vue-tsc` and `npm run check` (`vp check`: lint plus the kit's formatter; `npx vp check --fix` fixes formatting) run after `npm run build`, which generates Wayfinder's gitignored `resources/js/{routes,actions,wayfinder}`. On a fresh checkout, run `npm run build` or `php artisan wayfinder:generate --with-form` first, or `vue-tsc` fails on the kit's own files.

## Agent output (laravel/pao)
The kit ships `laravel/pao`. When Pest, Pint or PHPStan run under an AI agent, they print a one-line JSON summary instead of the usual report, for example `{"tool":"pest","result":"passed","tests":77,...}` or `{"tool":"phpstan","result":"passed","errors":0}`. `"result":"passed"` is the agent equivalent of `PASS` / `[OK] No errors`. Humans in a terminal see the normal output.

## Databases and credentials
- **Credentials live only in `.env`** (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`), never in docs or commits. On the owner's machine they are copied from the gitignored `prompts/local.env`.
- **Local MySQL:** create a user once, as a MySQL admin:
  `CREATE USER 'techzu'@'localhost' IDENTIFIED BY '<password>'; GRANT ALL PRIVILEGES ON \`techzu%\`.* TO 'techzu'@'localhost';`
- **Per-worktree databases** (create when a worktree starts, drop after its task lands):
```bash
env_val() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
MYSQL_PWD="$(env_val DB_PASSWORD)" mysql -h "$(env_val DB_HOST)" -P "$(env_val DB_PORT)" -u "$(env_val DB_USERNAME)" \
  -e "CREATE DATABASE IF NOT EXISTS techzu_wt_t05; CREATE DATABASE IF NOT EXISTS techzu_wt_t05_testing;"
# drop: same command with DROP DATABASE IF EXISTS ...
```

## Laravel Boost
`laravel/boost` (dev) appended its guidelines to `CLAUDE.md` and registered the `laravel-boost` MCP server (`php artisan boost:mcp`) in `.mcp.json`. `boost.json` pins it to Claude Code (`"agents": ["claude_code"]`), so re-running `php artisan boost:install --guidelines --mcp --no-interaction` (or `boost:update`) never creates `AGENTS.md` or `.agents/`. `.claude/settings.json` lists the enabled `.mcp.json` servers (`enabledMcpjsonServers`); the repo's settings already enable `playwright` and `laravel-boost`.
