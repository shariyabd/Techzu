<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;

/**
 * @param  list<Permission>  $permissions
 * @return list<string>
 */
function permissionValues(array $permissions): array
{
    $values = array_map(fn (Permission $permission): string => $permission->value, $permissions);
    sort($values);

    return $values;
}

it('grants each role exactly its TD-011 baseline', function (Role $role, array $expected): void {
    expect(permissionValues(Permission::forRole($role)))->toBe(permissionValues($expected));
})->with([
    'customer: create as self, view/reply/update own' => [Role::Customer, [
        Permission::TicketsCreate,
        Permission::TicketsViewOwn,
        Permission::TicketsReply,
        Permission::TicketsUpdateOwn,
    ]],
    'agent: whole queue, internal notes, claim, assigned-only changes, hand-off, dashboard' => [Role::Agent, [
        Permission::TicketsCreate,
        Permission::TicketsCreateOnBehalf,
        Permission::TicketsViewAny,
        Permission::TicketsReply,
        Permission::TicketsNotesInternal,
        Permission::TicketsClaim,
        Permission::TicketsUpdateAssigned,
        Permission::TicketsHandoffOwn,
        Permission::DashboardView,
    ]],
    'admin: every unscoped capability plus all admin areas' => [Role::Admin, [
        Permission::TicketsCreate,
        Permission::TicketsCreateOnBehalf,
        Permission::TicketsViewAny,
        Permission::TicketsReply,
        Permission::TicketsNotesInternal,
        Permission::TicketsClaim,
        Permission::TicketsUpdateAny,
        Permission::TicketsAssignAny,
        Permission::DashboardView,
        Permission::ImportsManage,
        Permission::UsersManage,
        Permission::CategoriesManage,
        Permission::HolidaysManage,
    ]],
]);

it('assigns every permission to at least one role, with no duplicates', function (): void {
    $granted = [];

    foreach (Role::cases() as $role) {
        $values = permissionValues(Permission::forRole($role));
        expect($values)->toBe(array_values(array_unique($values)));
        $granted = [...$granted, ...$values];
    }

    expect(array_values(array_unique($granted)))->toEqualCanonicalizing(permissionValues(Permission::cases()));
});
