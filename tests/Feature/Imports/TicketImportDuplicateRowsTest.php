<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImportError;
use App\Models\User;
use App\Services\Imports\ImportDuplicateTracker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    Notification::fake();
    Storage::fake((string) config('imports.disk'));
    config(['imports.chunk_size' => 2]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create(['email' => 'customer@example.com']);
});

function duplicateCsv(string ...$rows): string
{
    return implode("\n", ['subject,description,priority,category,customer_email,created_at', ...$rows])."\n";
}

it('rejects a row without external reference that matches an existing ticket by customer, subject and created at', function (): void {
    Ticket::factory()->create(['customer_id' => $this->customer->id, 'subject' => 'Printer on fire', 'created_at' => CarbonImmutable::parse('2026-10-29 17:00', config('business_hours.timezone'))->utc()]);

    $import = ImportCsvFixture::run($this->admin, duplicateCsv(
        'Printer on fire,Broken,low,hardware,customer@example.com,2026-10-29 17:00',
        'Printer on fire,Broken,low,hardware,customer@example.com,2026-10-29 18:00',
    ));

    $error = TicketImportError::query()->where('ticket_import_id', $import->id)->sole();
    expect($import->succeeded_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(1)
        ->and($error->row_number)->toBe(2)
        ->and($error->errors)->toBe(['row' => ['Duplicate of an existing ticket.']]);
});

it('rejects a second identical row in the same file and points at the first', function (): void {
    config(['imports.chunk_size' => 500]);
    $import = ImportCsvFixture::run($this->admin, duplicateCsv(
        'Printer on fire,Broken,low,hardware,customer@example.com,2026-10-29 17:00',
        'Other,Broken,low,hardware,customer@example.com,2026-10-29 17:00',
        'printer on fire,Again,high,hardware,CUSTOMER@example.com,2026-10-29 17:00',
    ));

    $error = TicketImportError::query()->where('ticket_import_id', $import->id)->sole();
    expect($import->succeeded_rows)->toBe(2)
        ->and($error->row_number)->toBe(4)
        ->and($error->errors)->toBe(['row' => ['Duplicate of row 2.']]);
});

it('imports nothing new when the same file is uploaded again', function (): void {
    $csv = duplicateCsv(
        'First,Broken,low,hardware,customer@example.com,2026-10-29 17:00',
        'Second,Broken,low,hardware,customer@example.com,2026-10-29 18:00',
        'Third,Broken,low,hardware,customer@example.com,2026-10-29 19:00',
    );
    ImportCsvFixture::run($this->admin, $csv);

    $second = ImportCsvFixture::run($this->admin, $csv);

    expect($second->succeeded_rows)->toBe(0)
        ->and($second->failed_rows)->toBe(3)
        ->and(Ticket::query()->count())->toBe(3);
});

it('points a duplicate only at a row that was actually imported', function (): void {
    $tracker = new ImportDuplicateTracker;
    $row = ['customer_email' => 'customer@example.com', 'subject' => 'Printer on fire', 'created_at' => '2026-10-29 17:00', 'external_reference' => null];
    $timezone = (string) config('business_hours.timezone');

    expect($tracker->check($row, 2, $timezone))->toBeNull()
        ->and($tracker->check($row, 3, $timezone))->toBeNull();

    $tracker->record($row, 3, $timezone);

    expect($tracker->check($row, 4, $timezone))->toBe('Duplicate of row 3.');
});
