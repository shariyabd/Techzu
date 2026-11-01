<?php

declare(strict_types=1);

use App\Enums\EscalationLevel;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    $this->customer = User::factory()->customer()->create();
    $this->ticket = Ticket::factory()->status(TicketStatus::Open)->breached()->create(['customer_id' => $this->customer->id]);
    $this->ticket->escalation_level = EscalationLevel::Breached;
    $this->ticket->save();
});

function escalationViewer(Role $role, User $customer): User
{
    return match ($role) {
        Role::Customer => $customer,
        Role::Agent => User::factory()->agent()->create(),
        Role::Admin => User::factory()->admin()->create(),
    };
}

it('exposes the escalation level in API payloads to staff only', function (Role $role, bool $sees): void {
    Sanctum::actingAs(escalationViewer($role, $this->customer), ['*']);

    $show = $this->getJson('/api/v1/tickets/'.$this->ticket->number)->assertOk();
    $list = $this->getJson('/api/v1/tickets')->assertOk();

    expect(array_key_exists('escalation_level', $show->json('data')))->toBe($sees)
        ->and(array_key_exists('escalation_level', $list->json('data.0')))->toBe($sees)
        ->and($show->json('data.sla.state.value'))->toBe('breached')
        ->and($show->json('data.sla.due_at'))->not->toBeNull()
        ->and($list->json('data.0.sla.state.value'))->toBe('breached');

    if ($sees) {
        expect($show->json('data.escalation_level'))->toBe(EscalationLevel::Breached->value);
    }
})->with([
    'customer' => [Role::Customer, false],
    'agent' => [Role::Agent, true],
    'admin' => [Role::Admin, true],
]);

it('exposes the escalation level in Inertia ticket props to staff only', function (Role $role, bool $sees): void {
    $this->actingAs(escalationViewer($role, $this->customer));

    $this->get('/tickets/'.$this->ticket->number)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Show')
            ->has('ticket.sla.state')
            ->has('ticket.sla.due_at')
            ->when($sees, fn (Assert $p) => $p->where('ticket.escalation_level', EscalationLevel::Breached->value), fn (Assert $p) => $p->missing('ticket.escalation_level')));

    $this->get('/tickets?scope=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/Index')
            ->has('tickets.data.0.sla.state')
            ->when($sees, fn (Assert $p) => $p->where('tickets.data.0.escalation_level', EscalationLevel::Breached->value), fn (Assert $p) => $p->missing('tickets.data.0.escalation_level')));
})->with([
    'customer' => [Role::Customer, false],
    'agent' => [Role::Agent, true],
]);
