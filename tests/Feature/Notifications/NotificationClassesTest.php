<?php

declare(strict_types=1);

use App\Enums\AssignmentMethod;
use App\Enums\ImportStatus;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketImportFinishedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create([
        'customer_id' => $this->customer->id,
        'created_by_id' => $this->customer->id,
    ]);
    $this->publicReply = TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->create();
    $this->internalReply = TicketReply::factory()->for($this->ticket)->for($this->agent, 'author')->internal()->create();
    $this->import = (new TicketImport)->forceFill([
        'id' => 42,
        'user_id' => $this->admin->id,
        'original_name' => 'legacy.csv',
        'status' => ImportStatus::CompletedWithErrors,
        'succeeded_rows' => 8,
        'failed_rows' => 2,
    ]);
});

it('stores the inbox payload and uses the right channels', function (string $kind, array $channels): void {
    $notification = match ($kind) {
        'created' => new TicketCreatedNotification($this->ticket),
        'assigned' => new TicketAssignedNotification($this->ticket, $this->agent),
        'replied' => new TicketRepliedNotification($this->ticket, $this->publicReply),
        'internal note' => new InternalNoteAddedNotification($this->ticket, $this->internalReply),
        'resolved' => new TicketResolvedNotification($this->ticket),
        'sla approaching' => new SlaApproachingNotification($this->ticket),
        'sla breached' => new SlaBreachedNotification($this->ticket),
    };
    $payload = $notification->toArray($this->customer);
    expect(array_keys($payload))->toBe(['ticket_number', 'title', 'body', 'url'])
        ->and($payload['ticket_number'])->toBe($this->ticket->number)
        ->and($payload['url'])->toBe(route('tickets.show', $this->ticket))
        ->and($payload['title'])->not->toBe('')
        ->and($notification->via($this->customer))->toBe($channels);
    if ($channels === ['mail', 'database']) {
        expect($notification->toMail($this->customer)->actionUrl)->toBe(route('tickets.show', $this->ticket));
    }
})->with([
    'created' => ['created', ['mail', 'database']],
    'assigned' => ['assigned', ['mail', 'database']],
    'replied' => ['replied', ['mail', 'database']],
    'internal note' => ['internal note', ['database']],
    'resolved' => ['resolved', ['mail', 'database']],
    'sla approaching' => ['sla approaching', ['mail', 'database']],
    'sla breached' => ['sla breached', ['mail', 'database']],
]);

it('keeps the import summary in the inbox only, linking to the import', function (): void {
    $notification = new TicketImportFinishedNotification($this->import);
    $payload = $notification->toArray($this->admin);
    expect($notification->via($this->admin))->toBe(['database'])
        ->and($payload['ticket_number'])->toBeNull()
        ->and($payload['url'])->toBe(route('admin.imports.show', 42))
        ->and($payload['body'])->toBe('8 tickets imported, 2 rows failed.');
});

it('refuses to build a public reply notification from an internal note', function (): void {
    expect(fn () => new TicketRepliedNotification($this->ticket, $this->internalReply))->toThrow(LogicException::class);
});

it('pushes notification jobs onto the notifications queue', function (): void {
    Queue::fake();
    event(new TicketAssigned($this->ticket, $this->admin, $this->agent, null, AssignmentMethod::Manual));
    Queue::assertPushed(SendQueuedNotifications::class, 4);
    Queue::assertNotPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue !== 'notifications');
});
