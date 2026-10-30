<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketQueueCounts;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->agent = User::factory()->agent()->create();
    $this->otherAgent = User::factory()->agent()->create();
    $this->customer = User::factory()->customer()->create();
    $this->billing = Category::factory()->create();
    $this->tech = Category::factory()->create();

    $make = fn (TicketStatus $status, Category $category, ?User $agent = null, ?User $customer = null) => Ticket::factory()
        ->status($status)
        ->when($agent !== null, fn ($factory) => $factory->assignedTo($agent))
        ->create(['category_id' => $category->id, 'customer_id' => ($customer ?? User::factory()->customer()->create())->id]);

    $make(TicketStatus::Open, $this->billing, $this->agent);
    $make(TicketStatus::InProgress, $this->billing, $this->agent);
    $make(TicketStatus::Open, $this->billing);
    $make(TicketStatus::WaitingForCustomer, $this->tech, $this->otherAgent);
    $make(TicketStatus::Open, $this->tech, null, $this->customer);
    $make(TicketStatus::InProgress, $this->tech, $this->otherAgent, $this->customer);
    $make(TicketStatus::Resolved, $this->tech, $this->agent);
    $make(TicketStatus::Closed, $this->billing, $this->agent, $this->customer);
});

it('counts open and total tickets per queue for staff', function (): void {
    $counts = app(TicketQueueCounts::class)->for($this->agent);

    expect($counts['active'])->toBe(['open' => 3, 'total' => 6])
        ->and($counts['assigned_to_me'])->toBe(['open' => 1, 'total' => 2])
        ->and($counts['unassigned'])->toBe(['open' => 2, 'total' => 2])
        ->and($counts['resolved'])->toBe(['open' => 0, 'total' => 2])
        ->and($counts['categories'])->toEqualCanonicalizing([
            ['category_id' => $this->billing->id, 'open' => 2, 'total' => 3],
            ['category_id' => $this->tech->id, 'open' => 1, 'total' => 3],
        ]);
});

it('limits a customer to their own tickets and hides the staff queues', function (): void {
    $counts = app(TicketQueueCounts::class)->for($this->customer);

    expect($counts)->toBe([
        'active' => ['open' => 1, 'total' => 2],
        'assigned_to_me' => null,
        'unassigned' => null,
        'resolved' => ['open' => 0, 'total' => 1],
        'categories' => [],
    ]);
});

it('exposes the queue counts on the web index only', function (): void {
    $this->actingAs($this->customer)
        ->get(route('tickets.index'))
        ->assertInertia(fn (Assert $page) => $page->where('queues.active.total', 2)->where('queues.unassigned', null));

    $this->actingAs($this->customer, 'sanctum')
        ->getJson(route('api.v1.tickets.index'))
        ->assertJsonMissingPath('queues');
});
