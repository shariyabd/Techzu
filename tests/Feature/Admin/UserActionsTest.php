<?php

declare(strict_types=1);

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\CreateUser;
use App\Actions\Admin\UpdateUser;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Exceptions\Domain\CannotChangeOwnRole;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->billing = Category::factory()->create();
    $this->hardware = Category::factory()->create();
});

it('leaves a user with exactly the new role and revokes their api tokens', function (): void {
    $user = User::factory()->customer()->create();
    $user->createToken('cli');

    app(ChangeUserRole::class)->handle($this->admin, $user, Role::Agent);

    $user->refresh();
    expect($user->getRoleNames()->all())->toBe([Role::Agent->value])
        ->and($user->tokens()->count())->toBe(0);
});

it('treats keeping the same role as a no-op, even for the admin themselves', function (): void {
    $this->admin->createToken('cli');

    app(ChangeUserRole::class)->handle($this->admin, $this->admin, Role::Admin);

    expect($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value])
        ->and($this->admin->tokens()->count())->toBe(1);
});

it('detaches agent categories when a user stops being an agent', function (): void {
    $agent = User::factory()->agent([$this->billing->id])->create();

    app(ChangeUserRole::class)->handle($this->admin, $agent, Role::Customer);

    expect($agent->categories()->count())->toBe(0);
});

it('refuses to let an admin change their own role', function (Role $role): void {
    expect(fn () => app(ChangeUserRole::class)->handle($this->admin, $this->admin, $role))
        ->toThrow(CannotChangeOwnRole::class, 'You cannot change your own role.');

    expect($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value]);
})->with([Role::Agent, Role::Customer]);

it('creates an agent with a role, categories and availability', function (): void {
    $user = app(CreateUser::class)->handle($this->admin, [
        'name' => 'New Agent',
        'email' => 'new.agent@example.com',
        'password' => 'S3cure-passw0rd!',
        'role' => Role::Agent->value,
        'category_ids' => [$this->billing->id, $this->hardware->id],
        'is_available' => false,
    ])->fresh();

    expect($user?->getRoleNames()->all())->toBe([Role::Agent->value])
        ->and($user?->categories()->count())->toBe(2)
        ->and($user?->is_available)->toBeFalse()
        ->and(Hash::check('S3cure-passw0rd!', (string) $user?->password))->toBeTrue();
});

it('updates a user, keeping the password when none is given and syncing the agent profile', function (): void {
    $user = User::factory()->customer()->create(['password' => 'old-password-123']);

    app(UpdateUser::class)->handle($this->admin, $user, [
        'name' => 'Renamed',
        'email' => 'renamed@example.com',
        'password' => null,
        'role' => Role::Agent->value,
        'category_ids' => [$this->billing->id],
        'is_available' => false,
    ]);

    $user->refresh();
    expect($user->name)->toBe('Renamed')
        ->and(Hash::check('old-password-123', $user->password))->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe([Role::Agent->value])
        ->and($user->is_available)->toBeFalse()
        ->and($user->categories()->pluck('categories.id')->all())->toBe([$this->billing->id]);
});

it('revokes api tokens when an admin sets a new password', function (): void {
    $user = User::factory()->customer()->create();
    $user->createToken('cli');

    app(UpdateUser::class)->handle($this->admin, $user, [
        'name' => $user->name,
        'email' => $user->email,
        'password' => 'brand-new-password',
        'role' => Role::Customer->value,
        'category_ids' => null,
        'is_available' => null,
    ]);

    expect(Hash::check('brand-new-password', (string) $user->fresh()?->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('leaves every field untouched when the admin tries to demote themselves', function (): void {
    expect(fn () => app(UpdateUser::class)->handle($this->admin, $this->admin, [
        'name' => 'Should not stick',
        'email' => $this->admin->email,
        'password' => null,
        'role' => Role::Customer->value,
        'category_ids' => null,
        'is_available' => null,
    ]))->toThrow(CannotChangeOwnRole::class);

    expect($this->admin->fresh()?->name)->not->toBe('Should not stick')
        ->and($this->admin->fresh()?->getRoleNames()->all())->toBe([Role::Admin->value]);
});

it('unassigns the active tickets of a demoted agent and leaves finished ones alone', function (): void {
    $agent = User::factory()->agent()->create();
    $active = Ticket::factory()->status(TicketStatus::InProgress)->assignedTo($agent)->create();
    $finished = Ticket::factory()->status(TicketStatus::Resolved)->assignedTo($agent)->create();

    app(ChangeUserRole::class)->handle($this->admin, $agent, Role::Customer);

    expect($active->fresh()?->assigned_agent_id)->toBeNull()
        ->and($finished->fresh()?->assigned_agent_id)->toBe($agent->id);
    $activity = Activity::query()->where('event', ActivityType::Unassigned->value)->sole();
    expect($activity->subject_id)->toBe($active->id)
        ->and($activity->causer_id)->toBe($this->admin->id);
});
