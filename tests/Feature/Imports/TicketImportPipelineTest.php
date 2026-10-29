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
    Notification::fake();
    Storage::fake((string) config('imports.disk'));
    config(['imports.chunk_size' => 3]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    User::factory()->customer()->create(['email' => 'customer@example.com']);
    User::factory()->agent()->create(['email' => 'agent@example.com']);
    Ticket::factory()->create(['external_reference' => 'EXT-OLD']);
});

it('imports the valid rows with distinct numbers and finishes with errors', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $imported = Ticket::query()->where('created_by_id', $this->admin->id)->orderBy('id')->get();
    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and([$import->total_rows, $import->processed_rows, $import->succeeded_rows, $import->failed_rows])->toBe([9, 9, 2, 7])
        ->and($import->batch_id)->not->toBeNull()
        ->and($import->started_at)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull()
        ->and($imported->pluck('subject')->all())->toBe(['Printer on fire', 'Waiting on customer'])
        ->and($imported->pluck('number')->unique())->toHaveCount(2)
        ->and($imported[1]->status)->toBe(TicketStatus::WaitingForCustomer);
});

it('records each rejected row with its row number, messages and raw values', function (): void {
    $import = ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    $errors = TicketImportError::query()->where('ticket_import_id', $import->id)->orderBy('row_number')->get()->keyBy('row_number');
    expect($errors->keys()->all())->toBe([3, 4, 5, 7, 8, 9, 10])
        ->and($errors[3]->errors)->toBe(['priority' => ['The selected priority is invalid.']])
        ->and($errors[3]->raw['subject'])->toBe('=SUM(A1:A2)')
        ->and($errors[4]->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($errors[5]->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($errors[7]->errors)->toBe(['customer_email' => ['The customer email must belong to an existing customer.']])
        ->and($errors[8]->errors)->toBe(['created_at' => ['The created at field must not be in the future.']])
        ->and($errors[9]->errors)->toBe(['category' => ['The selected category is invalid.']])
        ->and($errors[10]->errors)->toBe(['customer_email' => ['The customer email must belong to an existing customer.']]);
});

it('fires TicketImportFinished exactly once', function (): void {
    Event::fake([TicketImportFinished::class]);

    ImportCsvFixture::run($this->admin, ImportCsvFixture::mixed());

    Event::assertDispatchedTimes(TicketImportFinished::class, 1);
});

it('fails the import on an invalid header without importing anything', function (): void {
    $import = ImportCsvFixture::run($this->admin, "subject,description,priority\nPrinter,Broken,low\n");

    $error = TicketImportError::query()->where('ticket_import_id', $import->id)->sole();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->batch_id)->toBeNull()
        ->and(Ticket::query()->count())->toBe(1)
        ->and($error->row_number)->toBe(1)
        ->and($error->errors)->toBe(['header' => ['The CSV header is invalid: missing required columns: category, customer_email.']]);
});

it('completes a file without rejected rows, including one with no data rows', function (string $csv, int $succeeded): void {
    $import = ImportCsvFixture::run($this->admin, $csv);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->succeeded_rows)->toBe($succeeded)
        ->and($import->finished_at)->not->toBeNull();
})->with([
    'one valid row' => ["subject,description,priority,category,customer_email\nPrinter,Broken,low,hardware,customer@example.com\n", 1],
    'header only' => ["subject,description,priority,category,customer_email\n\n", 0],
]);

it('keeps the values of a row with the wrong column count under their header names', function (): void {
    $csv = "external_reference,subject,description,priority,category,customer_email\nEXT-9,Printer,Broken,low,hardware\n";

    $import = ImportCsvFixture::run($this->admin, $csv);

    $error = TicketImportError::query()->where('ticket_import_id', $import->id)->sole();
    expect($error->raw)->toEqualCanonicalizing([
        'external_reference' => 'EXT-9',
        'subject' => 'Printer',
        'description' => 'Broken',
        'priority' => 'low',
        'category' => 'hardware',
    ]);
});
