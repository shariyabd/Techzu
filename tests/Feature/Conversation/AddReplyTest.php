<?php

declare(strict_types=1);

use App\Actions\Conversation\AddReply;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Exceptions\Domain\TicketClosed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(config('attachments.disk'));
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
});

it('stores a public staff reply with its attachments and fires TicketReplied', function (): void {
    Event::fake([TicketReplied::class]);

    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'See attached.', false, [
        UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        UploadedFile::fake()->image('screenshot.png'),
    ]);

    expect($reply->is_internal)->toBeFalse()
        ->and($reply->author_id)->toBe($this->agent->id)
        ->and(Attachment::query()->where('reply_id', $reply->id)->where('is_internal', false)->count())->toBe(2);

    Event::assertDispatched(TicketReplied::class, fn (TicketReplied $event): bool => $event->reply->is($reply)
        && $event->ticket->is($this->ticket)
        && $event->author->is($this->agent));
});

it('stores an internal note whose attachments are internal too', function (): void {
    $reply = app(AddReply::class)->handle($this->agent, $this->ticket, 'Legacy plan.', true, [
        UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
    ]);

    expect($reply->is_internal)->toBeTrue()
        ->and(Attachment::query()->where('reply_id', $reply->id)->sole()->is_internal)->toBeTrue();
});

it('forces is_internal to false when a customer asks for an internal note', function (): void {
    $reply = app(AddReply::class)->handle($this->customer, $this->ticket, 'Let me sneak a note in.', true, [
        UploadedFile::fake()->create('mine.pdf', 2, 'application/pdf'),
    ]);

    expect($reply->is_internal)->toBeFalse()
        ->and(TicketReply::query()->where('is_internal', true)->exists())->toBeFalse()
        ->and(Attachment::query()->where('is_internal', true)->exists())->toBeFalse();
});

it('rejects replies on a closed ticket', function (string $who): void {
    $closed = Ticket::factory()->status(TicketStatus::Closed)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);

    expect(fn () => app(AddReply::class)->handle($this->{$who}, $closed, 'Hello?', false))
        ->toThrow(TicketClosed::class);
    expect(TicketReply::query()->where('ticket_id', $closed->id)->exists())->toBeFalse();
})->with(['customer', 'agent']);

it('deletes the stored files when the transaction fails after storing them', function (): void {
    $this->travelTo(now()->addMinute());
    Ticket::updating(function (): void {
        throw new RuntimeException('simulated failure');
    });

    expect(fn () => app(AddReply::class)->handle($this->agent, $this->ticket, 'Will roll back.', false, [
        UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'simulated failure');

    expect(Storage::disk(config('attachments.disk'))->allFiles())->toBeEmpty()
        ->and(TicketReply::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('moves a waiting ticket to in progress on a customer reply and resumes the SLA', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
        'sla_started_at' => CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'),
        'sla_warning_at' => CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'),
        'sla_due_at' => CarbonImmutable::parse('2026-11-01 11:00:00', 'UTC'),
        'sla_paused_at' => null,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 04:00:00', 'UTC'));
    app(ChangeTicketStatus::class)->handle($this->agent, $ticket, TicketStatus::WaitingForCustomer);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    app(AddReply::class)->handle($this->customer, $ticket->fresh(), 'Here is the info.', false);

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->sla_due_at->utc()->toDateTimeString())->toBe('2026-11-02 11:00:00');
});

it('keeps a waiting ticket waiting when staff reply', function (): void {
    $ticket = app(ChangeTicketStatus::class)->handle($this->agent, $this->ticket, TicketStatus::WaitingForCustomer);

    app(AddReply::class)->handle($this->agent, $ticket->fresh(), 'Any update?', false);

    expect($ticket->fresh()?->status)->toBe(TicketStatus::WaitingForCustomer);
});
