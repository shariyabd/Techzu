<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Events\TicketImportFinished;
use App\Jobs\PrepareTicketImport;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    Storage::fake((string) config('imports.disk'));
    Storage::disk((string) config('imports.disk'))->put('imports/retry.csv', "subject,description,priority,category,customer_email\nA,B,low,general,c@example.com\nC,D,low,general,c@example.com\n");
    $this->import = ImportCsvFixture::pendingImport(User::factory()->admin()->create(), 'imports/retry.csv');
});

function failBatchDispatch(): void
{
    test()->mock(BatchRepository::class)->shouldReceive('store')->andThrow(new RuntimeException('Queue unavailable'));
}

it('resumes on retry when the batch could not be dispatched', function (): void {
    failBatchDispatch();

    expect(fn () => app()->call([new PrepareTicketImport($this->import->id), 'handle']))->toThrow(RuntimeException::class);
    expect($this->import->refresh()->status)->toBe(ImportStatus::Processing)
        ->and($this->import->batch_id)->toBeNull();

    Bus::fake();
    app()->call([new PrepareTicketImport($this->import->id), 'handle']);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);
    expect($this->import->refresh()->status)->toBe(ImportStatus::Processing)
        ->and($this->import->total_rows)->toBe(2)
        ->and($this->import->batch_id)->not->toBeNull();
});

it('marks the import failed when the batch dispatch fails on every attempt', function (): void {
    Event::fake([TicketImportFinished::class]);
    failBatchDispatch();
    $job = new PrepareTicketImport($this->import->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('Queue unavailable'));

    $import = TicketImport::query()->withCount('errors')->findOrFail($this->import->id);
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->errors_count)->toBe(1);
    Event::assertDispatched(TicketImportFinished::class);
});
