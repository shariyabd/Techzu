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
use Illuminate\Support\Facades\Log;
use Throwable;

final class FinalizeTicketImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $import = TicketImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $batch = $import->batch_id !== null ? Bus::findBatch($import->batch_id) : null;
        $status = match (true) {
            $batch?->hasFailures() === true => ImportStatus::Failed,
            $import->failed_rows > 0 => ImportStatus::CompletedWithErrors,
            default => ImportStatus::Completed,
        };

        $this->finish($import, $status);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Ticket import finalization failed.', ['import_id' => $this->importId, 'exception' => $e->getMessage()]);

        $import = TicketImport::query()->find($this->importId);

        if ($import !== null) {
            $this->finish($import, ImportStatus::Failed);
        }
    }

    private function finish(TicketImport $import, ImportStatus $status): void
    {
        $updated = TicketImport::query()
            ->whereKey($import->id)
            ->where('status', ImportStatus::Processing->value)
            ->update(['status' => $status->value, 'finished_at' => CarbonImmutable::now()]);

        if ($updated === 1) {
            TicketImportFinished::dispatch($import->refresh());
        }
    }
}
