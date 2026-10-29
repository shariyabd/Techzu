# T11 — CSV import

## 1. Header

- **Objective:** let an admin upload a CSV of tickets that is streamed, validated row by row, imported in queued batches with per-row transactions, and reported back through a polling progress page, an error table and a downloadable errors CSV.
- **Depends on:** T05 (events, `SlaCalculator::applyInitial`, `TicketNumberGenerator`, `TicketCreated(imported)`), T01 (models, enums, `ImportHeaderInvalid`), T04 (`TicketImportPolicy`).
- **Wave:** 4b (parallel with T10, T12, T13).
- **TDs implemented:** TD-090 (whole pipeline), TD-072 (queue `imports`, retries, batches), TD-053 (one transaction per row), TD-021 (`external_reference` unique index as the de-duplication guard), TD-101 (streaming, `lazy()`), TD-120 (upload validation, private disk, CSV-injection-safe export), TD-130 (`config/imports.php`), TD-110 (`ImportHeaderInvalid`).

## 2. Files

**Create**
- `config/imports.php`
- `app/Services/Imports/TicketCsvReader.php`
- `app/Services/Imports/ImportTicketRowRules.php`
- `app/Services/Imports/TicketImportQuery.php`
- `app/Services/Imports/ImportErrorCsvWriter.php`
- `app/Actions/Imports/StartTicketImport.php`
- `app/Actions/Imports/ImportTicketRow.php`
- `app/Jobs/PrepareTicketImport.php`
- `app/Jobs/ImportTicketsChunk.php`
- `app/Jobs/FinalizeTicketImport.php`
- `app/Http/Requests/StoreTicketImportRequest.php`
- `app/Http/Requests/ListTicketImportErrorsRequest.php`
- `app/Http/Resources/V1/TicketImportResource.php`
- `app/Http/Resources/V1/TicketImportErrorResource.php`
- `app/Http/Controllers/Web/Admin/TicketImportController.php`
- `app/Http/Controllers/Web/Admin/DownloadTicketImportErrorsController.php`
- `app/Http/Controllers/Api/V1/TicketImportController.php`
- `app/Http/Controllers/Api/V1/TicketImportErrorController.php`
- `routes/web/imports.php`
- `routes/api/v1/imports.php`
- `resources/js/types/imports.ts`
- `resources/js/components/imports/ImportStatusBadge.vue`
- `resources/js/components/imports/ImportPagination.vue`
- `resources/js/pages/admin/imports/Index.vue`
- `resources/js/pages/admin/imports/Create.vue`
- `resources/js/pages/admin/imports/Show.vue`
- `resources/js/config/navigation/imports.ts`

**Test**
- `tests/Support/Imports/ImportCsvFixture.php`
- `tests/Unit/Imports/TicketCsvReaderTest.php`
- `tests/Feature/Imports/ImportTicketRowRulesTest.php`
- `tests/Feature/Imports/ImportTicketRowTest.php`
- `tests/Feature/Imports/TicketImportPipelineTest.php`
- `tests/Feature/Imports/PrepareTicketImportMemoryTest.php`
- `tests/Feature/Imports/TicketImportHttpTest.php`
- `tests/Feature/Authorization/ImportAuthorizationTest.php`

**Modify:** none.

**Must not touch:** any migration (the T01 schema already has `ticket_imports`, `ticket_import_errors`, `job_batches`), `app/Models/*`, `app/Events/*`, `app/Listeners/*` (T10 owns `SendImportFinishedNotification`; T06 owns `QueueAutoAssignment`), `bootstrap/app.php`, `resources/js/config/navigation.ts`, `config/tickets.php`, `phpunit.xml`, `tests/Pest.php`.

## 3. Interfaces

### Consumes (exactly as README §3)

```php
// T01
enum ImportStatus: string { Pending, Processing, Completed, CompletedWithErrors, Failed }
enum TicketStatus / TicketPriority / Role
final class ImportHeaderInvalid extends DomainException          // 422 'import_header_invalid'
TicketImport (user(), errors(): HasMany<TicketImportError>, cast status ImportStatus)
TicketImportError (import(), casts errors array, raw array, UPDATED_AT = null)
Ticket (fillable subject, description, priority, category_id, customer_id, created_by_id, external_reference)
Category::scopeActive, User HasRoles (spatie `role()` scope)
UserSummaryResource (id, name)
// T02
SlaCalculator::applyInitial(Ticket $ticket, CarbonImmutable $start): void
SlaCalculator::pause(Ticket $ticket, CarbonImmutable $now): void
// T03
TicketNumberGenerator::next(CarbonImmutable $at): string
// T04
TicketImportPolicy: viewAny(User), view(User, TicketImport), create(User)
// T05
TicketCreated(Ticket $ticket, ?User $actor, bool $imported = false)
TicketImportFinished(TicketImport $import)
```

### Produces (README §3, binding)

```php
// app/Actions/Imports
Imports\StartTicketImport::handle(User $admin, UploadedFile $file): TicketImport
Imports\ImportTicketRow::handle(TicketImport $import, array $row, int $rowNumber): ?Ticket

// app/Jobs — queue 'imports' (config('imports.queue'))
Jobs\PrepareTicketImport(int $importId)
Jobs\ImportTicketsChunk(int $importId, int $startLine, int $endLine)   // Batchable
Jobs\FinalizeTicketImport(int $importId)

// Events fired
TicketCreated($ticket, $importingAdmin, imported: true)   // T06 skips auto-assign, T10 skips customer mail
TicketImportFinished($import)                              // T10 notifies the admin (database channel)

// Resources — app/Http/Resources/V1
TicketImportResource, TicketImportErrorResource

// Routes
web:  admin.imports.index  GET  /admin/imports
      admin.imports.create GET  /admin/imports/create
      admin.imports.store  POST /admin/imports
      admin.imports.show   GET  /admin/imports/{import}
      admin.imports.errors.download GET /admin/imports/{import}/errors/download
api:  api.v1.imports.store        POST /api/v1/imports
      api.v1.imports.show         GET  /api/v1/imports/{import}
      api.v1.imports.errors.index GET  /api/v1/imports/{import}/errors

// Frontend
resources/js/config/navigation/imports.ts (default export NavItem[])
```

Task-internal (not crossing a task boundary): `TicketCsvReader`, `ImportTicketRowRules`, `TicketImportQuery`, `ImportErrorCsvWriter`, `config/imports.php`.

**Row numbering.** `row_number` is the 1-based CSV *record* number with the header as row 1, so the first data row is row 2. This matches what a spreadsheet shows when no field spans several lines. Internally `SplFileObject::key()` is the 0-based record index (header = 0), and the job's `$startLine`/`$endLine` are inclusive record indices.

**CSV format.** Header (case-insensitive, any order, optional UTF-8 BOM):
`external_reference?, subject, description, priority, category (slug), customer_email, status?, created_at?`.
`created_at` values without an offset are read in `config('business_hours.timezone')` and stored as UTC.

## 4. Subtasks & mini-waves

| Subtask | Files | Depends on | Parallel-safe with |
|---|---|---|---|
| T11.1 Config and streaming CSV reader | `config/imports.php`, `TicketCsvReader.php`, `tests/Unit/Imports/TicketCsvReaderTest.php` | — | T11.2, T11.5 |
| T11.2 Row rules and `ImportTicketRow` | `ImportTicketRowRules.php`, `ImportTicketRow.php`, `tests/Support/Imports/ImportCsvFixture.php`, `ImportTicketRowRulesTest.php`, `ImportTicketRowTest.php` | — | T11.1, T11.5 |
| T11.3 Pipeline: Start action and the three jobs | `StartTicketImport.php`, `PrepareTicketImport.php`, `ImportTicketsChunk.php`, `FinalizeTicketImport.php`, `ImportCsvFixture.php` (adds `run()`), `TicketImportPipelineTest.php`, `PrepareTicketImportMemoryTest.php` | T11.1, T11.2 | — |
| T11.4 HTTP layer | requests, resources, `TicketImportQuery.php`, `ImportErrorCsvWriter.php`, 4 controllers, 2 route files, `TicketImportHttpTest.php`, `ImportAuthorizationTest.php` | T11.3, T11.5 | — |
| T11.5 Frontend | `types/imports.ts`, 2 components, 3 pages, `navigation/imports.ts` | — (props fixed in this file) | T11.1, T11.2, T11.3 |

Mini-waves:
- **Wave A (parallel):** T11.1 ∥ T11.2 ∥ T11.5. They touch disjoint files.
- **Wave B:** T11.3.
- **Wave C:** T11.4. It runs after T11.5 because its `assertInertia` checks need the page files to exist.

---

## 5. Subtasks

### T11.1 — Config and streaming CSV reader

- [ ] **Step 1: Write the failing unit test** `tests/Unit/Imports/TicketCsvReaderTest.php`. It touches only a temp file, no database.

```php
<?php

declare(strict_types=1);

use App\Exceptions\Domain\ImportHeaderInvalid;
use App\Services\Imports\TicketCsvReader;

function ticketCsvReaderFixture(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tktcsv');
    file_put_contents($path, $contents);

    return $path;
}

it('normalises the header: BOM, case, surrounding spaces and column order', function (): void {
    $path = ticketCsvReaderFixture("\xEF\xBB\xBF Subject ,DESCRIPTION,priority,Category,customer_email,created_at\n");

    expect((new TicketCsvReader())->header($path))
        ->toBe(['subject', 'description', 'priority', 'category', 'customer_email', 'created_at']);
});

it('rejects a header without the required columns', function (): void {
    $path = ticketCsvReaderFixture("subject,description,priority\n");

    (new TicketCsvReader())->header($path);
})->throws(
    ImportHeaderInvalid::class,
    'The CSV header is invalid: missing required columns: category, customer_email.',
);

it('rejects unknown and duplicate columns', function (): void {
    $path = ticketCsvReaderFixture("subject,description,priority,category,customer_email,colour,subject\n");

    (new TicketCsvReader())->header($path);
})->throws(
    ImportHeaderInvalid::class,
    'The CSV header is invalid: unknown columns: colour; duplicate columns: subject.',
);

it('rejects an empty file', function (): void {
    $path = ticketCsvReaderFixture('');

    (new TicketCsvReader())->header($path);
})->throws(ImportHeaderInvalid::class, 'The CSV file is empty or has no header row.');

it('counts data records, skipping blank lines and treating a quoted multi-line field as one record', function (): void {
    $path = ticketCsvReaderFixture(implode("\n", [
        'subject,description,priority,category,customer_email',
        "A,\"line one\nline two\",low,general,a@example.com",
        'B,desc,low,general,b@example.com',
        '',
        'C,desc,low,general,c@example.com',
        'D,desc,low,general,d@example.com',
    ])."\n");

    expect((new TicketCsvReader())->scan($path))->toBe(['rows' => 4, 'last_line' => 5]);
});

it('yields only the requested record range, keyed by record index', function (): void {
    $path = ticketCsvReaderFixture(implode("\n", [
        'subject,description,priority,category,customer_email',
        "A,\"line one\nline two\",low,general,a@example.com",
        'B,desc,low,general,b@example.com',
        '',
        'C,desc,low,general,c@example.com',
        'D,desc,low,general,d@example.com',
    ])."\n");

    $records = (new TicketCsvReader())->records($path, 2, 4)->all();

    expect(array_keys($records))->toBe([2, 4])
        ->and($records[2][0])->toBe('B')
        ->and($records[4][0])->toBe('C');

    $first = (new TicketCsvReader())->records($path, 1, 1)->all();
    expect($first[1][1])->toBe("line one\nline two");
});
```

- [ ] **Step 2: Run it and expect FAIL.**
  `./vendor/bin/pest tests/Unit/Imports/TicketCsvReaderTest.php`
  Expected: FAIL with `Error: Class "App\Services\Imports\TicketCsvReader" not found`.

- [ ] **Step 3: Create** `config/imports.php`.

```php
<?php

declare(strict_types=1);

return [
    // Must be a disk with a local filesystem path: the jobs read the file with SplFileObject.
    'disk' => env('IMPORTS_DISK', 'local'),

    'directory' => env('IMPORTS_DIRECTORY', 'imports'),

    // 50 MB. Validated by StoreTicketImportRequest (`max:` is in kilobytes).
    'max_upload_kb' => (int) env('IMPORTS_MAX_UPLOAD_KB', 51200),

    // Records per ImportTicketsChunk job.
    'chunk_size' => (int) env('IMPORTS_CHUNK_SIZE', 500),

    // Separate queue so a large import never delays notifications (TD-072).
    'queue' => env('IMPORTS_QUEUE', 'imports'),
];
```

- [ ] **Step 4: Create** `app/Services/Imports/TicketCsvReader.php`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Exceptions\Domain\ImportHeaderInvalid;
use Illuminate\Support\LazyCollection;
use SplFileObject;

/**
 * Streams a ticket CSV record by record. Nothing here holds more than one record in memory,
 * which is what keeps a 50 MB import at constant memory (TD-090).
 *
 * Keys are SplFileObject record indices (header = 0). With READ_CSV a quoted field that spans
 * several physical lines is still one record, so indices stay stable between the counting pass
 * in PrepareTicketImport and the seek in ImportTicketsChunk.
 */
final class TicketCsvReader
{
    /** Canonical column order, also used for the errors CSV export. */
    public const COLUMNS = [
        'external_reference',
        'subject',
        'description',
        'priority',
        'category',
        'customer_email',
        'status',
        'created_at',
    ];

    public const REQUIRED_COLUMNS = ['subject', 'description', 'priority', 'category', 'customer_email'];

    /**
     * @return list<string>
     *
     * @throws ImportHeaderInvalid
     */
    public function header(string $path): array
    {
        $file = $this->open($path);
        $raw = $file->current();

        if (! is_array($raw) || $this->isBlank($raw)) {
            throw new ImportHeaderInvalid('The CSV file is empty or has no header row.');
        }

        $raw = array_values($raw);
        $raw[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw[0]);

        $columns = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $raw,
        );

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));
        $unknown = array_values(array_unique(array_diff($columns, self::COLUMNS)));
        $duplicates = array_values(array_unique(array_diff_assoc($columns, array_unique($columns))));

        $problems = [];
        if ($missing !== []) {
            $problems[] = 'missing required columns: '.implode(', ', $missing);
        }
        if ($unknown !== []) {
            $problems[] = 'unknown columns: '.implode(', ', array_map(
                static fn (string $column): string => $column === '' ? '(blank)' : $column,
                $unknown,
            ));
        }
        if ($duplicates !== []) {
            $problems[] = 'duplicate columns: '.implode(', ', $duplicates);
        }

        if ($problems !== []) {
            throw new ImportHeaderInvalid('The CSV header is invalid: '.implode('; ', $problems).'.');
        }

        return $columns;
    }

    /**
     * One streaming pass over the data records.
     *
     * @return array{rows: int, last_line: int}
     */
    public function scan(string $path): array
    {
        $rows = 0;
        $lastLine = 0;

        foreach ($this->records($path, 1, PHP_INT_MAX) as $line => $values) {
            $rows++;
            $lastLine = $line;
        }

        return ['rows' => $rows, 'last_line' => $lastLine];
    }

    /**
     * Non-blank records with index in [$startLine, $endLine], keyed by record index.
     *
     * @return LazyCollection<int, list<string|null>>
     */
    public function records(string $path, int $startLine, int $endLine): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $startLine, $endLine) {
            $file = $this->open($path);
            $file->seek($startLine);

            while ($file->valid() && $file->key() <= $endLine) {
                $values = $file->current();

                if (is_array($values) && ! $this->isBlank($values)) {
                    yield $file->key() => array_values(array_map(
                        static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                        $values,
                    ));
                }

                $file->next();
            }
        });
    }

    private function open(string $path): SplFileObject
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV);
        // RFC 4180: quotes are escaped by doubling; no backslash escape character.
        $file->setCsvControl(',', '"', '');

        return $file;
    }

    /** @param array<int, mixed> $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 5: Run it and expect PASS.**
  `./vendor/bin/pest tests/Unit/Imports/TicketCsvReaderTest.php`
  Expected: PASS (6 tests).

- [ ] **Step 6: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: Pint reports the files as fixed or clean, and PHPStan prints `[OK] No errors`.

- [ ] **Step 7: Commit.**

```bash
git add config/imports.php app/Services/Imports/TicketCsvReader.php tests/Unit/Imports/TicketCsvReaderTest.php
git commit -m "feat(import): add imports config and streaming CSV reader" --trailer "Refs: T11.1"
```

---
### T11.2 — Row rules and `ImportTicketRow`

- [ ] **Step 1: Create the shared test fixture** `tests/Support/Imports/ImportCsvFixture.php`. T11.3 adds `run()` to it.

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Enums\ImportStatus;
use App\Models\TicketImport;
use App\Models\User;

final class ImportCsvFixture
{
    /**
     * Nine data rows (CSV rows 2–10): two importable, seven rejected for different reasons.
     * Expects: category slug `hardware`, customer `customer@example.com`, agent `agent@example.com`,
     * and an existing ticket with external_reference `EXT-OLD`.
     */
    public static function mixed(): string
    {
        return implode("\n", [
            'external_reference,subject,description,priority,category,customer_email,status,created_at',
            'EXT-1,Printer on fire,The office printer is on fire,critical,hardware,customer@example.com,,2026-10-29 17:00',
            'EXT-2,=SUM(A1:A2),Formula in subject,urgent,hardware,customer@example.com,,',
            'EXT-1,Duplicate in file,Same reference as row 2,low,hardware,customer@example.com,,',
            'EXT-OLD,Already imported,Reference exists in the database,low,hardware,customer@example.com,,',
            ',Waiting on customer,No reference and no date,low,hardware,CUSTOMER@example.com,waiting_for_customer,',
            ',Unknown customer,Email has no account,medium,hardware,nobody@example.com,,',
            ',From the future,Created in 2030,medium,hardware,customer@example.com,,2030-01-01 10:00',
            ',Unknown category,Slug does not exist,high,plumbing,customer@example.com,,',
            ',Agent as customer,Email belongs to an agent,high,hardware,agent@example.com,,',
        ])."\n";
    }

    /**
     * A validated row as ImportTicketsChunk hands it to ImportTicketRow.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    public static function row(array $overrides = []): array
    {
        return array_merge([
            'external_reference' => 'EXT-ROW',
            'subject' => 'Printer on fire',
            'description' => 'The office printer is on fire',
            'priority' => 'critical',
            'category' => 'hardware',
            'customer_email' => 'customer@example.com',
            'status' => null,
            'created_at' => '2026-10-29 17:00',
        ], $overrides);
    }

    public static function pendingImport(User $admin, string $path = 'imports/fixture.csv'): TicketImport
    {
        $import = new TicketImport();
        $import->user_id = $admin->id;
        $import->original_name = 'fixture.csv';
        $import->path = $path;
        $import->status = ImportStatus::Pending;
        $import->total_rows = 0;
        $import->processed_rows = 0;
        $import->succeeded_rows = 0;
        $import->failed_rows = 0;
        $import->save();

        return $import;
    }
}
```

- [ ] **Step 2: Write the failing rules test** `tests/Feature/Imports/ImportTicketRowRulesTest.php`.

```php
<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Imports\ImportTicketRowRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent()->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);

    $this->rules = new ImportTicketRowRules(CarbonImmutable::now(), 'Asia/Dhaka', ['hardware']);
});

it('accepts a complete valid row', function (): void {
    $validator = Validator::make(ImportCsvFixture::row(), $this->rules->rules());

    expect($validator->fails())->toBeFalse();
});

it('accepts a row with only the required columns', function (): void {
    $row = ImportCsvFixture::row();
    unset($row['external_reference'], $row['status'], $row['created_at']);

    expect(Validator::make($row, $this->rules->rules())->fails())->toBeFalse();
});

it('rejects an invalid value', function (array $overrides, string $field): void {
    $validator = Validator::make(ImportCsvFixture::row($overrides), $this->rules->rules());

    expect($validator->fails())->toBeTrue()
        ->and(array_keys($validator->errors()->toArray()))->toBe([$field]);
})->with([
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
    'unknown or inactive category' => [['category' => 'plumbing'], 'category'],
    'email of a non-customer' => [['customer_email' => 'agent@example.com'], 'customer_email'],
    'email without an account' => [['customer_email' => 'nobody@example.com'], 'customer_email'],
    'unknown status' => [['status' => 'archived'], 'status'],
    'unparseable created_at' => [['created_at' => '2026-13-45'], 'created_at'],
    'created_at in the future' => [['created_at' => '2030-01-01 00:00'], 'created_at'],
    'reference already in the database' => [['external_reference' => 'EXT-OLD'], 'external_reference'],
]);

it('reads created_at without an offset in the business timezone', function (): void {
    expect(ImportTicketRowRules::parseCreatedAt('2026-10-29 17:00', 'Asia/Dhaka')?->toDateTimeString())
        ->toBe('2026-10-29 11:00:00')
        ->and(ImportTicketRowRules::parseCreatedAt('2026-10-29T17:00:00+00:00', 'Asia/Dhaka')?->toDateTimeString())
        ->toBe('2026-10-29 17:00:00')
        ->and(ImportTicketRowRules::parseCreatedAt(null, 'Asia/Dhaka'))->toBeNull()
        ->and(ImportTicketRowRules::parseCreatedAt('2026-13-45', 'Asia/Dhaka'))->toBeNull();
});
```

- [ ] **Step 3: Write the failing action test** `tests/Feature/Imports/ImportTicketRowTest.php`.

```php
<?php

declare(strict_types=1);

use App\Actions\Imports\ImportTicketRow;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImportError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create(['email' => 'customer@example.com']);
    $this->import = ImportCsvFixture::pendingImport($this->admin);
});

it('creates a numbered ticket whose SLA clock starts at the CSV created_at', function (): void {
    $ticket = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(), 2);

    expect($ticket)->toBeInstanceOf(Ticket::class);
    $ticket = $ticket->fresh();

    // PRD example: Critical, Thursday 17:00 Asia/Dhaka → due Sunday 10:00 (04:00 UTC).
    expect($ticket->number)->toMatch('/^TKT-2026-\d{6}$/')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->external_reference)->toBe('EXT-ROW')
        ->and($ticket->customer_id)->toBe($this->customer->id)
        ->and($ticket->created_by_id)->toBe($this->admin->id)
        ->and($ticket->created_at->toDateTimeString())->toBe('2026-10-29 11:00:00')
        ->and($ticket->sla_started_at->toDateTimeString())->toBe('2026-10-29 11:00:00')
        ->and($ticket->sla_warning_at->toDateTimeString())->toBe('2026-11-01 03:30:00')
        ->and($ticket->sla_due_at->toDateTimeString())->toBe('2026-11-01 04:00:00')
        ->and($ticket->sla_paused_at)->toBeNull();
});

it('starts the SLA clock at import time when created_at is empty', function (): void {
    $ticket = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['created_at' => null]), 2)
        ?->fresh();

    expect($ticket?->created_at->toDateTimeString())->toBe('2026-11-02 06:00:00')
        ->and($ticket?->sla_started_at->toDateTimeString())->toBe('2026-11-02 06:00:00');
});

it('pauses the SLA for an imported waiting_for_customer ticket', function (): void {
    $ticket = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['status' => 'waiting_for_customer']), 2)
        ?->fresh();

    expect($ticket?->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($ticket?->sla_paused_at?->toDateTimeString())->toBe('2026-11-02 06:00:00');
});

it('stamps resolved_at and closed_at for imported finished tickets', function (): void {
    $resolved = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['external_reference' => 'EXT-R', 'status' => 'resolved']), 2)
        ?->fresh();
    $closed = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['external_reference' => 'EXT-C', 'status' => 'closed']), 3)
        ?->fresh();

    expect($resolved?->resolved_at?->toDateTimeString())->toBe('2026-11-02 06:00:00')
        ->and($resolved?->closed_at)->toBeNull()
        ->and($closed?->resolved_at?->toDateTimeString())->toBe('2026-11-02 06:00:00')
        ->and($closed?->closed_at?->toDateTimeString())->toBe('2026-11-02 06:00:00');
});

it('fires TicketCreated flagged as imported, with the importing admin as actor', function (): void {
    Event::fake([TicketCreated::class]);

    app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(), 2);

    Event::assertDispatchedTimes(TicketCreated::class, 1);
    Event::assertDispatched(
        TicketCreated::class,
        fn (TicketCreated $event): bool => $event->imported === true && $event->actor?->is($this->admin) === true,
    );
});

it('turns a unique-index violation on external_reference into a duplicate error row', function (): void {
    // Simulates the race: the validation pre-check passed, but another chunk committed the same reference first.
    Ticket::factory()->create(['external_reference' => 'EXT-RACE']);
    $ticketsBefore = Ticket::query()->count();
    $sequenceBefore = (int) DB::table('ticket_sequences')->sum('last_number');

    $result = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['external_reference' => 'EXT-RACE']), 7);

    $error = TicketImportError::query()->where('ticket_import_id', $this->import->id)->sole();

    expect($result)->toBeNull()
        ->and(Ticket::query()->count())->toBe($ticketsBefore)
        ->and((int) DB::table('ticket_sequences')->sum('last_number'))->toBe($sequenceBefore)
        ->and($error->row_number)->toEqual(7)
        ->and($error->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($error->raw['external_reference'])->toBe('EXT-RACE');
});
```

- [ ] **Step 4: Run both and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Imports/ImportTicketRowRulesTest.php tests/Feature/Imports/ImportTicketRowTest.php`
  Expected: FAIL with `Error: Class "App\Services\Imports\ImportTicketRowRules" not found` and `Class "App\Actions\Imports\ImportTicketRow" not found`.

- [ ] **Step 5: Create** `app/Services/Imports/ImportTicketRowRules.php`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation for one normalised CSV row. Built once per chunk, with the active category slugs
 * preloaded, so the category check costs no query per row.
 */
final class ImportTicketRowRules
{
    /** @param list<string> $activeCategorySlugs */
    public function __construct(
        private readonly CarbonImmutable $now,
        private readonly string $timezone,
        private readonly array $activeCategorySlugs,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Pre-check only. The unique index is the real guard; ImportTicketRow handles the race.
            'external_reference' => ['nullable', 'string', 'max:64', Rule::unique('tickets', 'external_reference')],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:65535'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category' => ['required', 'string', Rule::in($this->activeCategorySlugs)],
            'customer_email' => ['bail', 'required', 'string', 'email', $this->customerExists(...)],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'created_at' => ['nullable', 'string', $this->createdAtIsValid(...)],
        ];
    }

    /** Reads a CSV date in the business timezone unless it carries its own offset; returns UTC. */
    public static function parseCreatedAt(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function customerExists(string $attribute, mixed $value, Closure $fail): void
    {
        $isCustomer = User::query()
            ->role(Role::Customer->value)
            ->where('email', (string) $value)
            ->exists();

        if (! $isCustomer) {
            $fail('The customer email must belong to an existing customer.');
        }
    }

    private function createdAtIsValid(string $attribute, mixed $value, Closure $fail): void
    {
        $createdAt = self::parseCreatedAt((string) $value, $this->timezone);

        if ($createdAt === null) {
            $fail('The created at field must be a valid date.');

            return;
        }

        if ($createdAt->greaterThan($this->now)) {
            $fail('The created at field must not be in the future.');
        }
    }
}
```

- [ ] **Step 6: Create** `app/Actions/Imports/ImportTicketRow.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Models\User;
use App\Services\Imports\ImportTicketRowRules;
use App\Services\Sla\SlaCalculator;
use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ImportTicketRow
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
    ) {}

    /**
     * Imports one validated row in its own transaction (TD-053), so a bad row never rolls back its neighbours.
     * Returns null when the row turned out to be a duplicate; the error row is recorded here.
     *
     * @param  array<string, string|null>  $row  validated by ImportTicketRowRules
     */
    public function handle(TicketImport $import, array $row, int $rowNumber): ?Ticket
    {
        $now = CarbonImmutable::now();
        $createdAt = ImportTicketRowRules::parseCreatedAt(
            $row['created_at'] ?? null,
            (string) config('business_hours.timezone'),
        ) ?? $now;
        $status = TicketStatus::tryFrom((string) ($row['status'] ?? '')) ?? TicketStatus::Open;
        $actor = $import->loadMissing('user')->user;

        try {
            return DB::transaction(function () use ($import, $row, $createdAt, $status, $now, $actor): Ticket {
                $category = Category::query()->where('slug', (string) $row['category'])->firstOrFail(['id']);
                $customer = User::query()->where('email', (string) $row['customer_email'])->firstOrFail(['id']);

                $ticket = new Ticket();
                $ticket->fill([
                    'subject' => (string) $row['subject'],
                    'description' => (string) $row['description'],
                    'priority' => TicketPriority::from((string) $row['priority']),
                    'category_id' => $category->id,
                    'customer_id' => $customer->id,
                    'created_by_id' => $import->user_id,
                    'external_reference' => $row['external_reference'] ?? null,
                ]);
                // Number year follows the ticket's own creation date, so historic tickets keep their year.
                $ticket->number = $this->numbers->next($createdAt);
                $ticket->status = $status;
                $ticket->created_at = $createdAt;
                $this->sla->applyInitial($ticket, $createdAt);
                $this->applyInitialStatus($ticket, $status, $now);
                $ticket->save();

                // imported=true: T06 skips auto-assignment and T10 skips per-ticket customer notifications.
                TicketCreated::dispatch($ticket, $actor, true);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'external_reference')) {
                throw $exception;
            }

            $this->recordDuplicate($import, $row, $rowNumber);

            return null;
        }
    }

    /**
     * The CSV gives an initial status, not a transition, so ChangeTicketStatus does not apply.
     * The side effects mirror what the lifecycle would have produced.
     */
    private function applyInitialStatus(Ticket $ticket, TicketStatus $status, CarbonImmutable $now): void
    {
        match ($status) {
            TicketStatus::WaitingForCustomer => $this->sla->pause($ticket, $now),
            TicketStatus::Resolved => $ticket->resolved_at = $now,
            TicketStatus::Closed => $ticket->resolved_at = $ticket->closed_at = $now,
            TicketStatus::Open, TicketStatus::InProgress => null,
        };
    }

    /** @param array<string, string|null> $row */
    private function recordDuplicate(TicketImport $import, array $row, int $rowNumber): void
    {
        $error = new TicketImportError();
        $error->row_number = $rowNumber;
        $error->errors = ['external_reference' => ['The external reference has already been taken.']];
        $error->raw = $row;
        $import->errors()->save($error);
    }
}
```

- [ ] **Step 7: Run both and expect PASS.**
  `./vendor/bin/pest tests/Feature/Imports/ImportTicketRowRulesTest.php tests/Feature/Imports/ImportTicketRowTest.php`
  Expected: PASS (17 tests: 11 in the rules file, counting the 8 dataset rows, and 6 in the action file).

- [ ] **Step 8: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 9: Commit.**

```bash
git add app/Services/Imports/ImportTicketRowRules.php app/Actions/Imports/ImportTicketRow.php \
  tests/Support/Imports/ImportCsvFixture.php tests/Feature/Imports/ImportTicketRowRulesTest.php \
  tests/Feature/Imports/ImportTicketRowTest.php
git commit -m "feat(import): validate and import single CSV rows" --trailer "Refs: T11.2"
```

---
### T11.3 — Pipeline: `StartTicketImport` and the three jobs

**Why the tests use the sync queue driver.** `phpunit.xml` (T00) sets `QUEUE_CONNECTION=sync`. On the sync driver, `PrepareTicketImport::dispatch()` runs immediately. Its `Bus::batch(...)->dispatch()` then runs every `ImportTicketsChunk` inline, in order, inside the batch repository's transaction. The batch's `finally` callback fires after the last chunk and dispatches `FinalizeTicketImport`, which also runs inline. So one call to `StartTicketImport::handle()` exercises the real batch, the real `job_batches` row and the real callbacks, with no worker, and the assertions run against the final state. Each pipeline test asserts `config('queue.default') === 'sync'` so this assumption is explicit. The memory test uses `Bus::fake()` instead, because it measures `PrepareTicketImport` on its own.

- [ ] **Step 1: Add `run()` to** `tests/Support/Imports/ImportCsvFixture.php`. Add these `use` lines below the existing ones:

```php
use App\Actions\Imports\StartTicketImport;
use Illuminate\Http\UploadedFile;
```

  and this method inside the class, after `pendingImport()`:

```php
    /** Uploads $csv through the real action; on the sync driver the whole pipeline has finished on return. */
    public static function run(User $admin, string $csv, string $name = 'tickets.csv'): TicketImport
    {
        return app(StartTicketImport::class)
            ->handle($admin, UploadedFile::fake()->createWithContent($name, $csv))
            ->refresh();
    }
```

- [ ] **Step 2: Write the failing pipeline test** `tests/Feature/Imports/TicketImportPipelineTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Enums\TicketStatus;
use App\Events\TicketImportFinished;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImportError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    expect(config('queue.default'))->toBe('sync');

    Storage::fake((string) config('imports.disk'));
    // Chunks of 3 records split the nine fixture rows into three batch jobs: [1–3], [4–6], [7–9].
    // auto_assign is on and the agent matches the category, so a missing skip would assign the ticket.
    config(['imports.chunk_size' => 3, 'tickets.auto_assign' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));

    $this->category = Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent([$this->category->id])->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);
});

it('imports the valid rows and finishes with errors, with correct counters', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->total_rows)->toEqual(9)
        ->and($import->processed_rows)->toEqual(9)
        ->and($import->succeeded_rows)->toEqual(2)
        ->and($import->failed_rows)->toEqual(7)
        ->and($import->batch_id)->not->toBeNull()
        ->and($import->started_at)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull()
        ->and(Ticket::query()->count())->toBe(3)
        ->and(Ticket::query()->where('external_reference', 'EXT-1')->count())->toBe(1);
});

it('records each rejected row with its row number, messages and raw values', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $errors = TicketImportError::query()
        ->where('ticket_import_id', $import->id)
        ->orderBy('row_number')
        ->get();
    $byRow = $errors->keyBy('row_number');

    expect($errors->pluck('row_number')->all())->toEqual([3, 4, 5, 7, 8, 9, 10])
        ->and($byRow[3]->errors)->toBe(['priority' => ['The selected priority is invalid.']])
        ->and($byRow[3]->raw['subject'])->toBe('=SUM(A1:A2)')
        ->and($byRow[4]->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($byRow[5]->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($byRow[7]->errors)->toBe(['customer_email' => ['The customer email must belong to an existing customer.']])
        ->and($byRow[8]->errors)->toBe(['created_at' => ['The created at field must not be in the future.']])
        ->and($byRow[9]->errors)->toBe(['category' => ['The selected category is invalid.']])
        ->and($byRow[10]->errors)->toBe(['customer_email' => ['The customer email must belong to an existing customer.']]);
});

it('gives imported tickets distinct numbers without auto-assigning them', function (): void {
    ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $dated = Ticket::query()->where('external_reference', 'EXT-1')->sole();
    $waiting = Ticket::query()->where('subject', 'Waiting on customer')->sole();

    expect($dated->number)->toMatch('/^TKT-2026-\d{6}$/')
        ->and($dated->status)->toBe(TicketStatus::Open)
        ->and($dated->customer_id)->toBe($this->customer->id)
        ->and($dated->created_by_id)->toBe($this->admin->id)
        ->and($dated->assigned_agent_id)->toBeNull()
        ->and($waiting->number)->toMatch('/^TKT-2026-\d{6}$/')
        ->and($waiting->number)->not->toBe($dated->number)
        ->and($waiting->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($waiting->customer_id)->toBe($this->customer->id)
        ->and($waiting->assigned_agent_id)->toBeNull();
});

it('fires TicketImportFinished exactly once', function (): void {
    Event::fake([TicketImportFinished::class]);

    ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    Event::assertDispatchedTimes(TicketImportFinished::class, 1);
});

it('sends customers no notification for imported tickets', function (): void {
    Notification::fake();

    ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    Notification::assertNothingSentTo($this->customer);
});

it('fails the import when the header is invalid, without importing anything', function (): void {
    Event::fake([TicketImportFinished::class]);

    $import = ImportCsvFixture::run($this->admin, "subject,description,priority\nPrinter,Broken,low\n");
    $error = TicketImportError::query()->where('ticket_import_id', $import->id)->sole();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->total_rows)->toEqual(0)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->batch_id)->toBeNull()
        ->and(Ticket::query()->count())->toBe(1)
        ->and($error->row_number)->toEqual(1)
        ->and($error->errors)->toBe(['header' => [
            'The CSV header is invalid: missing required columns: category, customer_email.',
        ]]);
    Event::assertDispatchedTimes(TicketImportFinished::class, 1);
});

it('completes a file that has a header and no data rows', function (): void {
    $import = ImportCsvFixture::run(
        $this->admin,
        "external_reference,subject,description,priority,category,customer_email\n\n",
    );

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->total_rows)->toEqual(0)
        ->and($import->finished_at)->not->toBeNull();
});

it('marks an import without rejected rows as completed', function (): void {
    $csv = "subject,description,priority,category,customer_email\nPrinter,Broken,low,hardware,customer@example.com\n";

    $import = ImportCsvFixture::run($this->admin, $csv);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->succeeded_rows)->toEqual(1)
        ->and($import->failed_rows)->toEqual(0);
});
```

- [ ] **Step 3: Write the failing memory test** `tests/Feature/Imports/PrepareTicketImportMemoryTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ImportTicketsChunk;
use App\Jobs\PrepareTicketImport;
use App\Models\User;
use App\Services\Imports\TicketCsvReader;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Imports\ImportCsvFixture;

it('streams a 10,000-row file while preparing, using constant memory', function (): void {
    Storage::fake((string) config('imports.disk'));
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $disk = Storage::disk((string) config('imports.disk'));
    $disk->makeDirectory('imports');

    // About 6 MB on disk: loading it whole (file(), file_get_contents, or an array of rows) would exceed the bound.
    $description = str_repeat('The printer on floor three is jammed again. ', 13);
    $handle = fopen($disk->path('imports/big.csv'), 'wb');
    fputcsv($handle, TicketCsvReader::COLUMNS, ',', '"', '');
    for ($i = 1; $i <= 10_000; $i++) {
        fputcsv($handle, ["EXT-{$i}", "Subject {$i}", $description, 'low', 'general', 'customer@example.com', '', ''], ',', '"', '');
    }
    fclose($handle);
    expect(filesize($disk->path('imports/big.csv')))->toBeGreaterThan(5_000_000);

    // Warm-up on a tiny file, so autoloading the job and reader classes is not counted as growth.
    $disk->put('imports/warmup.csv', "subject,description,priority,category,customer_email\nA,B,low,general,c@example.com\n");
    app()->call([new PrepareTicketImport(ImportCsvFixture::pendingImport($admin, 'imports/warmup.csv')->id), 'handle']);

    $import = ImportCsvFixture::pendingImport($admin, 'imports/big.csv');

    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage();

    app()->call([new PrepareTicketImport($import->id), 'handle']);

    $growth = memory_get_peak_usage() - $baseline;
    $import->refresh();

    expect($growth)->toBeLessThan(2 * 1024 * 1024)
        ->and($import->status)->toBe(ImportStatus::Processing)
        ->and($import->total_rows)->toEqual(10_000);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        /** @var ImportTicketsChunk $first */
        $first = $batch->jobs->first();
        /** @var ImportTicketsChunk $last */
        $last = $batch->jobs->last();

        return $batch->jobs->count() === 20
            && $batch->queue() === 'imports'
            && $batch->allowsFailures()
            && [$first->startLine, $first->endLine] === [1, 500]
            && [$last->startLine, $last->endLine] === [9501, 10000];
    });
});
```

- [ ] **Step 4: Run both and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Imports/TicketImportPipelineTest.php tests/Feature/Imports/PrepareTicketImportMemoryTest.php`
  Expected: FAIL with `Error: Class "App\Actions\Imports\StartTicketImport" not found` and `Class "App\Jobs\PrepareTicketImport" not found`.

- [ ] **Step 5: Create** `app/Actions/Imports/StartTicketImport.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Jobs\PrepareTicketImport;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StartTicketImport
{
    public function handle(User $admin, UploadedFile $file): TicketImport
    {
        $disk = (string) config('imports.disk');

        // Random hashed filename on the private disk; the client name is kept only as metadata.
        $path = $file->store((string) config('imports.directory'), $disk);
        if ($path === false) {
            throw new RuntimeException('The import file could not be stored.');
        }

        $import = new TicketImport();
        $import->user_id = $admin->id;
        $import->original_name = Str::limit($file->getClientOriginalName(), 250, '');
        $import->path = $path;
        $import->status = ImportStatus::Pending;
        $import->total_rows = 0;
        $import->processed_rows = 0;
        $import->succeeded_rows = 0;
        $import->failed_rows = 0;

        try {
            $import->save();
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        PrepareTicketImport::dispatch($import->id);

        // On the sync driver the pipeline already ran; refresh so callers see the current state.
        return $import->refresh();
    }
}
```

- [ ] **Step 6: Create** `app/Jobs/PrepareTicketImport.php`.

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Events\TicketImportFinished;
use App\Exceptions\Domain\ImportHeaderInvalid;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Services\Imports\TicketCsvReader;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PrepareTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $importId)
    {
        $this->onQueue((string) config('imports.queue'));
    }

    public function handle(TicketCsvReader $reader): void
    {
        $import = TicketImport::query()->find($this->importId);

        // Only a pending import is prepared, so a retry after the batch was dispatched does nothing.
        if ($import === null || $import->status !== ImportStatus::Pending) {
            return;
        }

        $path = Storage::disk((string) config('imports.disk'))->path($import->path);

        try {
            $reader->header($path);
        } catch (ImportHeaderInvalid $exception) {
            $this->markFailed($import, 1, ['header' => [$exception->getMessage()]]);

            return;
        }

        ['rows' => $rows, 'last_line' => $lastLine] = $reader->scan($path);

        // Must be Processing before dispatch: on the sync driver Finalize runs inside dispatch().
        $import->status = ImportStatus::Processing;
        $import->total_rows = $rows;
        $import->started_at = CarbonImmutable::now();
        $import->save();

        if ($rows === 0) {
            FinalizeTicketImport::dispatch($import->id);

            return;
        }

        $importId = $import->id;

        $batch = Bus::batch($this->chunks($importId, $lastLine))
            ->name('ticket-import-'.$importId)
            ->onQueue((string) config('imports.queue'))
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($importId): void {
                // Stamped here too: on the sync driver this callback runs before dispatch() returns.
                TicketImport::query()->whereKey($importId)->whereNull('batch_id')->update(['batch_id' => $batch->id]);
                FinalizeTicketImport::dispatch($importId);
            })
            ->dispatch();

        TicketImport::query()->whereKey($importId)->whereNull('batch_id')->update(['batch_id' => $batch->id]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Preparing ticket import failed.', ['import_id' => $this->importId, 'exception' => $exception]);

        $import = TicketImport::query()->find($this->importId);

        if ($import !== null && in_array($import->status, [ImportStatus::Pending, ImportStatus::Processing], true)) {
            $this->markFailed($import, 1, ['import' => ['The import could not be prepared. See the application log.']]);
        }
    }

    /** @return list<ImportTicketsChunk> */
    private function chunks(int $importId, int $lastLine): array
    {
        $size = max(1, (int) config('imports.chunk_size'));
        $jobs = [];

        for ($start = 1; $start <= $lastLine; $start += $size) {
            $jobs[] = new ImportTicketsChunk($importId, $start, min($start + $size - 1, $lastLine));
        }

        return $jobs;
    }

    /** @param array<string, list<string>> $errors */
    private function markFailed(TicketImport $import, int $rowNumber, array $errors): void
    {
        // ticket_imports has no message column; the reason is the first row of the error report.
        DB::transaction(function () use ($import, $rowNumber, $errors): void {
            $error = new TicketImportError();
            $error->row_number = $rowNumber;
            $error->errors = $errors;
            $error->raw = [];
            $import->errors()->save($error);

            $import->status = ImportStatus::Failed;
            $import->finished_at = CarbonImmutable::now();
            $import->save();

            TicketImportFinished::dispatch($import);
        });
    }
}
```

  > The header error stores `raw = []`. The header values already appear in the message.

- [ ] **Step 7: Create** `app/Jobs/ImportTicketsChunk.php`.

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Imports\ImportTicketRow;
use App\Models\Category;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Services\Imports\ImportTicketRowRules;
use App\Services\Imports\TicketCsvReader;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final class ImportTicketsChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /** Values lower-cased before validation, so `High` and `CUSTOMER@x.com` are accepted. */
    private const LOWERCASE = ['priority', 'status', 'category', 'customer_email'];

    // One attempt: rows commit one by one, so a retry would re-import rows without an external_reference.
    // Per-row failures are caught below, so the job itself fails only on infrastructure errors.
    public int $tries = 1;

    public function __construct(
        public int $importId,
        public int $startLine,
        public int $endLine,
    ) {
        $this->onQueue((string) config('imports.queue'));
    }

    public function handle(TicketCsvReader $reader, ImportTicketRow $importRow): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $import = TicketImport::query()->with('user')->find($this->importId);
        if ($import === null) {
            return;
        }

        $path = Storage::disk((string) config('imports.disk'))->path($import->path);
        $header = $reader->header($path);
        $rules = new ImportTicketRowRules(
            CarbonImmutable::now(),
            (string) config('business_hours.timezone'),
            Category::query()->active()->pluck('slug')->all(),
        );

        foreach ($reader->records($path, $this->startLine, $this->endLine) as $line => $values) {
            $this->importRecord($import, $importRow, $rules, $header, $values, $line + 1);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Ticket import chunk failed.', [
            'import_id' => $this->importId,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'exception' => $exception,
        ]);

        $error = new TicketImportError();
        $error->ticket_import_id = $this->importId;
        $error->row_number = $this->startLine + 1;
        $error->errors = ['chunk' => [sprintf(
            'Rows %d to %d stopped unexpectedly and may be partly imported. See the application log.',
            $this->startLine + 1,
            $this->endLine + 1,
        )]];
        $error->raw = [];
        $error->save();
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $values
     */
    private function importRecord(
        TicketImport $import,
        ImportTicketRow $importRow,
        ImportTicketRowRules $rules,
        array $header,
        array $values,
        int $rowNumber,
    ): void {
        if (count($values) !== count($header)) {
            $this->recordFailure($import, $rowNumber, ['row' => [sprintf(
                'The row has %d columns but the header has %d.',
                count($values),
                count($header),
            )]], $values);

            return;
        }

        $row = $this->normalise(array_combine($header, $values));
        $validator = Validator::make($row, $rules->rules());

        if ($validator->fails()) {
            $this->recordFailure($import, $rowNumber, $validator->errors()->toArray(), $row);

            return;
        }

        try {
            /** @var array<string, string|null> $validated */
            $validated = $validator->validated();
            $ticket = $importRow->handle($import, $validated, $rowNumber);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($import, $rowNumber, ['row' => [
                'The row could not be imported because of an unexpected error.',
            ]], $row);

            return;
        }

        // A null ticket is a duplicate caught by the unique index; ImportTicketRow already wrote its error row.
        $this->increment($import, $ticket === null ? 'failed_rows' : 'succeeded_rows');
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private function normalise(array $row): array
    {
        $clean = [];

        foreach ($row as $column => $value) {
            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;

            if ($value !== null && in_array($column, self::LOWERCASE, true)) {
                $value = Str::lower($value);
            }

            $clean[$column] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<int|string, string|null>  $raw
     */
    private function recordFailure(TicketImport $import, int $rowNumber, array $errors, array $raw): void
    {
        DB::transaction(function () use ($import, $rowNumber, $errors, $raw): void {
            $error = new TicketImportError();
            $error->row_number = $rowNumber;
            $error->errors = $errors;
            $error->raw = $raw;
            $import->errors()->save($error);

            $this->increment($import, 'failed_rows');
        });
    }

    /** Atomic `col = col + 1` in SQL, so parallel chunks never lose an update. */
    private function increment(TicketImport $import, string $outcomeColumn): void
    {
        TicketImport::query()
            ->whereKey($import->id)
            ->toBase()
            ->incrementEach(['processed_rows' => 1, $outcomeColumn => 1]);
    }
}
```

- [ ] **Step 8: Create** `app/Jobs/FinalizeTicketImport.php`.

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Events\TicketImportFinished;
use App\Models\TicketImport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

final class FinalizeTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $importId)
    {
        $this->onQueue((string) config('imports.queue'));
    }

    public function handle(): void
    {
        $import = TicketImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }

        $batch = $import->batch_id !== null ? Bus::findBatch($import->batch_id) : null;

        $status = match (true) {
            $batch !== null && $batch->hasFailures() => ImportStatus::Failed,
            (int) $import->failed_rows > 0 => ImportStatus::CompletedWithErrors,
            default => ImportStatus::Completed,
        };

        // Conditional update: a duplicate Finalize (retry or second worker) changes nothing and fires nothing.
        $updated = TicketImport::query()
            ->whereKey($import->id)
            ->where('status', ImportStatus::Processing->value)
            ->update(['status' => $status->value, 'finished_at' => CarbonImmutable::now()]);

        if ($updated === 1) {
            TicketImportFinished::dispatch($import->refresh());
        }
    }
}
```

- [ ] **Step 9: Run both and expect PASS.**
  `./vendor/bin/pest tests/Feature/Imports/TicketImportPipelineTest.php tests/Feature/Imports/PrepareTicketImportMemoryTest.php`
  Expected: PASS (9 tests). If the memory test fails, the cause is a non-streaming read, not the bound. Find it before touching the 2 MB limit.

- [ ] **Step 10: Run every import test.**
  `./vendor/bin/pest tests/Unit/Imports tests/Feature/Imports`
  Expected: PASS.

- [ ] **Step 11: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 12: Commit.**

```bash
git add app/Actions/Imports/StartTicketImport.php app/Jobs/PrepareTicketImport.php \
  app/Jobs/ImportTicketsChunk.php app/Jobs/FinalizeTicketImport.php \
  tests/Support/Imports/ImportCsvFixture.php tests/Feature/Imports/TicketImportPipelineTest.php \
  tests/Feature/Imports/PrepareTicketImportMemoryTest.php
git commit -m "feat(import): process CSV imports in streamed batches" --trailer "Refs: T11.3"
```

---
### T11.4 — HTTP layer (web + API)

Runs after T11.5, because `assertInertia` checks that the page files exist.

**Inertia props.** These are fixed here, and T11.5 builds against them.
- `admin/imports/Index`: `imports`, a `ResourcePage<TicketImportItem>`.
- `admin/imports/Create`: `maxUploadKb: number`, `columns: { required: string[]; optional: string[] }`.
- `admin/imports/Show`: `ticketImport: TicketImportItem` and `importErrors: ResourcePage<TicketImportErrorItem>`. Both are closures, so a poll (`only: ['ticketImport','importErrors']`) re-evaluates just these two. The prop is named `ticketImport` because `import` is a reserved word in Vue template expressions.

- [ ] **Step 1: Write the failing HTTP test** `tests/Feature/Imports/TicketImportHttpTest.php`.

```php
<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    expect(config('queue.default'))->toBe('sync');
    Storage::fake((string) config('imports.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));

    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent()->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);
});

it('rejects an invalid upload on the web', function (array $payload): void {
    $this->actingAs($this->admin)
        ->post(route('admin.imports.store'), $payload)
        ->assertSessionHasErrors('file');

    expect(TicketImport::query()->count())->toBe(0);
})->with([
    'a PDF' => [fn (): array => ['file' => UploadedFile::fake()->create('tickets.pdf', 10, 'application/pdf')]],
    'larger than 50 MB' => [fn (): array => ['file' => UploadedFile::fake()->create('tickets.csv', 51201, 'text/csv')]],
]);

it('accepts a .txt file containing CSV', function (): void {
    $file = UploadedFile::fake()->createWithContent('tickets.txt', ImportCsvFixture::mixed());

    $this->actingAs($this->admin)
        ->post(route('admin.imports.store'), ['file' => $file])
        ->assertSessionHasNoErrors();

    expect(TicketImport::query()->sole()->original_name)->toBe('tickets.txt');
});

it('stores the upload privately and redirects to the import page', function (): void {
    $file = UploadedFile::fake()->createWithContent('tickets.csv', ImportCsvFixture::mixed());

    $response = $this->actingAs($this->admin)->post(route('admin.imports.store'), ['file' => $file]);

    $import = TicketImport::query()->sole();
    $response->assertRedirect(route('admin.imports.show', $import));
    expect($import->original_name)->toBe('tickets.csv')
        ->and($import->user_id)->toBe($this->admin->id)
        ->and($import->path)->toStartWith('imports/')
        ->and($import->path)->not->toContain('tickets.csv');
    Storage::disk((string) config('imports.disk'))->assertExists($import->path);
});

it('accepts an upload over the API with 202 and the import resource', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $file = UploadedFile::fake()->createWithContent('tickets.csv', ImportCsvFixture::mixed());

    $this->postJson(route('api.v1.imports.store'), ['file' => $file])
        ->assertStatus(202)
        ->assertJsonPath('data.original_name', 'tickets.csv')
        ->assertJsonPath('data.status', ImportStatus::CompletedWithErrors->value)
        ->assertJsonPath('data.total_rows', 9)
        ->assertJsonPath('data.succeeded_rows', 2)
        ->assertJsonPath('data.failed_rows', 7)
        ->assertJsonPath('data.progress_percent', 100)
        ->assertJsonPath('data.is_finished', true)
        ->assertJsonPath('data.user.id', $this->admin->id);
});

it('lists imports on the index page, newest first', function (): void {
    $older = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed(), 'first.csv');
    $newer = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed(), 'second.csv');

    $this->actingAs($this->admin)
        ->get(route('admin.imports.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/imports/Index')
            ->has('imports.data', 2)
            ->where('imports.data.0.id', $newer->id)
            ->where('imports.data.1.id', $older->id)
            ->where('imports.meta.total', 2));
});

it('shows progress and a paginated error table', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $this->actingAs($this->admin)
        ->get(route('admin.imports.show', $import))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/imports/Show')
            ->where('ticketImport.id', $import->id)
            ->where('ticketImport.status', 'completed_with_errors')
            ->where('ticketImport.status_label', 'Completed with errors')
            ->where('ticketImport.processed_rows', 9)
            ->has('importErrors.data', 7)
            ->where('importErrors.data.0.row_number', 3)
            ->where('importErrors.data.0.errors.priority.0', 'The selected priority is invalid.')
            ->where('importErrors.meta.total', 7));
});

it('shows a single import over the API', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.imports.show', $import))
        ->assertOk()
        ->assertJsonPath('data.id', $import->id)
        ->assertJsonPath('data.failed_rows', 7);
});

it('paginates the error rows over the API, ordered by row number', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson(route('api.v1.imports.errors.index', ['import' => $import, 'per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.row_number', 3)
        ->assertJsonPath('data.1.row_number', 4)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 7);

    $this->getJson(route('api.v1.imports.errors.index', ['import' => $import, 'per_page' => 101]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('streams the error rows as a CSV that neutralises spreadsheet formulas', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $response = $this->actingAs($this->admin)->get(route('admin.imports.errors.download', $import));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain(sprintf('import-%d-errors.csv', $import->id));

    $lines = explode("\n", trim($response->streamedContent()));

    expect($lines)->toHaveCount(8)
        ->and($lines[0])->toBe('row_number,errors,external_reference,subject,description,priority,category,customer_email,status,created_at')
        ->and($lines[1])->toStartWith('3,"The selected priority is invalid.",EXT-2,\'=SUM(A1:A2),')
        ->and($lines[7])->toStartWith('10,"The customer email must belong to an existing customer."');
});
```

- [ ] **Step 2: Write the failing authorization test** `tests/Feature/Authorization/ImportAuthorizationTest.php`. It uses T04's `Tests\Support\AuthorizationMatrix`, which sends `/api/` URIs as JSON with Sanctum and everything else through the session.

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\TicketImport;
use App\Models\User;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    $this->import = ImportCsvFixture::pendingImport(User::factory()->admin()->create());
});

/**
 * [method, uri, status for an admin, status for a guest].
 * The admin POSTs carry no file, so they stop at validation: the web gets a 302 back with errors, the API a 422.
 *
 * @return list<array{0: string, 1: string, 2: int, 3: int}>
 */
function importAuthorizationRows(TicketImport $import): array
{
    return [
        ['GET', route('admin.imports.index'), 200, 302],
        ['GET', route('admin.imports.create'), 200, 302],
        ['POST', route('admin.imports.store'), 302, 302],
        ['GET', route('admin.imports.show', $import), 200, 302],
        ['GET', route('admin.imports.errors.download', $import), 200, 302],
        ['POST', route('api.v1.imports.store'), 422, 401],
        ['GET', route('api.v1.imports.show', $import), 200, 401],
        ['GET', route('api.v1.imports.errors.index', $import), 200, 401],
    ];
}

it('lets only admins use the import routes, on web and API', function (Role $role): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole($role);

    foreach (importAuthorizationRows($this->import) as [$method, $uri, $adminStatus]) {
        $matrix->expectAccess($method, $uri, $role === Role::Admin ? $adminStatus : 403);
    }
})->with(AuthorizationMatrix::roles());

it('sends guests to the login page on the web and 401 on the API', function (): void {
    $matrix = AuthorizationMatrix::for($this)->asGuest();

    foreach (importAuthorizationRows($this->import) as [$method, $uri, , $guestStatus]) {
        $response = $matrix->expectAccess($method, $uri, $guestStatus);

        if ($guestStatus === 302) {
            $response->assertRedirect(route('login'));
        }
    }
});

it('rejects an agent POST before validation runs', function (): void {
    $matrix = AuthorizationMatrix::for($this);
    $matrix->actingAsRole(Role::Agent);

    // A 403 rather than a 422 proves the `can:` middleware runs before StoreTicketImportRequest.
    $matrix->expectAccess('POST', route('api.v1.imports.store'), 403, ['file' => 'not-a-file']);
});
```

  > The guest `POST` on the web returns a redirect, not 419, because Laravel 13's `PreventRequestForgery` (the renamed CSRF middleware; `VerifyCsrfToken`/`ValidateCsrfToken` are deprecated aliases) skips verification while running unit tests.

- [ ] **Step 3: Run both and expect FAIL.**
  `./vendor/bin/pest tests/Feature/Imports/TicketImportHttpTest.php tests/Feature/Authorization/ImportAuthorizationTest.php`
  Expected: FAIL with `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [admin.imports.store] not defined.`

- [ ] **Step 4: Create** `app/Http/Requests/StoreTicketImportRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

final class StoreTicketImportRequest extends FormRequest
{
    /** Authorization runs earlier, in the route's `can:create` middleware (TicketImportPolicy). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('imports.max_upload_kb')],
        ];
    }

    public function csv(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('csv() called before validation.');
        }

        return $file;
    }
}
```

- [ ] **Step 5: Create** `app/Http/Requests/ListTicketImportErrorsRequest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListTicketImportErrorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }
}
```

- [ ] **Step 6: Create** `app/Http/Resources/V1/TicketImportResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\ImportStatus;
use App\Models\TicketImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketImport */
final class TicketImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $finished = in_array($status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Failed], true);
        $total = (int) $this->total_rows;
        $processed = (int) $this->processed_rows;

        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => $status->value,
            'status_label' => self::label($status),
            'is_finished' => $finished,
            'total_rows' => $total,
            'processed_rows' => $processed,
            'succeeded_rows' => (int) $this->succeeded_rows,
            'failed_rows' => (int) $this->failed_rows,
            'progress_percent' => $total === 0
                ? ($finished ? 100 : 0)
                : min(100, intdiv($processed * 100, $total)),
            'started_at' => self::iso($this->started_at),
            'finished_at' => self::iso($this->finished_at),
            'created_at' => self::iso($this->created_at),
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
        ];
    }

    private static function label(ImportStatus $status): string
    {
        return match ($status) {
            ImportStatus::Pending => 'Pending',
            ImportStatus::Processing => 'Processing',
            ImportStatus::Completed => 'Completed',
            ImportStatus::CompletedWithErrors => 'Completed with errors',
            ImportStatus::Failed => 'Failed',
        };
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
```

- [ ] **Step 7: Create** `app/Http/Resources/V1/TicketImportErrorResource.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\TicketImportError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketImportError */
final class TicketImportErrorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => (int) $this->row_number,
            'errors' => $this->errors,
            'raw' => $this->raw,
            'created_at' => $this->created_at === null ? null : CarbonImmutable::parse($this->created_at)->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 8: Create** `app/Services/Imports/TicketImportQuery.php`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\TicketImport;
use App\Models\TicketImportError;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/** The read side of imports, so controllers stay query-free (TD-001). */
final class TicketImportQuery
{
    /** @return LengthAwarePaginator<int, TicketImport> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return TicketImport::query()
            ->with('user:id,name')
            ->latest('id')
            ->paginate($perPage);
    }

    /** Served by the (ticket_import_id, row_number) index. @return LengthAwarePaginator<int, TicketImportError> */
    public function errors(TicketImport $import, int $perPage): LengthAwarePaginator
    {
        return $import->errors()
            ->orderBy('row_number')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** All error rows, 1,000 at a time, for the streamed download. @return LazyCollection<int, TicketImportError> */
    public function allErrors(TicketImport $import): LazyCollection
    {
        return $import->errors()
            ->orderBy('row_number')
            ->orderBy('id')
            ->lazy(1000);
    }
}
```

- [ ] **Step 9: Create** `app/Services/Imports/ImportErrorCsvWriter.php`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\TicketImport;
use Illuminate\Support\Arr;

final class ImportErrorCsvWriter
{
    public function __construct(private readonly TicketImportQuery $imports) {}

    /** @param resource $stream */
    public function write(TicketImport $import, $stream): void
    {
        fputcsv($stream, ['row_number', 'errors', ...TicketCsvReader::COLUMNS], ',', '"', '');

        foreach ($this->imports->allErrors($import) as $error) {
            /** @var array<int|string, mixed> $raw */
            $raw = is_array($error->raw) ? $error->raw : [];
            /** @var array<string, mixed> $messages */
            $messages = is_array($error->errors) ? $error->errors : [];

            $line = [(string) $error->row_number, implode(' | ', Arr::flatten($messages))];
            foreach (TicketCsvReader::COLUMNS as $column) {
                $line[] = isset($raw[$column]) ? (string) $raw[$column] : '';
            }

            fputcsv($stream, array_map(self::cell(...), $line), ',', '"', '');
        }
    }

    /** CSV-injection guard: a spreadsheet must not run an uploaded value as a formula. */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
```

- [ ] **Step 10: Create** `app/Http/Controllers/Web/Admin/TicketImportController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Imports\StartTicketImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketImportRequest;
use App\Http\Resources\V1\TicketImportErrorResource;
use App\Http\Resources\V1\TicketImportResource;
use App\Models\TicketImport;
use App\Models\User;
use App\Services\Imports\TicketCsvReader;
use App\Services\Imports\TicketImportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TicketImportController extends Controller
{
    public function __construct(private readonly TicketImportQuery $imports) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/imports/Index', [
            'imports' => TicketImportResource::collection($this->imports->paginate(20))
                ->response($request)
                ->getData(true),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/imports/Create', [
            'maxUploadKb' => (int) config('imports.max_upload_kb'),
            'columns' => [
                'required' => TicketCsvReader::REQUIRED_COLUMNS,
                'optional' => array_values(array_diff(TicketCsvReader::COLUMNS, TicketCsvReader::REQUIRED_COLUMNS)),
            ],
        ]);
    }

    public function store(StoreTicketImportRequest $request, StartTicketImport $start): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $import = $start->handle($admin, $request->csv());

        return redirect()
            ->route('admin.imports.show', $import)
            ->with('success', 'Import started. Progress updates on this page.');
    }

    public function show(Request $request, TicketImport $import): Response
    {
        return Inertia::render('admin/imports/Show', [
            'ticketImport' => fn (): array => TicketImportResource::make($import->load('user:id,name'))->resolve($request),
            'importErrors' => fn (): mixed => TicketImportErrorResource::collection($this->imports->errors($import, 25))
                ->response($request)
                ->getData(true),
        ]);
    }
}
```

- [ ] **Step 11: Create** `app/Http/Controllers/Web/Admin/DownloadTicketImportErrorsController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketImport;
use App\Services\Imports\ImportErrorCsvWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadTicketImportErrorsController extends Controller
{
    public function __invoke(TicketImport $import, ImportErrorCsvWriter $writer): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($import, $writer): void {
                $stream = fopen('php://output', 'wb');
                $writer->write($import, $stream);
                fclose($stream);
            },
            sprintf('import-%d-errors.csv', $import->id),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
```

- [ ] **Step 12: Create** `app/Http/Controllers/Api/V1/TicketImportController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Imports\StartTicketImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketImportRequest;
use App\Http\Resources\V1\TicketImportResource;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class TicketImportController extends Controller
{
    /** 202: the import continues in the background; poll `imports.show` for progress. */
    public function store(StoreTicketImportRequest $request, StartTicketImport $start): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $import = $start->handle($admin, $request->csv());

        return TicketImportResource::make($import->load('user:id,name'))
            ->response()
            ->setStatusCode(202);
    }

    public function show(TicketImport $import): TicketImportResource
    {
        return TicketImportResource::make($import->load('user:id,name'));
    }
}
```

- [ ] **Step 13: Create** `app/Http/Controllers/Api/V1/TicketImportErrorController.php`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListTicketImportErrorsRequest;
use App\Http\Resources\V1\TicketImportErrorResource;
use App\Models\TicketImport;
use App\Services\Imports\TicketImportQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TicketImportErrorController extends Controller
{
    public function index(
        ListTicketImportErrorsRequest $request,
        TicketImport $import,
        TicketImportQuery $imports,
    ): AnonymousResourceCollection {
        return TicketImportErrorResource::collection($imports->errors($import, $request->perPage()));
    }
}
```

- [ ] **Step 14: Create** `routes/web/imports.php`. `bootstrap/app.php` (T00) loads it inside the `web` group.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\DownloadTicketImportErrorsController;
use App\Http\Controllers\Web\Admin\TicketImportController;
use App\Models\TicketImport;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('admin/imports')
    ->name('admin.imports.')
    ->group(function (): void {
        Route::get('/', [TicketImportController::class, 'index'])
            ->name('index')
            ->can('viewAny', TicketImport::class);

        Route::get('create', [TicketImportController::class, 'create'])
            ->name('create')
            ->can('create', TicketImport::class);

        Route::post('/', [TicketImportController::class, 'store'])
            ->name('store')
            ->middleware('throttle:20,1')
            ->can('create', TicketImport::class);

        Route::get('{import}', [TicketImportController::class, 'show'])
            ->name('show')
            ->can('view', 'import');

        Route::get('{import}/errors/download', DownloadTicketImportErrorsController::class)
            ->name('errors.download')
            ->can('view', 'import');
    });
```

- [ ] **Step 15: Create** `routes/api/v1/imports.php`. It is loaded with prefix `api/v1` and name prefix `api.v1.`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TicketImportController;
use App\Http\Controllers\Api\V1\TicketImportErrorController;
use App\Models\TicketImport;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('imports', [TicketImportController::class, 'store'])
        ->name('imports.store')
        ->middleware('throttle:20,1')
        ->can('create', TicketImport::class);

    Route::get('imports/{import}', [TicketImportController::class, 'show'])
        ->name('imports.show')
        ->can('view', 'import');

    Route::get('imports/{import}/errors', [TicketImportErrorController::class, 'index'])
        ->name('imports.errors.index')
        ->can('view', 'import');
});
```

- [ ] **Step 16: Run both and expect PASS.**
  `./vendor/bin/pest tests/Feature/Imports/TicketImportHttpTest.php tests/Feature/Authorization/ImportAuthorizationTest.php`
  Expected: PASS (15 tests: 10 in the HTTP file, counting 2 dataset rows, and 5 in the authorization file, counting 3 role rows).

- [ ] **Step 17: Format and analyse.**
  `./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G`
  Expected: `[OK] No errors`.

- [ ] **Step 18: Commit.**

```bash
git add app/Http/Requests/StoreTicketImportRequest.php app/Http/Requests/ListTicketImportErrorsRequest.php \
  app/Http/Resources/V1/TicketImportResource.php app/Http/Resources/V1/TicketImportErrorResource.php \
  app/Services/Imports/TicketImportQuery.php app/Services/Imports/ImportErrorCsvWriter.php \
  app/Http/Controllers/Web/Admin/TicketImportController.php \
  app/Http/Controllers/Web/Admin/DownloadTicketImportErrorsController.php \
  app/Http/Controllers/Api/V1/TicketImportController.php app/Http/Controllers/Api/V1/TicketImportErrorController.php \
  routes/web/imports.php routes/api/v1/imports.php \
  tests/Feature/Imports/TicketImportHttpTest.php tests/Feature/Authorization/ImportAuthorizationTest.php
git commit -m "feat(import): add import endpoints, resources and errors CSV" --trailer "Refs: T11.4"
```

---
### T11.5 — Frontend: types, components, pages, navigation

UI components come from the starter kit: `@/components/ui/button`, `card`, `label`, and `@/components/InputError.vue`. The tables and the status badge are plain Tailwind markup, so this task needs no shadcn components beyond those. Links use literal paths, because the contract doesn't fix a client-side route helper.

- [ ] **Step 1: Create** `resources/js/types/imports.ts`.

```ts
export type ImportStatusValue =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed';

export interface ImportUser {
    id: number;
    name: string;
}

export interface TicketImportItem {
    id: number;
    original_name: string;
    status: ImportStatusValue;
    status_label: string;
    is_finished: boolean;
    total_rows: number;
    processed_rows: number;
    succeeded_rows: number;
    failed_rows: number;
    progress_percent: number;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
    user?: ImportUser;
}

export interface TicketImportErrorItem {
    id: number;
    row_number: number;
    errors: Record<string, string[]>;
    raw: Record<string, string | null> | Array<string | null>;
    created_at: string | null;
}

/** Laravel's paginated ResourceCollection response: { data, links, meta }. */
export interface ResourcePage<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
}
```

- [ ] **Step 2: Create** `resources/js/components/imports/ImportStatusBadge.vue`.

```vue
<script setup lang="ts">
import { computed } from 'vue';
import type { ImportStatusValue } from '@/types/imports';

const props = defineProps<{ status: ImportStatusValue; label: string }>();

const classes = computed((): string => {
    switch (props.status) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300';
        case 'completed_with_errors':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300';
        case 'failed':
            return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300';
        case 'processing':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300';
        default:
            return 'bg-muted text-muted-foreground';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
```

- [ ] **Step 3: Create** `resources/js/components/imports/ImportPagination.vue`.

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { ResourcePage } from '@/types/imports';

defineProps<{
    links: ResourcePage<unknown>['links'];
    meta: ResourcePage<unknown>['meta'];
    only?: string[];
}>();
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex items-center justify-between gap-2 text-sm"
        aria-label="Pagination"
    >
        <span class="text-muted-foreground">
            Page {{ meta.current_page }} of {{ meta.last_page }} ·
            {{ meta.total }} total
        </span>
        <div class="flex gap-2">
            <Button v-if="links.prev" variant="outline" size="sm" as-child>
                <Link
                    :href="links.prev"
                    :only="only"
                    preserve-scroll
                    preserve-state
                    >Previous</Link
                >
            </Button>
            <Button v-if="links.next" variant="outline" size="sm" as-child>
                <Link
                    :href="links.next"
                    :only="only"
                    preserve-scroll
                    preserve-state
                    >Next</Link
                >
            </Button>
        </div>
    </nav>
</template>
```

- [ ] **Step 4: Create** `resources/js/pages/admin/imports/Index.vue`.

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ImportPagination from '@/components/imports/ImportPagination.vue';
import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { ResourcePage, TicketImportItem } from '@/types/imports';

defineProps<{ imports: ResourcePage<TicketImportItem> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Imports', href: '/admin/imports' }],
    },
});

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head title="Ticket imports" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Ticket imports</h1>
            <Button as-child>
                <Link href="/admin/imports/create">New import</Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
                <CardDescription
                    >Every CSV upload, newest first.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p
                    v-if="imports.data.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No imports yet.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-4">File</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4 text-right">Rows</th>
                                <th class="py-2 pr-4 text-right">Imported</th>
                                <th class="py-2 pr-4 text-right">Rejected</th>
                                <th class="py-2 pr-4">Uploaded by</th>
                                <th class="py-2">Uploaded</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in imports.data"
                                :key="item.id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-4">
                                    <Link
                                        :href="`/admin/imports/${item.id}`"
                                        class="font-medium underline-offset-4 hover:underline"
                                    >
                                        {{ item.original_name }}
                                    </Link>
                                </td>
                                <td class="py-2 pr-4">
                                    <ImportStatusBadge
                                        :status="item.status"
                                        :label="item.status_label"
                                    />
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ item.total_rows }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ item.succeeded_rows }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ item.failed_rows }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ item.user?.name ?? '—' }}
                                </td>
                                <td class="py-2">
                                    {{ formatDate(item.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ImportPagination :links="imports.links" :meta="imports.meta" />
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 5: Create** `resources/js/pages/admin/imports/Create.vue`.

```vue
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    maxUploadKb: number;
    columns: { required: string[]; optional: string[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Imports', href: '/admin/imports' },
            { title: 'New import', href: '/admin/imports/create' },
        ],
    },
});

const form = useForm<{ file: File | null }>({ file: null });

const maxMb = computed((): number => Math.round(props.maxUploadKb / 1024));
const headerExample = computed((): string =>
    [
        ...props.columns.optional.slice(0, 1),
        ...props.columns.required,
        ...props.columns.optional.slice(1),
    ].join(','),
);

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
}

function submit(): void {
    form.post('/admin/imports', { forceFormData: true });
}
</script>

<template>
    <Head title="New ticket import" />

    <div class="flex max-w-3xl flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Import tickets from CSV</h1>

        <Card>
            <CardHeader>
                <CardTitle>File format</CardTitle>
                <CardDescription
                    >The first row must be a header. Columns may be in any
                    order.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-3 text-sm">
                <p>
                    <span class="font-medium">Required:</span>
                    <code
                        v-for="column in columns.required"
                        :key="column"
                        class="mx-1 rounded bg-muted px-1"
                        >{{ column }}</code
                    >
                </p>
                <p>
                    <span class="font-medium">Optional:</span>
                    <code
                        v-for="column in columns.optional"
                        :key="column"
                        class="mx-1 rounded bg-muted px-1"
                        >{{ column }}</code
                    >
                </p>
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>
                        <code>priority</code>: low, medium, high or critical.
                    </li>
                    <li>
                        <code>category</code>: the slug of an active category.
                    </li>
                    <li>
                        <code>customer_email</code>: must belong to an existing
                        customer account.
                    </li>
                    <li>
                        <code>status</code>: open (default), in_progress,
                        waiting_for_customer, resolved or closed.
                    </li>
                    <li>
                        <code>created_at</code>: a past date such as
                        <code>2026-10-29 17:00</code>, read in business time.
                        The SLA is measured from it.
                    </li>
                    <li>
                        <code>external_reference</code>: up to 64 characters. A
                        row whose reference was already imported is reported as
                        a duplicate.
                    </li>
                </ul>
                <pre class="overflow-x-auto rounded bg-muted p-2 text-xs">{{
                    headerExample
                }}</pre>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="pt-6">
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="file"
                            >CSV file (up to {{ maxMb }} MB)</Label
                        >
                        <input
                            id="file"
                            type="file"
                            accept=".csv,text/csv,text/plain"
                            class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-primary-foreground"
                            @change="onFileChange"
                        />
                        <InputError :message="form.errors.file" />
                    </div>

                    <div
                        v-if="form.progress"
                        class="h-2 w-full overflow-hidden rounded bg-muted"
                        role="progressbar"
                    >
                        <div
                            class="h-full bg-primary transition-all"
                            :style="{
                                width: `${form.progress.percentage ?? 0}%`,
                            }"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            type="submit"
                            :disabled="form.processing || form.file === null"
                            >Start import</Button
                        >
                        <Button variant="ghost" as-child>
                            <Link href="/admin/imports">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 6: Create** `resources/js/pages/admin/imports/Show.vue`. It polls every 2 s with Inertia 3's `usePoll(interval, reloadOptions, { autoStart })` (`@inertiajs/vue3` `types/usePoll.d.ts`), reloading only the two props, and stops once the import has finished.

```vue
<script setup lang="ts">
import { Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, watch, watchEffect } from 'vue';
import ImportPagination from '@/components/imports/ImportPagination.vue';
import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type {
    ResourcePage,
    TicketImportErrorItem,
    TicketImportItem,
} from '@/types/imports';

const props = defineProps<{
    ticketImport: TicketImportItem;
    importErrors: ResourcePage<TicketImportErrorItem>;
}>();

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Imports', href: '/admin/imports' },
    {
        title: props.ticketImport.original_name,
        href: `/admin/imports/${props.ticketImport.id}`,
    },
]);

// The breadcrumbs depend on props, so they're pushed to the default AppLayout (app.ts) reactively.
watchEffect(() => {
    setLayoutProps({ breadcrumbs: breadcrumbs.value });
});

const { stop } = usePoll(
    2000,
    { only: ['ticketImport', 'importErrors'] },
    { autoStart: !props.ticketImport.is_finished },
);

watch(
    () => props.ticketImport.is_finished,
    (finished: boolean): void => {
        if (finished) {
            stop();
        }
    },
);

const counters = computed(() => [
    { label: 'Rows in file', value: props.ticketImport.total_rows },
    { label: 'Processed', value: props.ticketImport.processed_rows },
    { label: 'Imported', value: props.ticketImport.succeeded_rows },
    { label: 'Rejected', value: props.ticketImport.failed_rows },
]);

function messages(error: TicketImportErrorItem): string[] {
    return Object.values(error.errors).flat();
}

function subjectOf(error: TicketImportErrorItem): string {
    if (Array.isArray(error.raw)) {
        return '—';
    }

    return error.raw.subject ?? '—';
}
</script>

<template>
    <Head :title="`Import: ${ticketImport.original_name}`" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">
                    {{ ticketImport.original_name }}
                </h1>
                <ImportStatusBadge
                    :status="ticketImport.status"
                    :label="ticketImport.status_label"
                />
            </div>
            <Button
                v-if="
                    ticketImport.failed_rows > 0 ||
                    ticketImport.status === 'failed'
                "
                variant="outline"
                as-child
            >
                <a :href="`/admin/imports/${ticketImport.id}/errors/download`"
                    >Download errors (CSV)</a
                >
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Progress</CardTitle>
                <CardDescription v-if="!ticketImport.is_finished"
                    >Updates automatically every few seconds.</CardDescription
                >
                <CardDescription v-else
                    >Finished
                    {{
                        ticketImport.finished_at
                            ? new Date(
                                  ticketImport.finished_at,
                              ).toLocaleString()
                            : ''
                    }}</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div
                    class="h-3 w-full overflow-hidden rounded bg-muted"
                    role="progressbar"
                    :aria-valuenow="ticketImport.progress_percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full bg-primary transition-all"
                        :style="{ width: `${ticketImport.progress_percent}%` }"
                    />
                </div>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div
                        v-for="counter in counters"
                        :key="counter.label"
                        class="rounded-lg border p-3"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ counter.label }}
                        </dt>
                        <dd class="text-2xl font-semibold tabular-nums">
                            {{ counter.value }}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Rejected rows</CardTitle>
                <CardDescription>
                    Row numbers match the spreadsheet; the header is row 1. Fix
                    these rows and upload them again.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p
                    v-if="importErrors.data.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No rejected rows.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-2 pr-4">Row</th>
                                <th class="py-2 pr-4">Subject</th>
                                <th class="py-2">Problems</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="error in importErrors.data"
                                :key="error.id"
                                class="border-b align-top last:border-0"
                            >
                                <td class="py-2 pr-4 tabular-nums">
                                    {{ error.row_number }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ subjectOf(error) }}
                                </td>
                                <td class="py-2">
                                    <ul class="list-disc pl-4">
                                        <li
                                            v-for="message in messages(error)"
                                            :key="message"
                                        >
                                            {{ message }}
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ImportPagination
                    :links="importErrors.links"
                    :meta="importErrors.meta"
                    :only="['importErrors']"
                />
            </CardContent>
        </Card>

        <div>
            <Button variant="ghost" as-child>
                <Link href="/admin/imports">Back to imports</Link>
            </Button>
        </div>
    </div>
</template>
```

- [ ] **Step 7: Create** `resources/js/config/navigation/imports.ts`.

```ts
import { FileUp } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';

const items: NavItem[] = [
    {
        title: 'Imports',
        href: '/admin/imports',
        icon: FileUp,
        order: 60,
        visible: (page) => page.auth.can.manageImports,
    },
];

export default items;
```

- [ ] **Step 8: Type-check and build.**
  `npm run build && npm run check && npx vue-tsc --noEmit`
  Expected: no type errors, and Vite prints `✓ built in …`. A missing page or type shows up here as an error naming the file.

- [ ] **Step 9: Commit.**

```bash
git add resources/js/types/imports.ts resources/js/components/imports/ImportStatusBadge.vue \
  resources/js/components/imports/ImportPagination.vue resources/js/pages/admin/imports/Index.vue \
  resources/js/pages/admin/imports/Create.vue resources/js/pages/admin/imports/Show.vue \
  resources/js/config/navigation/imports.ts
git commit -m "feat(import): add import pages with progress and error table" --trailer "Refs: T11.5"
```

---

## 6. Acceptance criteria

- [ ] Uploads are validated with `required|file|mimes:csv,txt|max:51200`. The limit comes from `config('imports.max_upload_kb')`. Files are stored with random names on the private `imports.disk`.
- [ ] `StartTicketImport` creates a `pending` import and dispatches `PrepareTicketImport` on queue `imports`.
- [ ] `PrepareTicketImport` streams the file (SplFileObject `READ_CSV` + `LazyCollection`) and validates the header. If the header is invalid, it raises `ImportHeaderInvalid`, which marks the import `failed`, records error row 1 and fires `TicketImportFinished`.
- [ ] Otherwise Prepare counts the rows, sets `processing`, `total_rows` and `started_at`, and dispatches a named `Bus::batch` of `ImportTicketsChunk` jobs by record range on queue `imports`, with `allowFailures()` and a `finally` that dispatches `FinalizeTicketImport`.
- [ ] Preparing a 10,000-row (~6 MB) file grows peak memory by less than 2 MB.
- [ ] `ImportTicketsChunk` seeks to its start record and validates each row with `ImportTicketRowRules`:
  - category by active slug
  - `customer_email` belongs to a customer
  - priority and status are valid enum values
  - `created_at` parses and is not after now
  - `external_reference` ≤ 64 characters and not already present
- [ ] Every rejected row writes `ticket_import_errors(row_number, errors{field: [messages]}, raw)`. Counters change only through atomic `incrementEach`.
- [ ] `ImportTicketRow` runs one transaction per row:
  - It uses `TicketNumberGenerator::next()` and `SlaCalculator::applyInitial()`.
  - It sets `sla_started_at = created_at` when one is given, and `created_by_id` is the importing admin.
  - It fires `TicketCreated($ticket, $admin, imported: true)`.
- [ ] A `UniqueConstraintViolationException` on `external_reference` becomes a duplicate error row. The ticket and its sequence number roll back.
- [ ] Imported tickets are not auto-assigned, and customers receive no notification.
- [ ] `FinalizeTicketImport` sets `completed`, `completed_with_errors` or `failed` (failed when the batch has failures), sets `finished_at`, and fires `TicketImportFinished` exactly once.
- [ ] Web: Index, Create and Show pages exist. Show polls with `usePoll` until finished, paginates the errors, and links to a streamed errors CSV (`nosniff`, attachment, formula-escaped).
- [ ] API: `imports.store` returns 202, plus `imports.show` and `imports.errors.index` (paginated, `per_page` ≤ 100).
- [ ] Every import route is admin-only, via `viewAny`/`create` and `view` on `{import}`. Agents and customers get 403, and guests get a login redirect or 401. ImportAuthorizationTest checks this through T04's `AuthorizationMatrix`.
- [ ] `navigation/imports.ts` shows "Imports" only when `auth.can.manageImports`.

## 7. Verification

```bash
./vendor/bin/pest tests/Unit/Imports tests/Feature/Imports tests/Feature/Authorization/ImportAuthorizationTest.php
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pest --parallel
npm run build && npx vue-tsc --noEmit
```

Expected:
- The import tests pass. That is 6 reader tests, 17 rules and action tests, 9 pipeline and memory tests, and 15 HTTP and authorization tests.
- `pint --test` passes, and PHPStan prints `[OK] No errors`.
- The full suite has no failures.
- The build succeeds with no type errors.

Manual check, with a worker running `php artisan queue:work --queue=imports`:
1. Upload `ImportCsvFixture::mixed()` saved as a `.csv`.
2. The Show page moves from Pending to Completed with errors.
3. The download contains 7 rows.

## Contract notes for controller

1. **Initial status on import.** `status` is not fillable, and the rules say status changes go through `ChangeTicketStatus`. `ImportTicketRow` sets the *initial* status directly instead. Side effects:
   - waiting → `SlaCalculator::pause`
   - resolved → `resolved_at`
   - closed → `resolved_at` and `closed_at`

   `ChangeTicketStatus` would fire `TicketStatusChanged` for an event that never happened. Please confirm.
2. **`ImportHeaderInvalid` construction.** §3.2 gives it no named constructor, so this plan uses `new ImportHeaderInvalid($message)`.
3. **Failure message storage.** `ticket_imports` has no failure-message column (spec §16 says "failed with a message"). This plan stores the reason as an error row instead of adding a migration: row 1 for the header, the chunk's first row for a chunk crash.
4. **Retries.** TD-072 says `tries=3`. `ImportTicketsChunk` uses `tries=1`, because a retry would re-import committed rows that have no `external_reference`. Per-row errors are caught instead. Prepare and Finalize keep 3 tries.
5. **Finalize queue.** §3.8 puts all three jobs on `imports`; TD-072 says the finalize steps run on `default`. This plan follows §3.8.
6. **`batch_id` timing.** On the sync driver the batch's `finally` runs before `dispatch()` returns. So `batch_id` is also stamped inside `finally`.
7. **Show prop name.** It is `ticketImport`, not `import`, because `import` is reserved in Vue template expressions.
8. **Unpinned frontend contracts.**
   - The kit ships Wayfinder (`@/routes/...`, `@/actions/...`), but README §3 doesn't pin a client route helper for feature pages, so the pages use literal paths. They then don't depend on the generated Wayfinder files being regenerated for T11's routes.
   - The shape of `Paginated<T>` (`types/tickets.ts`) isn't pinned, so T11 defines a local `ResourcePage<T>`.
9. **Unspecified fillables.** `TicketImport` and `TicketImportError` have no fillables in the contract. This plan assigns attributes one by one.
10. **Resolved:** T04 added `TicketImportPolicy::view(User, TicketImport)`. The show, errors-index and errors-download routes use `->can('view', 'import')`, and only the index uses `viewAny`.
11. **Upload throttle.** The contract names no limiter, so this plan uses `throttle:20,1` inline.
