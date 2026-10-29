<?php

declare(strict_types=1);

use App\Enums\AssignmentMethod;
use App\Enums\ImportStatus;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketImportFinished;
use App\Events\TicketReplied;
use App\Events\TicketResolved;
use App\Events\TicketSlaApproaching;
use App\Events\TicketSlaBreached;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();
    config(['tickets.auto_assign' => false]);
    $this->u = [
        'customer' => User::factory()->customer()->create(),
        'agent' => User::factory()->agent()->create(),
        'other_agent' => User::factory()->agent()->create(),
        'admin1' => User::factory()->admin()->create(),
        'admin2' => User::factory()->admin()->create(),
    ];
    $this->ticket = fn (?string $assignee = 'agent'): Ticket => Ticket::factory()->status(TicketStatus::InProgress)->create([
        'customer_id' => $this->u['customer']->id,
        'created_by_id' => $this->u['customer']->id,
        'assigned_agent_id' => $assignee === null ? null : $this->u[$assignee]->id,
    ]);
    $this->reply = fn (Ticket $ticket, string $author, bool $internal): TicketReply => TicketReply::factory()
        ->for($ticket)
        ->for($this->u[$author], 'author')
        ->create(['is_internal' => $internal]);
    $this->expectRecipients = function (string $notification, array $expected): void {
        foreach ($this->u as $key => $user) {
            in_array($key, $expected, true)
                ? Notification::assertSentToTimes($user, $notification, 1)
                : Notification::assertNotSentTo($user, $notification);
        }
    };
});

it('notifies on TicketCreated', function (?string $actor, bool $autoAssign, bool $imported, array $expected): void {
    config(['tickets.auto_assign' => $autoAssign]);
    event(new TicketCreated(($this->ticket)(null), $actor === null ? null : $this->u[$actor], $imported));
    ($this->expectRecipients)(TicketCreatedNotification::class, $expected);
})->with([
    'customer self-service, manual assignment' => ['customer', false, false, ['customer', 'admin1', 'admin2']],
    'agent on behalf of the customer' => ['agent', false, false, ['customer', 'admin1', 'admin2']],
    'admin on behalf: that admin is skipped' => ['admin1', false, false, ['customer', 'admin2']],
    'auto-assign on: admins are not told' => ['customer', true, false, ['customer']],
    'imported: nobody' => ['admin1', false, true, []],
]);

it('notifies on TicketAssigned', function (?string $actor, string $agent, AssignmentMethod $method, array $expected): void {
    event(new TicketAssigned(($this->ticket)($agent), $actor === null ? null : $this->u[$actor], $this->u[$agent], null, $method));
    ($this->expectRecipients)(TicketAssignedNotification::class, $expected);
})->with([
    'admin assigns' => ['admin1', 'agent', AssignmentMethod::Manual, ['agent', 'customer']],
    'agent claims: no notice to themselves' => ['agent', 'agent', AssignmentMethod::Claimed, ['customer']],
    'automatic assignment' => [null, 'agent', AssignmentMethod::Automatic, ['agent', 'customer']],
    'hand-off to a colleague' => ['agent', 'other_agent', AssignmentMethod::Manual, ['other_agent', 'customer']],
]);

it('notifies on TicketReplied', function (string $author, ?string $assignee, bool $internal, string $notification, array $expected): void {
    $ticket = ($this->ticket)($assignee);
    event(new TicketReplied($ticket, ($this->reply)($ticket, $author, $internal), $this->u[$author]));
    ($this->expectRecipients)($notification, $expected);
})->with([
    'agent public reply to customer' => ['agent', 'agent', false, TicketRepliedNotification::class, ['customer']],
    'admin public reply to customer' => ['admin1', 'agent', false, TicketRepliedNotification::class, ['customer']],
    'customer reply to assigned agent' => ['customer', 'agent', false, TicketRepliedNotification::class, ['agent']],
    'customer reply, unassigned, to admins' => ['customer', null, false, TicketRepliedNotification::class, ['admin1', 'admin2']],
    'admin replies on own ticket, customer only' => ['admin1', 'admin1', false, TicketRepliedNotification::class, ['customer']],
    'internal note by a colleague to assignee' => ['other_agent', 'agent', true, InternalNoteAddedNotification::class, ['agent']],
    'internal note by the assignee, nobody' => ['agent', 'agent', true, InternalNoteAddedNotification::class, []],
    'internal note, unassigned, nobody' => ['admin1', null, true, InternalNoteAddedNotification::class, []],
]);

it('sends the customer nothing at all for an internal note', function (?string $assignee, string $author): void {
    $ticket = ($this->ticket)($assignee);
    event(new TicketReplied($ticket, ($this->reply)($ticket, $author, true), $this->u[$author]));
    Notification::assertNothingSentTo($this->u['customer']);
})->with([
    'colleague writes, agent assigned' => ['agent', 'other_agent'],
    'admin writes, unassigned' => [null, 'admin1'],
    'assignee writes' => ['agent', 'agent'],
]);

it('notifies on TicketResolved', function (?string $actor, array $expected): void {
    event(new TicketResolved(($this->ticket)('agent'), $actor === null ? null : $this->u[$actor]));
    ($this->expectRecipients)(TicketResolvedNotification::class, $expected);
})->with([
    'agent resolves' => ['agent', ['customer']],
    'system resolves' => [null, ['customer']],
    'customer acted: no notice to themselves' => ['customer', []],
]);

it('notifies on SLA events', function (string $event, ?string $assignee, string $notification, array $expected): void {
    event(new $event(($this->ticket)($assignee)));
    ($this->expectRecipients)($notification, $expected);
})->with([
    'approaching, assigned, to agent' => [TicketSlaApproaching::class, 'agent', SlaApproachingNotification::class, ['agent']],
    'approaching, unassigned, to admins' => [TicketSlaApproaching::class, null, SlaApproachingNotification::class, ['admin1', 'admin2']],
    'breached, assigned, to agent and admins' => [TicketSlaBreached::class, 'agent', SlaBreachedNotification::class, ['agent', 'admin1', 'admin2']],
    'breached, unassigned, to admins' => [TicketSlaBreached::class, null, SlaBreachedNotification::class, ['admin1', 'admin2']],
    'breached, assigned to an admin, that admin once' => [TicketSlaBreached::class, 'admin1', SlaBreachedNotification::class, ['admin1', 'admin2']],
]);

it('notifies only the importing admin when an import finishes', function (): void {
    $import = (new TicketImport)->forceFill([
        'id' => 7,
        'user_id' => $this->u['admin1']->id,
        'original_name' => 'tickets.csv',
        'status' => ImportStatus::Completed,
        'succeeded_rows' => 3,
        'failed_rows' => 0,
    ]);
    event(new TicketImportFinished($import));
    ($this->expectRecipients)(TicketImportFinishedNotification::class, ['admin1']);
});
