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
        $this->onQueue('imports');
    }

    public function handle(TicketCsvReader $reader): void
    {
        $import = TicketImport::query()->find($this->importId);

        if ($import === null || $import->status !== ImportStatus::Pending) {
            return;
        }

        $path = Storage::disk((string) config('imports.disk'))->path($import->path);

        try {
            $reader->header($path);
        } catch (ImportHeaderInvalid $exception) {
            $this->markFailed($import, ['header' => [$exception->getMessage()]]);

            return;
        }

        ['rows' => $rows, 'last_line' => $lastLine] = $reader->scan($path);
        $import->status = ImportStatus::Processing;
        $import->total_rows = $rows;
        $import->started_at = CarbonImmutable::now();
        $import->save();

        if ($rows === 0) {
            FinalizeTicketImport::dispatch($import->id);

            return;
        }

        $importId = $import->id;
        $stampBatch = static fn (Batch $batch): int => TicketImport::query()->whereKey($importId)->whereNull('batch_id')->update(['batch_id' => $batch->id]);
        $batch = Bus::batch($this->chunks($importId, $lastLine))
            ->name('ticket-import-'.$importId)
            ->onQueue('imports')
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($importId, $stampBatch): void {
                $stampBatch($batch);
                FinalizeTicketImport::dispatch($importId);
            })
            ->dispatch();
        $stampBatch($batch);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Preparing ticket import failed.', ['import_id' => $this->importId, 'exception' => $exception]);
        $import = TicketImport::query()->find($this->importId);

        if ($import !== null && ! $import->status->isFinished()) {
            $this->markFailed($import, ['import' => ['The import could not be prepared. See the application log.']]);
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
    private function markFailed(TicketImport $import, array $errors): void
    {
        DB::transaction(function () use ($import, $errors): void {
            $import->errors()->save(new TicketImportError(['row_number' => 1, 'errors' => $errors, 'raw' => []]));
            $import->status = ImportStatus::Failed;
            $import->finished_at = CarbonImmutable::now();
            $import->save();

            TicketImportFinished::dispatch($import);
        });
    }
}
