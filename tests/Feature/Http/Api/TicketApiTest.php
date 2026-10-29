<?php

declare(strict_types=1);

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketReplied;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

const TICKET_JSON = [
    'number', 'subject', 'description',
    'status' => ['value', 'label'],
    'priority' => ['value', 'label'],
    'category', 'customer', 'assigned_agent',
    'sla' => ['state' => ['value', 'label'], 'due_at', 'warning_at', 'paused_at'],
    'escalation_level', 'created_at', 'updated_at', 'resolved_at', 'closed_at',
    'can' => ['update', 'changeStatus', 'changePriority', 'claim', 'assign', 'reply', 'addInternalNote', 'allowedStatuses'],
];

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));
    $this->category = Category::factory()->create(['is_active' => true]);
    $this->customer = User::factory()->customer()->create();
    $this->agent = User::factory()->agent([$this->category->id])->create();
    $this->admin = User::factory()->admin()->create();
});

it('lists visible tickets with pagination meta', function (): void {
    Ticket::factory()->status(TicketStatus::Open)->count(2)->create(['customer_id' => $this->customer->id]);
    Ticket::factory()->status(TicketStatus::Open)->create();
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/v1/tickets?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonStructure([
            'data' => [['number', 'subject', 'status' => ['value', 'label'], 'priority' => ['value', 'label'], 'category', 'customer', 'assigned_agent', 'sla' => ['state', 'due_at'], 'escalation_level', 'replies_count', 'created_at', 'updated_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('creates a ticket and returns 201 with the resource', function (): void {
    Sanctum::actingAs($this->customer);

    $response = $this->postJson('/api/v1/tickets', [
        'subject' => 'API ticket',
        'description' => 'Created over the API.',
        'priority' => TicketPriority::Critical->value,
        'category_id' => $this->category->id,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => TICKET_JSON])
        ->assertJsonPath('data.status.value', 'open')
        ->assertJsonPath('data.priority.value', 'critical')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.assigned_agent', null);

    expect($response->json('data.number'))->toMatch('/^TKT-2026-\d{6}$/');
});

it('returns 422 json for invalid payloads', function (): void {
    Sanctum::actingAs($this->customer);

    $this->postJson('/api/v1/tickets', ['subject' => ''])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['subject']]);
});

it('shows a ticket', function (): void {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/v1/tickets/'.$ticket->number)
        ->assertOk()
        ->assertJsonStructure(['data' => TICKET_JSON])
        ->assertJsonPath('data.number', $ticket->number);
});

it('updates a ticket', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->create(['customer_id' => $this->customer->id]);
    Sanctum::actingAs($this->customer);

    $this->patchJson('/api/v1/tickets/'.$ticket->number, ['subject' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.subject', 'Renamed');
});

it('changes the status and reports the domain error code on a forbidden transition', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($this->agent)->create();
    Sanctum::actingAs($this->agent);

    $this->patchJson('/api/v1/tickets/'.$ticket->number.'/status', ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'resolved');

    $this->patchJson('/api/v1/tickets/'.$ticket->number.'/status', ['status' => 'waiting_for_customer'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_status_transition');
});

it('changes the priority', function (): void {
    $ticket = Ticket::factory()->status(TicketStatus::Open)->priority(TicketPriority::Low)->create();
    Sanctum::actingAs($this->admin);

    $this->patchJson('/api/v1/tickets/'.$ticket->number.'/priority', ['priority' => 'high'])
        ->assertOk()
        ->assertJsonPath('data.priority.value', 'high');
});

it('lists activities without internal notes for customers', function (): void {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);
    $public = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id, 'is_internal' => false]);
    $internal = TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->agent->id]);
    TicketReplied::dispatch($ticket, $public, $this->agent);
    TicketReplied::dispatch($ticket, $internal, $this->agent);

    Sanctum::actingAs($this->customer);
    $this->getJson('/api/v1/tickets/'.$ticket->number.'/activities')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', ActivityType::Replied->value)
        ->assertJsonStructure(['data' => [['id', 'type', 'description', 'properties', 'causer' => ['id', 'name'], 'created_at']], 'meta' => ['total']]);

    Sanctum::actingAs($this->agent);
    $this->getJson('/api/v1/tickets/'.$ticket->number.'/activities')->assertJsonCount(2, 'data');
});
