<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;

it('hides internal replies from customers and shows everything to staff', function (string $staffState): void {
    $ticket = Ticket::factory()->create();
    $public = TicketReply::factory()->create(['ticket_id' => $ticket->id]);
    $internal = TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id]);
    $customer = User::query()->findOrFail($ticket->customer_id);
    $staff = User::factory()->{$staffState}()->create();

    expect($ticket->replies()->visibleTo($customer)->pluck('id')->all())->toBe([$public->id])
        ->and($ticket->replies()->visibleTo($staff)->orderBy('id')->pluck('id')->all())->toBe([$public->id, $internal->id]);
})->with(['agent', 'admin']);

it('never mass-assigns the reply ticket or author', function (): void {
    expect((new TicketReply)->getFillable())->toBe(['body', 'is_internal']);
});
