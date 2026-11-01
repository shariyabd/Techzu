# 09 · Testing strategy

What we test, why, and how to run it. Decisions: TD-030 to TD-032 in [11 · Technical decisions](11-technical-decisions.md). The [traceability matrix](12-traceability-matrix.md) maps every requirement to its tests.

## 1. Principles

- **Test the risks, not the framework.** Every test proves one of: a business rule from the brief, an acceptance criterion, a security boundary (authorization, internal-note visibility, 404 versus 403), or a failure mode that can really happen (races, compensation paths, duplicate scans). Eloquent casts, relationship definitions, package internals and single-field `required` rules are not tested.
- **One rule, one place.** A rule is tested once at the Action or Service. Endpoint tests cover only the HTTP mapping (status, resource shape) and authorization.
- **Datasets for matrices and edge cases.** Role × route matrices, calendar edge cases and import row variants are Pest datasets, so a new case is one line.
- **Real MySQL** (TD-031). Locking, conditional updates, FULLTEXT search and `EXPLAIN` plans behave differently on SQLite, so every database test runs on MySQL 8.

## 2. What is tested, by risk

| Risk | Why it matters | How it is tested | Main files |
|---|---|---|---|
| SLA and business-hours math | a wrong deadline is a wrong breach | pure unit tests with datasets: the brief's Thursday 17:00 example, weekends, holidays, before and after hours, exact boundaries, multi-day spans, lunch-break windows, pause/resume, reopen, priority change with credited pauses, the 366-day guard | `tests/Unit/Sla/BusinessCalendarTest.php`, `tests/Unit/Sla/SlaCalculatorTest.php`, `tests/Feature/Sla/StoppedSlaClockTest.php` |
| Authorization | data leaks and privilege escalation | a route-by-route matrix over every web and API route and every role, plus a route audit that fails when a new route has no matrix row; policy tests; 404-versus-403 rows | `tests/Feature/Authorization/AuthorizationMatrixTest.php`, `tests/Support/AuthorizationMatrix.php`, `tests/Support/RouteAudit.php`, `tests/Feature/Authorization/*PolicyTest.php` |
| Internal-note leakage | the brief's hardest rule | every page, endpoint, notification, activity entry and broadcast is checked for a customer viewer; the resource refuses to serialize an internal reply even if a query forgot its scope; editing the permission matrix keeps every surface consistent | `tests/Feature/Security/InternalNoteLeakageTest.php`, `tests/Feature/Notifications/InternalNoteEndToEndTest.php`, `tests/Feature/Security/PermissionScopedVisibilityTest.php`, `tests/Feature/Realtime/TicketBroadcastsTest.php` |
| Concurrency | double assignment, duplicate numbers | two real database connections (TD-032): claim race with exactly one winner and one 409; a claim racing a manual assignment waits and loses; parallel ticket numbers unique and rolled-back numbers reused | `tests/Concurrency/ClaimTicketConcurrencyTest.php`, `tests/Concurrency/AssignTicketConcurrencyTest.php`, `tests/Concurrency/TicketNumberConcurrencyTest.php` |
| Assignment rules | wrong agent, overloaded agent | eligibility (role, availability, category, admin override), least load, round-robin tie-break, back-off when claimed meanwhile, Open → In Progress | `tests/Feature/Assignment/*.php` |
| Status machine and SLA side effects | invalid states, unfair breaches | every allowed and forbidden transition; pause/resume; reopen restores remaining time; customer reply reopens a Resolved ticket | `tests/Unit/Enums/TicketStatusTest.php`, `tests/Feature/Actions/Tickets/ChangeTicketStatusTest.php`, `tests/Feature/Conversation/CustomerReplyReopensTicketTest.php` |
| Escalation | missed or duplicated alerts | thresholds, idempotency (scan twice, one notification), lowering and re-escalation, at-least-once redelivery after a lost announcement, the schedule definition | `tests/Feature/Sla/ScanSlaCommandTest.php`, `tests/Feature/Sla/EscalationRedeliveryTest.php`, `tests/Feature/Sla/ScanSlaActivityTest.php`, `tests/Feature/Sla/ScanSlaScheduleTest.php` |
| SLA settings | broken calendars, silent changes | validation rules, cached repository and fallback, recalculation only on request, one ticket at a time | `tests/Feature/Admin/SlaSettingsManagementTest.php`, `tests/Feature/Sla/SlaSettingsRepositoryTest.php`, `tests/Feature/Sla/RecalculateActiveTicketSlasTest.php` |
| CSV import | memory, bad rows, duplicates | valid, invalid and duplicate rows (reference, created-at, fingerprint); counters; error report and CSV download; streaming memory test; chunk failure; side effects (no per-ticket mail, auto-assignment) | `tests/Feature/Imports/*.php`, `tests/Unit/Imports/TicketCsvReaderTest.php` |
| Notifications | wrong recipients, self-notification | recipients and channels per event with `Notification::fake()`; one customer never receives another's notification | `tests/Feature/Notifications/NotificationRecipientsTest.php`, `NotificationInboxTest.php` |
| Query efficiency | N+1 queries, full scans | query counts that stay flat as rows grow (ticket list, show page, dashboard, agents directory); `EXPLAIN` checks on a few thousand tickets | `tests/Feature/Performance/*.php` |
| Realtime | leaking channels | channel authorization per role, presence member info, identifier-only payloads, internal notes only on the staff channel | `tests/Feature/Realtime/*.php` |
| Files | disguised executables, leaks | content-sniffed types, private storage, compensation on failure, download headers, avatar processing and headers | `tests/Feature/Attachments/AttachmentStorerTest.php`, `tests/Feature/Conversation/AttachmentDownloadTest.php`, `tests/Feature/Profile/AvatarTest.php` |
| Security checklist | regressions in cross-cutting rules | auth on every route, guest-only routes, uploads throttled, no `v-html`, privileged fields ignored | `tests/Feature/Security/SecurityChecklistTest.php` |
| Email verification | unverified accounts reaching the app or the API | registration leaves the customer unverified; every app page and the broadcasting handshake send an unverified user to the notice; signed link and hash checks; resend throttling; admin-made users pre-verified; API token refused with `email_unverified` | `tests/Feature/Auth/EmailVerificationTest.php` |
| Schema | missing indexes, wrong delete rules | every index and foreign-key rule against `information_schema` | `tests/Feature/Database/SchemaTest.php` |
| Demo data | a broken presentation | the seeder runs, is idempotent and produces the advertised scenarios | `tests/Feature/Database/DemoSeederTest.php` |
| Frontend logic | presence labels, typing routing and expiry, placeholder rendering, reply-mode safety, business-hours window rules, timezone formatting | Vitest unit tests with happy-dom | `resources/js/**/__tests__/*.test.ts` |
| API docs | an undocumented endpoint | every `/api/v1` route appears in `docs/api/openapi.json`; docs are admin-only outside `local` | `tests/Feature/Docs/ApiDocsTest.php` |

## 3. Suites

`phpunit.xml` defines four suites:

| Suite | Folder | Database |
|---|---|---|
| Unit | `tests/Unit` | none (pure PHP) |
| Feature | `tests/Feature` | MySQL, `RefreshDatabase` |
| Concurrency | `tests/Concurrency` | MySQL, committed data across two connections (`DatabaseTruncation`) |
| Database | `tests/Database` | MySQL, FULLTEXT behaviour |

`phpunit.xml` forces a safe test environment: `APP_ENV=testing`, `DB_DATABASE=techzu_testing`, array cache, mail and session, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`, auto-assign off (tests turn it on when they need it) and `memory_limit=2G`.

## 4. Running the tests

Create the test database once (an empty database is enough; the tests build the schema):

```sql
CREATE DATABASE techzu_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

| Goal | Command |
|---|---|
| Everything, one process | `php artisan test --compact` |
| Everything, in parallel (creates `techzu_testing_test_N` databases) | `./vendor/bin/pest --parallel` |
| One folder or file | `php artisan test tests/Feature/Sla` |
| One test by name | `php artisan test --filter="pauses the SLA"` |
| A different test database | `DB_DATABASE=techzu_testing_agent1 php artisan test` |
| Frontend unit tests | `npm run test:js` |
| Static analysis | `./vendor/bin/phpstan analyse --memory-limit=1G` |
| Code style | `./vendor/bin/pint --test` |
| Frontend lint and types | `npm run build && npm run check && npx vue-tsc --noEmit` |

The MySQL user in `.env` must be allowed to create and drop tables in the test database (and to create databases, for `--parallel`).

## 5. Current totals

Last recorded full gate (the 2026-11-01 fix pass). Tests added since then, such as email verification, are not reflected in these counts until the gate is run and recorded again:

| Check | Result |
|---|---|
| Pest (`./vendor/bin/pest --parallel`, MySQL) | **1,874 tests, all passing** |
| Vitest (`npm run test:js`) | **49 tests, all passing** |
| PHPStan level 6 | 0 errors |
| Pint, `vp check`, `vue-tsc` | clean |

Pest counts every dataset row as a test; the suites contain about 520 `it()` blocks. A single-process run (`php artisan test --compact`) of the suite before the fix pass took about 4 minutes 50 seconds on a laptop; `--parallel` is much faster.

## 6. Continuous integration

`.github/workflows/ci.yml` runs on every push to `main` and every pull request, against a MySQL 8 service: `composer install` → Pint → Larastan → `pest --parallel` → `npm ci` → `npm run build` → Vitest (`npm run test:js`) → `npm run check` → `vue-tsc --noEmit`.

## 7. What is deliberately not tested

- Browser end-to-end tests: the rules live on the server and `assertInertia` covers each page's contract. UI flows are checked by hand with the demo data.
- Framework and package behaviour (casts, relations, spatie internals).
- Coverage percentages: they reward testing trivial code.
