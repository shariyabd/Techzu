<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('seeds the documented demo accounts with the password "password"', function (): void {
    $this->seed(DemoSeeder::class);

    $expected = [
        'admin@example.com' => Role::Admin,
        'agent1@example.com' => Role::Agent,
        'agent2@example.com' => Role::Agent,
        'agent3@example.com' => Role::Agent,
        'agent4@example.com' => Role::Agent,
        'customer1@example.com' => Role::Customer,
        'customer2@example.com' => Role::Customer,
        'customer3@example.com' => Role::Customer,
        'customer4@example.com' => Role::Customer,
        'customer5@example.com' => Role::Customer,
    ];

    expect(User::query()->count())->toBe(10);

    foreach ($expected as $email => $role) {
        $user = User::query()->where('email', $email)->firstOrFail();

        expect($user->hasRole($role->value))->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeTrue();
    }
});

it('spreads four agents across three categories, with one unavailable', function (): void {
    $this->seed(DemoSeeder::class);

    expect(Category::query()->count())->toBe(3)
        ->and(User::query()->availableAgents()->count())->toBe(3);

    foreach (Category::query()->with('agents:id')->get() as $category) {
        expect($category->agents)->not->toBeEmpty();
    }
});

it('seeds sixty tickets in mixed states with sequential numbers', function (): void {
    $this->seed(DemoSeeder::class);

    $tickets = Ticket::query()->orderBy('id')->get(['id', 'number', 'status', 'assigned_agent_id', 'sla_due_at']);
    $year = now()->year;
    $prefix = (string) config('tickets.number_prefix', 'TKT');

    expect($tickets)->toHaveCount(60)
        ->and($tickets->first()?->number)->toBe(sprintf('%s-%d-000001', $prefix, $year))
        ->and($tickets->last()?->number)->toBe(sprintf('%s-%d-000060', $prefix, $year))
        ->and($tickets->pluck('status')->unique(fn (TicketStatus $status): string => $status->value)->count())->toBe(count(TicketStatus::cases()))
        ->and($tickets->whereNull('assigned_agent_id')->count())->toBeGreaterThan(0)
        ->and($tickets->filter(fn (Ticket $ticket): bool => $ticket->isActive() && $ticket->sla_due_at->isPast())->count())->toBeGreaterThan(0)
        ->and((int) DB::table('ticket_sequences')->where('year', $year)->value('last_number'))->toBe(60);
});

it('assigns tickets only to available agents of the ticket category', function (): void {
    $this->seed(DemoSeeder::class);

    $assigned = Ticket::query()
        ->whereNotNull('assigned_agent_id')
        ->with(['assignedAgent:id,is_available', 'assignedAgent.categories:id'])
        ->get(['id', 'category_id', 'assigned_agent_id']);

    expect($assigned)->not->toBeEmpty();

    foreach ($assigned as $ticket) {
        expect($ticket->assignedAgent?->is_available)->toBeTrue()
            ->and($ticket->assignedAgent?->categories->modelKeys())->toContain($ticket->category_id);
    }
});

it('is idempotent and runs through DatabaseSeeder', function (): void {
    $this->seed();
    $this->seed();

    expect(User::query()->count())->toBe(10)
        ->and(Ticket::query()->count())->toBe(60);
});
