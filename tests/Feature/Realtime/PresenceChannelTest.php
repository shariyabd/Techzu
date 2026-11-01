<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

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

/** @return TestResponse<Response> */
function joinTicketPresence(User $user, Ticket $ticket): TestResponse
{
    return test()->actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-presence.tickets.{$ticket->number}",
    ]);
}

it('lets only users who may view the ticket join its presence channel', function (string $who, bool $allowed): void {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id, 'created_by_id' => $customer->id]);

    $users = [
        'owner' => fn (): User => $customer,
        'other customer' => fn (): User => User::factory()->customer()->create(),
        'agent' => fn (): User => User::factory()->agent()->create(),
        'agent without notes' => fn (): User => User::factory()->create()->givePermissionTo([Permission::TicketsViewAny->value]),
        'admin' => fn (): User => User::factory()->admin()->create(),
    ];
    $user = $users[$who]();

    expect(joinTicketPresence($user, $ticket)->status())->toBe($allowed ? 200 : 403);
})->with([
    'owner customer joins' => ['owner', true],
    'other customer is refused' => ['other customer', false],
    'agent joins' => ['agent', true],
    'staff with view but without internal notes joins' => ['agent without notes', true],
    'admin joins' => ['admin', true],
]);

it('shares only id, name, avatar and side with presence members, never the email', function (string $who, string $role): void {
    $customer = User::factory()->customer()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id, 'created_by_id' => $customer->id]);
    $user = $who === 'owner' ? $customer : User::factory()->agent()->create();

    $channelData = json_decode((string) joinTicketPresence($user, $ticket)->assertOk()->json('channel_data'), true);

    expect((string) $channelData['user_id'])->toBe((string) $user->id)
        ->and($channelData['user_info'])->toBe([
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatarUrl(),
            'role' => $role,
        ])
        ->and(json_encode($channelData))->not->toContain($user->email);
})->with([
    'customer member' => ['owner', 'customer'],
    'staff member' => ['agent', 'staff'],
]);
