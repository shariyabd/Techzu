<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Resources\V1\NotificationResource;
use App\Models\User;
use App\Notifications\InternalNoteAddedNotification;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

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

it('shares my newest unread notification so the bell can announce what just arrived', function (): void {
    ($this->store)($this->me, title: 'older unread');
    $this->travel(1)->minutes();
    $newest = ($this->store)($this->me, title: 'New reply on TKT-2026-000001');
    $this->travel(1)->minutes();
    ($this->store)($this->me, read: true, title: 'newer but read');
    ($this->store)($this->someoneElse, title: 'not mine');

    $this->actingAs($this->me)
        ->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.latest_unread.id', $newest->id)
            ->where('notifications.latest_unread.title', 'New reply on TKT-2026-000001')
            ->where('notifications.latest_unread.body', 'You have a new ticket.')
            ->where('notifications.latest_unread.url', url('/tickets/TKT-2026-000001'))
            ->has('notifications.latest_unread.created_at'));
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

it('hides internal-note notifications from a viewer whose role lost internal notes', function (): void {
    ($this->store)($this->me, title: 'assigned');
    $note = $this->me->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => InternalNoteAddedNotification::class,
        'data' => ['ticket_number' => 'TKT-2026-000001', 'title' => 'Internal note', 'body' => 'SECRET-EXCERPT', 'url' => url('/tickets/TKT-2026-000001')],
    ]);
    RoleModel::findByName(Role::Agent->value)->revokePermissionTo(Permission::TicketsNotesInternal->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $viewer = $this->me->fresh();

    Sanctum::actingAs($viewer, ['*']);
    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'assigned')
        ->assertDontSee('SECRET-EXCERPT');
    $this->actingAs($viewer)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('notifications.unread_count', 1)
            ->where('notifications.latest_unread.title', 'assigned'));

    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $viewer);
    expect(NotificationResource::make($note)->toArray($request))->body->toBe('')->title->toBe('');
});
