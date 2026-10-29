<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Models\User;
use App\Services\Imports\ImportTicketRowRules;
use App\Services\Sla\SlaCalculator;
use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ImportTicketRow
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
    ) {}

    /** @param array<string, string|null> $row */
    public function handle(TicketImport $import, array $row, int $rowNumber): ?Ticket
    {
        $now = CarbonImmutable::now();
        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, (string) config('business_hours.timezone')) ?? $now;
        $status = TicketStatus::tryFrom((string) ($row['status'] ?? '')) ?? TicketStatus::Open;
        $actor = $import->loadMissing('user')->user;

        try {
            return DB::transaction(function () use ($import, $row, $createdAt, $status, $now, $actor): Ticket {
                $ticket = new Ticket([
                    'subject' => (string) $row['subject'],
                    'description' => (string) $row['description'],
                    'priority' => TicketPriority::from((string) $row['priority']),
                    'category_id' => Category::query()->where('slug', (string) $row['category'])->firstOrFail(['id'])->id,
                    'customer_id' => User::query()->where('email', (string) $row['customer_email'])->firstOrFail(['id'])->id,
                    'created_by_id' => $import->user_id,
                    'external_reference' => $row['external_reference'] ?? null,
                ]);
                $ticket->number = $this->numbers->next($createdAt);
                $ticket->status = $status;
                $ticket->created_at = $createdAt;
                $this->sla->applyInitial($ticket, $createdAt);
                $this->applyInitialStatus($ticket, $status, $now);
                $this->sla->applyCurrentLevel($ticket, $now);
                $ticket->save();

                TicketCreated::dispatch($ticket, $actor, true);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'external_reference')) {
                throw $exception;
            }

            $error = new TicketImportError([
                'row_number' => $rowNumber,
                'errors' => ['external_reference' => ['The external reference has already been taken.']],
                'raw' => $row,
            ]);
            $import->errors()->save($error);

            return null;
        }
    }

    private function applyInitialStatus(Ticket $ticket, TicketStatus $status, CarbonImmutable $now): void
    {
        match ($status) {
            TicketStatus::WaitingForCustomer => $this->sla->pause($ticket, $now),
            TicketStatus::Resolved => $ticket->resolved_at = $now,
            TicketStatus::Closed => $ticket->resolved_at = $ticket->closed_at = $now,
            TicketStatus::Open, TicketStatus::InProgress => null,
        };
    }
}
