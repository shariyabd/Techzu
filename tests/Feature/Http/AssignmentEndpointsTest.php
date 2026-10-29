<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));
    $this->category = Category::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent([$this->category->id])->create();
    $this->colleague = User::factory()->agent([$this->category->id])->create();
    $this->customer = User::factory()->customer()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::Open)->create([
        'category_id' => $this->category->id,
        'customer_id' => $this->customer->id,
        'assigned_agent_id' => null,
    ]);
    $this->show = '/tickets/'.$this->ticket->number;
});

it('claims through the web and flashes success', function (): void {
    $this->actingAs($this->agent)
        ->from($this->show)
        ->post($this->show.'/claim')
        ->assertRedirect($this->show)
        ->assertSessionHas('success');

    expect($this->ticket->fresh()?->assigned_agent_id)->toBe($this->agent->id);
});

it('claims through the API and returns the ticket resource', function (): void {
    Sanctum::actingAs($this->agent);

    $this->postJson('/api/v1'.$this->show.'/claim')
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.status.value', 'in_progress');
});

it('returns 409 ticket_already_claimed when the API claim loses', function (): void {
    $this->ticket->forceFill(['assigned_agent_id' => $this->colleague->id])->save();
    Sanctum::actingAs($this->agent);

    $this->postJson('/api/v1'.$this->show.'/claim')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ticket_already_claimed');
});

it('lets an admin assign through the API without changing the status', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1'.$this->show.'/assign', ['agent_id' => $this->agent->id])
        ->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.status.value', 'open');
});

it('returns 422 agent_not_eligible for a customer target', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1'.$this->show.'/assign', ['agent_id' => $this->customer->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'agent_not_eligible');
});
