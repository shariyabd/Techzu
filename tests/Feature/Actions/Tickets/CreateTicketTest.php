<?php

declare(strict_types=1);

use App\Actions\Tickets\CreateTicket;
use App\Enums\EscalationLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('attachments.disk', 'local');
    $this->now = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->category = Category::factory()->create();
});

function ticketData(Category $category, array $overrides = []): array
{
    return array_merge([
        'subject' => 'Printer on fire',
        'description' => 'It is literally on fire.',
        'priority' => TicketPriority::High->value,
        'category_id' => $category->id,
    ], $overrides);
}

it('creates an open ticket with a number and the initial SLA', function (): void {
    $customer = User::factory()->customer()->create();

    $ticket = app(CreateTicket::class)->handle($customer, ticketData($this->category));

    $deadlines = app(SlaCalculator::class)->deadlinesFor(TicketPriority::High, $this->now);

    expect($ticket->exists)->toBeTrue()
        ->and($ticket->number)->toBe('TKT-2026-000001')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->customer_id)->toBe($customer->id)
        ->and($ticket->created_by_id)->toBe($customer->id)
        ->and($ticket->assigned_agent_id)->toBeNull()
        ->and($ticket->escalation_level)->toBe(EscalationLevel::None)
        ->and($ticket->sla_started_at?->equalTo($this->now))->toBeTrue()
        ->and($ticket->sla_due_at?->equalTo($deadlines->dueAt))->toBeTrue()
        ->and($ticket->sla_warning_at?->equalTo($deadlines->warningAt))->toBeTrue()
        ->and($ticket->sla_paused_at)->toBeNull();
});

it('lets staff create on behalf of a customer', function (): void {
    $agent = User::factory()->agent()->create();
    $customer = User::factory()->customer()->create();

    $ticket = app(CreateTicket::class)->handle($agent, ticketData($this->category, ['customer_id' => $customer->id]));

    expect($ticket->customer_id)->toBe($customer->id)
        ->and($ticket->created_by_id)->toBe($agent->id);
});

it('ignores customer_id from a customer', function (): void {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();

    $ticket = app(CreateTicket::class)->handle($customer, ticketData($this->category, ['customer_id' => $other->id]));

    expect($ticket->customer_id)->toBe($customer->id);
});

it('stores attachments as non-internal ticket-level files', function (): void {
    $customer = User::factory()->customer()->create();

    $ticket = app(CreateTicket::class)->handle($customer, ticketData($this->category), [
        UploadedFile::fake()->create('log.txt', 3, 'text/plain'),
        UploadedFile::fake()->image('shot.png'),
    ]);

    $attachments = Attachment::query()->where('ticket_id', $ticket->id)->get();
    expect($attachments)->toHaveCount(2)
        ->and($attachments->every(fn (Attachment $a): bool => $a->reply_id === null && ! $a->is_internal))->toBeTrue();

    foreach ($attachments as $attachment) {
        Storage::disk('local')->assertExists($attachment->path);
    }
});

it('fires TicketCreated once with the actor', function (): void {
    Event::fake([TicketCreated::class]);
    $customer = User::factory()->customer()->create();

    $ticket = app(CreateTicket::class)->handle($customer, ticketData($this->category));

    Event::assertDispatchedTimes(TicketCreated::class, 1);
    Event::assertDispatched(TicketCreated::class, fn (TicketCreated $e): bool => $e->ticket->is($ticket)
        && $e->actor?->is($customer) === true
        && $e->imported === false);
});

it('rolls back the ticket, the number and the files when an attachment insert fails', function (): void {
    $customer = User::factory()->customer()->create();
    $inserted = 0;
    Attachment::created(function () use (&$inserted): void {
        if (++$inserted === 2) {
            throw new RuntimeException('disk quota row failure');
        }
    });

    expect(fn () => app(CreateTicket::class)->handle($customer, ticketData($this->category), [
        UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 1, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'disk quota row failure');

    expect(Ticket::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Attachment::flushEventListeners();
    $next = app(CreateTicket::class)->handle($customer, ticketData($this->category));
    expect($next->number)->toBe('TKT-2026-000001');
});

it('keeps the committed ticket, its attachments and files when a TicketCreated listener throws', function (): void {
    $customer = User::factory()->customer()->create();
    Event::listen(TicketCreated::class, function (): void {
        throw new RuntimeException('listener failure');
    });

    expect(fn () => app(CreateTicket::class)->handle($customer, ticketData($this->category), [
        UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'listener failure');

    $attachment = Attachment::query()->firstOrFail();
    expect(Ticket::query()->count())->toBe(1)
        ->and(Attachment::query()->count())->toBe(1);
    Storage::disk('local')->assertExists($attachment->path);
});
