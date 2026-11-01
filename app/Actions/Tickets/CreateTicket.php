<?php

declare(strict_types=1);

namespace App\Actions\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Attachments\AttachmentStorer;
use App\Services\Sla\SlaCalculator;
use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateTicket
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
        private readonly AttachmentStorer $attachments,
    ) {}

    /**
     * @param  array{subject: string, description: string, priority: string, category_id: int|string, customer_id?: int|string|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function handle(User $actor, array $data, array $files = []): Ticket
    {
        $now = CarbonImmutable::now();
        $staged = $this->attachments->stage($files);

        try {
            $ticket = DB::transaction(function () use ($actor, $data, $staged, $now): Ticket {
                $ticket = new Ticket([
                    'subject' => $data['subject'],
                    'description' => $data['description'],
                    'priority' => TicketPriority::from($data['priority']),
                    'category_id' => (int) $data['category_id'],
                    'customer_id' => $this->customerId($actor, $data),
                    'created_by_id' => $actor->id,
                ]);
                $ticket->number = $this->numbers->next($now);
                $ticket->status = TicketStatus::Open;
                $this->sla->applyInitial($ticket, $now);
                $ticket->save();
                $ticket->refresh();

                $this->attachments->persist($ticket, null, $actor, $staged, false);

                return $ticket;
            });
        } catch (Throwable $e) {
            $this->attachments->discard($staged);

            throw $e;
        }

        TicketCreated::dispatch($ticket, $actor);

        return $ticket;
    }

    /** @param  array<string, mixed>  $data */
    private function customerId(User $actor, array $data): int
    {
        $requested = $data['customer_id'] ?? null;

        if ($requested !== null && $actor->can('createOnBehalf', Ticket::class)) {
            return (int) $requested;
        }

        return $actor->id;
    }
}
