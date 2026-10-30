# Roadmap and execution rules

T00–T05 are done and on `main`: the kit, session auth and Sanctum tokens, the schema and models, roles and permissions, ticket numbers, the business calendar and SLA, the policies, and ticket create/list/show (web and API). Their plans live in git history. This file and the six plans below are everything that remains.

## Plans, in order

| # | Plan | Delivers | Depends on |
|---|---|---|---|
| 1 | `P1-setup-fixes.md` | Simpler migrations and config, dependency cleanup, the two-command dev setup, `README.md`, agent test databases | — |
| 2 | `P2-authentication.md` | The complete access system: login, logout, register, forgot/reset password, user and role management, the read-only permission matrix, route protection, Sanctum tokens, and every page for these | P1 |
| 3 | `P3-ticket-operations.md` | Assignment (manual, claim, automatic), conversation (replies, internal notes, reply attachments, secure downloads), search and filters: backend and API | P2 |
| 4 | `P4-automation-admin.md` | SLA escalation, notifications and the inbox API, CSV import, categories, holidays, dashboard statistics: backend and API | P3 |
| 5 | `P5-ui.md` | All remaining UI in one consistent pass | P4 |
| 6 | `P6-hardening-release.md` | Index audit, N+1 guards, security checks, Scramble API docs, final docs | P5 |

Plans run **one at a time, in this order, on `main`**, in one session per plan. Parallelism lives inside a plan: the agents of one wave run at the same time.

## Execution order

Each plan runs **solo**, one after another. Plans never run in parallel: they share one checkout, and each plan's commits are approved before the next plan starts. The parallel work happens **inside** each plan, in its waves.

| Step | Plan file | Mode | Inside the plan |
|---|---|---|---|
| 1 | `P1-setup-fixes.md` | solo | W1: P1.1 ∥ P1.2 |
| 2 | `P2-authentication.md` | solo | W1: P2.1 ∥ P2.2 → W2: P2.3 ∥ P2.4 |
| 3 | `P3-ticket-operations.md` | solo | W1: P3.1 ∥ P3.2 ∥ P3.3 → W2: P3.4 |
| 4 | `P4-automation-admin.md` | solo | W1: P4.1 ∥ P4.2 ∥ P4.3 ∥ P4.4 ∥ P4.5 → W2: P4.6 |
| 5 | `P5-ui.md` | solo | W1: P5.1 → W2: P5.2 ∥ P5.3 ∥ P5.4 ∥ P5.5 ∥ P5.6 ∥ P5.7 → W3: P5.8 |
| 6 | `P6-hardening-release.md` | solo | W1: P6.1 ∥ P6.2 ∥ P6.3 → final review |

`∥` means the subtasks run at the same time as parallel subagents; `→` means the next wave waits for the previous one.

## Owner decisions (2026-10-29)
- No worktrees and no task branches. Everything happens on `main` in the main checkout.
- Nothing is committed until the owner approves the plan's changes. Then the session creates one commit per subtask.
- Email verification is omitted. Password reset is included; mail uses the `log` mailer, so the reset link lands in `storage/logs/laravel.log`.
- Roles are fixed (`Admin`, `Agent`, `Customer`). Admins change a user's role. Permissions are defined in code and the seeder, and are shown read-only.
- The local `.env` uses `QUEUE_CONNECTION=sync`, so `php artisan serve` and `npm run dev` are enough.
- During development, migrations are edited in place instead of adding follow-up migrations, and the dev database is rebuilt with `php artisan migrate:fresh --seed`. After P1, plans add no migrations unless they create a new table.

## How a session executes a plan

1. **Read** this file, `CLAUDE.md` and the plan. Check that the working tree is clean (`git status`). If it isn't, stop and ask.
2. **Run the waves** (`subagent-driven-development` skill). The plan's **Waves** table is the single source for waves, models and parallelism; the prompt never repeats it. For each wave, dispatch one implementer subagent per subtask, all in one message so they run in parallel, with the model the plan names. Give each one its subtask section, the plan's Global Constraints, and its agent number `N` (1–8). Wait for the whole wave before starting the next.
3. **Implementer rules:**
   - Touch only the files the subtask lists. If another file must change, stop and report.
   - Work test-first. Run **only your own test files**: `DB_DATABASE=techzu_testing_agent<N> ./vendor/bin/pest <your test files>`.
   - Then run `./vendor/bin/pint <your PHP files>`.
   - Don't run the full suite, `npm run build`, `vue-tsc` or `phpstan`. Don't commit, and don't touch git.
   - Report the files you changed, the tests you ran, and anything you dropped or changed from the plan.
4. **Gate, once.** After the last wave, the session runs it once:
   ```bash
   ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G && ./vendor/bin/pest --parallel
   npm run build && npm run check && npx vue-tsc --noEmit   # only if resources/js changed
   ```
5. **Review, in parallel** (one message). Reviewers read the uncommitted diff (`git diff`, plus `git status` for new files):
   - **Spec reviewer** (opus): every acceptance criterion holds; nothing is missing or extra.
   - **Quality reviewer** (opus): simplicity, Laravel conventions, CLAUDE.md rules (no comments, thin controllers, explicit `$fillable`), redundant or unnecessary tests.
   - **Security and test reviewer** (opus): authorization on every route, the internal-note boundary, 404 versus 403, and missing tests that are actually necessary.
   - **Documenter** (sonnet): writes this plan's sections of `docs/FEATURES.md`, describing behaviour only.
6. **Fix.** Send each confirmed finding to an implementer subagent. Rerun only the affected test files, plus `pint --test` and `phpstan`. Run the full suite again only if a fix touched code shared by other features.
7. **Browser check** (only for plans that change UI): Playwright MCP against `php artisan serve --port=8010`, using the seeded demo accounts. Stop the server afterwards.
8. **Report and stop.** Report:
   - what was built
   - the gate and review results, and any limitations
   - for each subtask, its files and a proposed commit message

   **Wait for the owner's approval.**
9. **Commit, after approval.** For each subtask in plan order, run `git add <its files>`, then `git commit` with the Conventional Commit message and the `Refs: Pn.m` / `Implements: TD-…` trailers (CLAUDE.md §Commits). No AI attribution. The owner pushes.
10. **Before finishing,** run `pgrep -af "mysqld|artisan serve|vite|queue:work"` and stop anything the session started.

## Shared files

A file that several features need belongs to exactly one subtask, which the plan names. In particular:
- Route authorization tests (`tests/Feature/Authorization/*RoutesAuthorizationTest.php`, `AuthorizationMatrixTest.php`) and `tests/Support/RouteAudit.php` (`bindings()` for every new route parameter) belong to each plan's last wave.
- `routes/console.php` belongs to P4's escalation subtask.
- On the frontend, P2 owns `resources/js/types/users.ts` and `resources/js/config/navigation/admin.ts` (the users and roles entries). P5 wave 1 owns every other new file under `resources/js/types/` and `resources/js/config/navigation/`, and may add the remaining admin entries to `admin.ts`.

Inside a wave, no two subtasks touch the same file.

## Agent test databases

P1 creates `techzu_testing_agent1` … `techzu_testing_agent8` on the local MySQL server, and they are reused by every plan. `pest --parallel` creates its own `techzu_testing_test_N` databases. Don't start another `mysqld`.
