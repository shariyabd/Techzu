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

it('prepares a 10,000-row file in constant memory and batches it by record range', function (): void {
    Storage::fake((string) config('imports.disk'));
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $disk = Storage::disk((string) config('imports.disk'));
    $disk->makeDirectory('imports');
    $description = str_repeat('The printer on floor three is jammed again. ', 13);
    $handle = fopen($disk->path('imports/big.csv'), 'wb');
    fputcsv($handle, TicketCsvReader::COLUMNS, ',', '"', '');
    for ($i = 1; $i <= 10_000; $i++) {
        fputcsv($handle, ["EXT-{$i}", "Subject {$i}", $description, 'low', 'general', 'customer@example.com', '', ''], ',', '"', '');
    }
    fclose($handle);
    expect(filesize($disk->path('imports/big.csv')))->toBeGreaterThan(5_000_000);
    $disk->put('imports/warmup.csv', "subject,description,priority,category,customer_email\nA,B,low,general,c@example.com\n");
    app()->call([new PrepareTicketImport(ImportCsvFixture::pendingImport($admin, 'imports/warmup.csv')->id), 'handle']);
    $import = ImportCsvFixture::pendingImport($admin, 'imports/big.csv');
    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage();

    app()->call([new PrepareTicketImport($import->id), 'handle']);

    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(2 * 1024 * 1024)
        ->and($import->refresh()->status)->toBe(ImportStatus::Processing)
        ->and($import->total_rows)->toBe(10_000);
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
