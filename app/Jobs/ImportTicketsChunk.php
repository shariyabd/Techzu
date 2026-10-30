<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Imports\ImportTicketRow;
use App\Models\Category;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Services\Imports\ImportDuplicateTracker;
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
use Throwable;

final class ImportTicketsChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    private const LOWERCASE = ['priority', 'status', 'category', 'customer_email'];

    public int $tries = 1;

    public function __construct(
        public int $importId,
        public int $startLine,
        public int $endLine,
        public int $startOffset,
    ) {
        $this->onQueue('imports');
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

        $duplicates = new ImportDuplicateTracker;

        foreach ($reader->records($path, $this->startLine, $this->endLine, $this->startOffset) as $line => $values) {
            $this->importRecord($import, $importRow, $rules, $duplicates, $header, $values, $line + 1);
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

        $error = new TicketImportError([
            'row_number' => $this->startLine + 1,
            'errors' => ['chunk' => [sprintf('Rows %d to %d stopped unexpectedly and may be partly imported. See the application log.', $this->startLine + 1, $this->endLine + 1)]],
            'raw' => [],
        ]);
        $error->ticket_import_id = $this->importId;
        $error->save();
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $values
     */
    private function importRecord(TicketImport $import, ImportTicketRow $importRow, ImportTicketRowRules $rules, ImportDuplicateTracker $duplicates, array $header, array $values, int $rowNumber): void
    {
        if (count($values) !== count($header)) {
            $keyed = [];

            foreach ($values as $position => $value) {
                $keyed[$header[$position] ?? 'extra_'.$position] = $value;
            }

            $this->recordFailure($import, $rowNumber, ['row' => [sprintf('The row has %d columns but the header has %d.', count($values), count($header))]], $keyed);

            return;
        }

        $row = $this->normalise(array_combine($header, $values));
        $validator = Validator::make($row, $rules->rules());

        if ($validator->fails()) {
            $this->recordFailure($import, $rowNumber, $validator->errors()->toArray(), $row);

            return;
        }

        /** @var array<string, string|null> $validated */
        $validated = $validator->validated();
        $duplicate = $duplicates->check($validated, $rowNumber, $rules->timezone());

        if ($duplicate !== null) {
            $this->recordFailure($import, $rowNumber, ['row' => [$duplicate]], $row);

            return;
        }

        try {
            $ticket = $importRow->handle($import, $validated, $rowNumber);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($import, $rowNumber, ['row' => ['The row could not be imported because of an unexpected error.']], $row);

            return;
        }

        if ($ticket !== null) {
            $duplicates->record($validated, $rowNumber, $rules->timezone());
        }

        $this->increment($import, $ticket === null ? 'failed_rows' : 'succeeded_rows');
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private function normalise(array $row): array
    {
        foreach ($row as $column => $value) {
            $value = $value === null || trim($value) === '' ? null : trim($value);
            $row[$column] = $value !== null && in_array($column, self::LOWERCASE, true) ? strtolower($value) : $value;
        }

        return $row;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<int|string, string|null>  $raw
     */
    private function recordFailure(TicketImport $import, int $rowNumber, array $errors, array $raw): void
    {
        DB::transaction(function () use ($import, $rowNumber, $errors, $raw): void {
            $import->errors()->save(new TicketImportError(['row_number' => $rowNumber, 'errors' => $errors, 'raw' => $raw]));
            $this->increment($import, 'failed_rows');
        });
    }

    private function increment(TicketImport $import, string $outcomeColumn): void
    {
        TicketImport::query()->whereKey($import->id)->toBase()->incrementEach(['processed_rows' => 1, $outcomeColumn => 1]);
    }
}
