<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('never lets an internal note reach the customer, from reply endpoint to inbox', function (): void {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();
    $colleague = User::factory()->agent()->create();
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create([
        'customer_id' => $customer->id,
        'created_by_id' => $customer->id,
    ]);

    Sanctum::actingAs($colleague, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $ticket), ['body' => 'Do not tell the customer', 'is_internal' => true])->assertCreated();

    Sanctum::actingAs($customer, ['*']);
    $this->getJson(route('api.v1.notifications.index'))->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs($agent, ['*']);
    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'InternalNoteAddedNotification');

    Sanctum::actingAs($colleague, ['*']);
    $this->postJson(route('api.v1.tickets.replies.store', $ticket), ['body' => 'Public answer'])->assertCreated();

    Sanctum::actingAs($customer, ['*']);
    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'TicketRepliedNotification')
        ->assertJsonMissing(['body' => 'Do not tell the customer']);
});
