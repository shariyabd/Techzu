<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Assignment\AssignTicketAutomatically;
use App\Enums\TicketStatus;
use App\Events\ImportedTicketsAutoAssigned;
use App\Models\Ticket;
use App\Models\TicketImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutoAssignImportedTickets implements ShouldQueue
{
    use Queueable;

    public const int TICKETS_PER_RUN = 100;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public int $importId,
        public int $afterTicketId = 0,
    ) {
        $this->onQueue('imports');
    }

    public function handle(AssignTicketAutomatically $assignAutomatically): void
    {
        $import = TicketImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $tickets = Ticket::query()
            ->where('ticket_import_id', $import->id)
            ->where('id', '>', $this->afterTicketId)
            ->whereNull('assigned_agent_id')
            ->whereIn('status', array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::active()))
            ->orderBy('id')
            ->limit(self::TICKETS_PER_RUN)
            ->get();

        foreach ($tickets as $ticket) {
            $assignAutomatically->handle($ticket);
        }

        $last = $tickets->last();

        if ($last !== null && $tickets->count() === self::TICKETS_PER_RUN) {
            self::dispatch($import->id, $last->id);

            return;
        }

        ImportedTicketsAutoAssigned::dispatch($import);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Auto-assigning imported tickets failed.', ['import_id' => $this->importId, 'exception' => $exception->getMessage()]);

        $import = TicketImport::query()->find($this->importId);

        if ($import !== null) {
            ImportedTicketsAutoAssigned::dispatch($import);
        }
    }
}
