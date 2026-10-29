<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Assignment\AssignTicketAutomatically;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutoAssignTicket implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $ticketId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(AssignTicketAutomatically $assignAutomatically): void
    {
        $ticket = Ticket::query()->find($this->ticketId);

        if ($ticket === null || $ticket->assigned_agent_id !== null || ! $ticket->isActive()) {
            return;
        }

        $assignAutomatically->handle($ticket);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Auto-assignment job failed.', ['ticket_id' => $this->ticketId, 'exception' => $e->getMessage()]);
    }
}
