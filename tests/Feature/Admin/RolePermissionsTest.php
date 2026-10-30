<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as RoleModel;

function seededPermissionsOfRole(Role $role): array
{
    return RoleModel::where('name', $role->value)
        ->firstOrFail()
        ->getAllPermissions()
        ->pluck('name')
        ->all();
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create();
    $this->customer = User::factory()->customer()->create();
});

it('rewrites role permissions through the api and applies them immediately', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    expect(Gate::forUser($this->agent)->allows(Permission::HolidaysManage->value))->toBeFalse()
        ->and(Gate::forUser($this->agent)->allows(Permission::TicketsNotesInternal->value))->toBeTrue();

    $response = $this->patchJson(route('api.v1.admin.roles.update', Role::Agent->value), [
        'permissions' => [
            Permission::TicketsReply->value,
            Permission::TicketsViewOwn->value,
            Permission::HolidaysManage->value,
        ],
    ])->assertOk();

    $expected = [
        Permission::TicketsReply->value,
        Permission::TicketsViewOwn->value,
        Permission::HolidaysManage->value,
    ];

    expect($response->json('data.value'))->toBe(Role::Agent->value)
        ->and($response->json('data.permissions'))->toEqualCanonicalizing($expected)
        ->and(seededPermissionsOfRole(Role::Agent))->toEqualCanonicalizing($expected)
        ->and(Gate::forUser($this->agent->fresh())->allows(Permission::HolidaysManage->value))->toBeTrue()
        ->and(Gate::forUser($this->agent->fresh())->allows(Permission::TicketsNotesInternal->value))->toBeFalse();
});

it('always keeps user management available on the admin role', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $before = seededPermissionsOfRole(Role::Admin);

    $response = $this->patchJson(route('api.v1.admin.roles.update', Role::Admin->value), [
        'permissions' => [Permission::TicketsReply->value],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'cannot_remove_user_management');

    expect(seededPermissionsOfRole(Role::Admin))->toEqualCanonicalizing($before)
        ->and($response->json('data'))->toBeNull();
});

it('never grants internal notes to the customer role', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $before = seededPermissionsOfRole(Role::Customer);

    $this->patchJson(route('api.v1.admin.roles.update', Role::Customer->value), [
        'permissions' => [
            Permission::TicketsReply->value,
            Permission::TicketsNotesInternal->value,
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'customer_internal_notes_forbidden');

    expect(seededPermissionsOfRole(Role::Customer))->toEqualCanonicalizing($before);
});

it('lets an admin strip a role of every permission', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->patchJson(route('api.v1.admin.roles.update', Role::Customer->value), [
        'permissions' => [],
    ])->assertOk()
        ->assertJsonPath('data.permissions', []);

    expect(Gate::forUser($this->customer->fresh())->allows(Permission::TicketsReply->value))->toBeFalse();
});
