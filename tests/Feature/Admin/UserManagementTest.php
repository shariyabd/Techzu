<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    $this->category = Category::factory()->create(['name' => 'Billing']);
});

it('lists users with role and agent profile, filtered by prefix search and role', function (): void {
    User::factory()->agent([$this->category->id])->create(['name' => 'Bob Agent', 'email' => 'bob@example.com']);
    User::factory()->customer()->create(['name' => 'Cara Customer', 'email' => 'cara@example.com']);

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Index', false)
            ->has('users.data', 3)
            ->where('users.data.1.name', 'Bob Agent')
            ->where('users.data.1.role', Role::Agent->value)
            ->where('users.data.1.categories.0.name', 'Billing')
            ->missing('users.data.1.password')
            ->has('roles', 3));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'car']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1)->where('filters.q', 'car'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => Role::Agent->value]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'bob@example.com')
            ->where('filters.role', Role::Agent->value));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => '%']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));
});

it('renders the create and edit pages, flagging self-edit', function (): void {
    $this->actingAs($this->admin)->get(route('admin.users.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Create', false)
            ->has('roles', 3)
            ->has('categories', 1));

    $this->actingAs($this->admin)->get(route('admin.users.edit', $this->admin))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Edit', false)
            ->where('user.id', $this->admin->id)
            ->where('user.role', Role::Admin->value)
            ->where('isSelf', true));
});

it('creates a user from the web form and returns to the list', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'name' => 'New Agent',
            'email' => 'new.agent@example.com',
            'password' => 'S3cure-passw0rd!',
            'role' => Role::Agent->value,
            'category_ids' => [$this->category->id],
            'is_available' => false,
        ])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'User created.');

    expect(User::query()->where('email', 'new.agent@example.com')->sole()->getRoleNames()->all())
        ->toBe([Role::Agent->value]);
});

it('rejects users that would break the account rules', function (array $payload, string $field): void {
    User::factory()->customer()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), array_merge([
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'S3cure-passw0rd!',
            'role' => Role::Customer->value,
        ], $payload))
        ->assertSessionHasErrors($field);
})->with([
    'duplicate email' => [['email' => 'taken@example.com'], 'email'],
    'unknown role' => [['role' => 'superuser'], 'role'],
    'unknown category' => [['role' => 'agent', 'category_ids' => [999999]], 'category_ids.0'],
]);

it('maps a refused self-demotion to a flash error on the web and a 422 code on the API', function (): void {
    $this->actingAs($this->admin)
        ->from(route('admin.users.edit', $this->admin))
        ->patch(route('admin.users.update', $this->admin), [
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'role' => Role::Agent->value,
        ])
        ->assertRedirect(route('admin.users.edit', $this->admin))
        ->assertSessionHas('error', 'You cannot change your own role.');

    Sanctum::actingAs($this->admin, ['*']);
    $this->patchJson(route('api.v1.admin.users.update', $this->admin), [
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'role' => Role::Customer->value,
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'cannot_change_own_role');
});

it('manages users over the API', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $id = $this->postJson(route('api.v1.admin.users.store'), [
        'name' => 'Api Agent',
        'email' => 'api.agent@example.com',
        'password' => 'S3cure-passw0rd!',
        'role' => Role::Agent->value,
        'category_ids' => [$this->category->id],
    ])->assertCreated()
        ->assertJsonPath('data.role', Role::Agent->value)
        ->assertJsonPath('data.is_available', true)
        ->assertJsonPath('data.categories.0.id', $this->category->id)
        ->assertJsonMissingPath('data.password')
        ->json('data.id');

    $this->patchJson(route('api.v1.admin.users.update', $id), [
        'name' => 'Api Agent',
        'email' => 'api.agent@example.com',
        'role' => Role::Agent->value,
        'category_ids' => [],
        'is_available' => false,
    ])->assertOk()
        ->assertJsonPath('data.is_available', false)
        ->assertJsonPath('data.categories', []);

    $this->getJson(route('api.v1.admin.users.index', ['role' => Role::Agent->value]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'api.agent@example.com');
});

it('shows each fixed role with exactly its seeded permissions', function (): void {
    $permissionsOf = static fn (Role $role): array => array_map(
        static fn (Permission $permission): string => $permission->value,
        Permission::forRole($role),
    );

    $this->actingAs($this->admin)->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/roles/Index', false)
            ->where('roles.0.value', Role::Admin->value)
            ->where('roles.2.value', Role::Customer->value)
            ->where('roles.2.permissions', fn ($permissions): bool => collect($permissions)->sort()->values()->all()
                === collect($permissionsOf(Role::Customer))->sort()->values()->all())
            ->has('permissions', count(Permission::cases()))
            ->where('permissions.0.value', Permission::TicketsCreate->value));

    Sanctum::actingAs($this->admin, ['*']);
    $response = $this->getJson(route('api.v1.admin.roles.index'))->assertOk();

    expect($response->json('data.*.value'))->toBe([Role::Admin->value, Role::Agent->value, Role::Customer->value])
        ->and($response->json('data.1.permissions'))->toEqualCanonicalizing($permissionsOf(Role::Agent));
});
