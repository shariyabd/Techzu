<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    $this->me = User::factory()->agent()->create();
    $this->someoneElse = User::factory()->agent()->create();
    $this->store = fn (User $user, bool $read = false, string $title = 'Assigned'): DatabaseNotification => $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TicketAssignedNotification::class,
        'data' => ['ticket_number' => 'TKT-2026-000001', 'title' => $title, 'body' => 'You have a new ticket.', 'url' => url('/tickets/TKT-2026-000001')],
        'read_at' => $read ? now() : null,
    ]);
});

it('lists only my notifications, newest first, paginated, optionally unread only', function (): void {
    ($this->store)($this->me, title: 'older');
    $this->travel(1)->minutes();
    ($this->store)($this->me, read: true, title: 'newer');
    ($this->store)($this->someoneElse, title: 'not mine');
    Sanctum::actingAs($this->me, ['*']);
    $this->getJson(route('api.v1.notifications.index', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'newer')
        ->assertJsonPath('data.0.type', 'TicketAssignedNotification')
        ->assertJsonPath('data.0.ticket_number', 'TKT-2026-000001')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonMissing(['title' => 'not mine']);
    $this->getJson(route('api.v1.notifications.index', ['unread' => 1]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'older');
});

it('renders the inbox props and shares my unread count', function (): void {
    ($this->store)($this->me);
    ($this->store)($this->me);
    ($this->store)($this->me, read: true);
    ($this->store)($this->someoneElse);
    $this->actingAs($this->me)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/Index', false)
            ->has('items.data', 3)
            ->where('unreadOnly', false)
            ->where('notifications.unread_count', 2));
});

it('marks my notification as read on web and API', function (): void {
    $apiOne = ($this->store)($this->me);
    $webOne = ($this->store)($this->me);
    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read', $apiOne->id))->assertOk()->assertJsonPath('data.id', $apiOne->id);
    $this->actingAs($this->me)
        ->from(route('notifications.index'))
        ->post(route('notifications.read', $webOne->id))
        ->assertRedirect(route('notifications.index'));
    expect($apiOne->fresh()->read_at)->not->toBeNull()
        ->and($webOne->fresh()->read_at)->not->toBeNull();
});

it("answers 404 for another user's notification and leaves it unread", function (): void {
    $theirs = ($this->store)($this->someoneElse);
    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read', $theirs->id))->assertNotFound();
    $this->actingAs($this->me)->post(route('notifications.read', $theirs->id))->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();
});

it('marks all of my notifications as read and leaves others alone', function (): void {
    ($this->store)($this->me);
    $theirs = ($this->store)($this->someoneElse);
    Sanctum::actingAs($this->me, ['*']);
    $this->postJson(route('api.v1.notifications.read-all'))->assertNoContent();
    ($this->store)($this->me);
    $this->actingAs($this->me)
        ->from(route('notifications.index'))
        ->post(route('notifications.read-all'))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHas('success');
    expect($this->me->unreadNotifications()->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();
});
