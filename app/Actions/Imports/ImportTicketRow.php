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
use App\Services\Imports\ImportDuplicateTracker;
use App\Services\Imports\ImportTicketRowRules;
use App\Services\Sla\SlaCalculator;
use App\Services\Sla\SlaSettingsRepository;
use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ImportTicketRow
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
        private readonly SlaSettingsRepository $settings,
    ) {}

    /** @param array<string, string|null> $row */
    public function handle(TicketImport $import, array $row, int $rowNumber): ?Ticket
    {
        $now = CarbonImmutable::now();
        $timezone = $this->settings->timezone();
        $createdAt = ImportTicketRowRules::parseCreatedAt($row['created_at'] ?? null, $timezone) ?? $now;
        $fingerprint = ImportDuplicateTracker::fingerprint($row, $timezone);
        $status = TicketStatus::tryFrom((string) ($row['status'] ?? '')) ?? TicketStatus::Open;
        $actor = $import->loadMissing('user')->user;

        try {
            return DB::transaction(function () use ($import, $row, $createdAt, $status, $now, $actor, $fingerprint): Ticket {
                $ticket = new Ticket([
                    'subject' => (string) $row['subject'],
                    'description' => (string) $row['description'],
                    'priority' => TicketPriority::from((string) $row['priority']),
                    'category_id' => Category::query()->where('slug', (string) $row['category'])->firstOrFail(['id'])->id,
                    'customer_id' => User::query()->where('email', (string) $row['customer_email'])->firstOrFail(['id'])->id,
                    'created_by_id' => $import->user_id,
                    'external_reference' => $row['external_reference'] ?? null,
                ]);
                $ticket->ticket_import_id = $import->id;
                $ticket->import_fingerprint = $fingerprint;
                $ticket->number = $this->numbers->next($createdAt);
                $ticket->status = $status;
                $ticket->created_at = $createdAt;
                $this->sla->applyInitial($ticket, $createdAt);
                $this->applyInitialStatus($ticket, $status, $now);
                $this->sla->applyCurrentLevelAsNotified($ticket, $now);
                $ticket->save();

                TicketCreated::dispatch($ticket, $actor, true);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $import->errors()->save(new TicketImportError([
                'row_number' => $rowNumber,
                'errors' => $this->duplicateErrors($exception),
                'raw' => $row,
            ]));

            return null;
        }
    }

    /** @return array<string, list<string>> */
    private function duplicateErrors(UniqueConstraintViolationException $exception): array
    {
        return match (true) {
            str_contains($exception->getMessage(), 'tickets_external_reference_unique') => ['external_reference' => ['The external reference has already been taken.']],
            str_contains($exception->getMessage(), 'tickets_import_fingerprint_unique') => ['row' => ['Duplicate of an existing ticket.']],
            default => throw $exception,
        };
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
