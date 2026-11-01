<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
});

function joinChannel(User $user, string $channel): int
{
    return test()->actingAs($user)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"])
        ->status();
}

it('lets only authorized users join realtime channels', function (string $who, string $channel, bool $allowed): void {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id, 'created_by_id' => $customer->id]);
    $agentWithoutNotes = User::factory()->create()->givePermissionTo([Permission::TicketsViewAny->value, Permission::TicketsReply->value]);

    $users = [
        'owner' => $customer,
        'other customer' => User::factory()->customer()->create(),
        'agent' => User::factory()->agent()->create(),
        'agent without notes' => $agentWithoutNotes,
        'admin' => User::factory()->admin()->create(),
    ];
    $user = $users[$who];

    $name = str_replace(['{number}', '{user}'], [$ticket->number, (string) $user->id], $channel);

    expect(joinChannel($user, $name))->toBe($allowed ? 200 : 403);
})->with([
    'owner joins own ticket channel' => ['owner', 'tickets.{number}', true],
    'other customer is refused the ticket channel' => ['other customer', 'tickets.{number}', false],
    'owner is refused the staff channel' => ['owner', 'tickets.{number}.staff', false],
    'owner is refused the queue channel' => ['owner', 'tickets.queue', false],
    'agent joins the ticket channel' => ['agent', 'tickets.{number}', true],
    'agent joins the staff channel' => ['agent', 'tickets.{number}.staff', true],
    'agent joins the queue channel' => ['agent', 'tickets.queue', true],
    'agent without the notes permission joins the ticket channel' => ['agent without notes', 'tickets.{number}', true],
    'agent without the notes permission is refused the staff channel' => ['agent without notes', 'tickets.{number}.staff', false],
    'admin joins the queue channel' => ['admin', 'tickets.queue', true],
    'user joins own notification channel' => ['owner', 'App.Models.User.{user}', true],
]);

it('refuses another user\'s notification channel', function (): void {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();

    expect(joinChannel($customer, "App.Models.User.{$agent->id}"))->toBe(403);
});

it('refuses a channel for a ticket that does not exist', function (): void {
    expect(joinChannel(User::factory()->agent()->create(), 'tickets.TKT-0000-000000'))->toBe(403);
});
