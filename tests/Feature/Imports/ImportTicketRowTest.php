<?php

declare(strict_types=1);

use App\Actions\Imports\ImportTicketRow;
use App\Enums\EscalationLevel;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketImportError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Imports\ImportCsvFixture;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    Category::factory()->create(['slug' => 'hardware', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->customer()->create(['email' => 'customer@example.com']);
    $this->import = ImportCsvFixture::pendingImport($this->admin);
});

it('starts the SLA clock at the CSV created_at, read in business time', function (): void {
    $ticket = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(), 2)?->fresh();

    expect($ticket?->number)->toMatch('/^TKT-2026-\d{6}$/')
        ->and($ticket?->status)->toBe(TicketStatus::Open)
        ->and($ticket?->customer_id)->toBe($this->customer->id)
        ->and($ticket?->created_by_id)->toBe($this->admin->id)
        ->and($ticket?->created_at->toDateTimeString())->toBe('2026-10-29 11:00:00')
        ->and($ticket?->sla_started_at->toDateTimeString())->toBe('2026-10-29 11:00:00')
        ->and($ticket?->sla_warning_at->toDateTimeString())->toBe('2026-11-01 03:30:00')
        ->and($ticket?->sla_due_at->toDateTimeString())->toBe('2026-11-01 04:00:00')
        ->and($ticket?->escalation_level)->toBe(EscalationLevel::Breached);
});

it('starts the SLA clock at import time when created_at is empty', function (): void {
    $ticket = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(['created_at' => null]), 2)?->fresh();

    expect($ticket?->sla_started_at->toDateTimeString())->toBe('2026-11-02 06:00:00')
        ->and($ticket?->escalation_level)->toBe(EscalationLevel::None);
});

it('applies the side effects of the initial status', function (string $status, bool $paused, bool $resolved, bool $closed): void {
    $ticket = app(ImportTicketRow::class)
        ->handle($this->import, ImportCsvFixture::row(['status' => $status, 'created_at' => null]), 2)
        ?->fresh();

    expect($ticket?->status->value)->toBe($status)
        ->and($ticket?->sla_paused_at !== null)->toBe($paused)
        ->and($ticket?->resolved_at !== null)->toBe($resolved)
        ->and($ticket?->closed_at !== null)->toBe($closed);
})->with([
    'waiting pauses the SLA' => ['waiting_for_customer', true, false, false],
    'resolved stamps resolved_at' => ['resolved', false, true, false],
    'closed stamps both' => ['closed', false, true, true],
]);

it('fires TicketCreated flagged as imported, with the importing admin as actor', function (): void {
    Event::fake([TicketCreated::class]);

    app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(), 2);

    Event::assertDispatched(TicketCreated::class, fn (TicketCreated $event): bool => $event->imported
        && $event->actor?->is($this->admin) === true);
});

it('turns a unique-index race on external_reference into a duplicate error row and rolls the ticket back', function (): void {
    Ticket::factory()->create(['external_reference' => 'EXT-RACE']);
    $ticketsBefore = Ticket::query()->count();
    $sequenceBefore = (int) DB::table('ticket_sequences')->sum('last_number');

    $result = app(ImportTicketRow::class)->handle($this->import, ImportCsvFixture::row(['external_reference' => 'EXT-RACE']), 7);

    $error = TicketImportError::query()->where('ticket_import_id', $this->import->id)->sole();
    expect($result)->toBeNull()
        ->and(Ticket::query()->count())->toBe($ticketsBefore)
        ->and((int) DB::table('ticket_sequences')->sum('last_number'))->toBe($sequenceBefore)
        ->and($error->row_number)->toBe(7)
        ->and($error->errors)->toBe(['external_reference' => ['The external reference has already been taken.']])
        ->and($error->raw['external_reference'])->toBe('EXT-RACE');
});
