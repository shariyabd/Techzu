<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import TableCard from '@/components/admin/TableCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    index as rolesIndex,
    update as rolesUpdate,
} from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    PermissionOption,
    RolePermissions,
    RoleValue,
} from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: rolesIndex() }],
    },
});

const INTERNAL_NOTE_PERMISSION = 'tickets.notes.internal';
const USER_MANAGEMENT_PERMISSION = 'users.manage';

const props = defineProps<{
    roles: RolePermissions[];
    permissions: PermissionOption[];
}>();

const grants = reactive<Record<RoleValue, string[]>>(
    Object.fromEntries(
        props.roles.map((role) => [role.value, [...role.permissions]]),
    ) as Record<RoleValue, string[]>,
);

const saving = reactive<Record<RoleValue, boolean>>(
    Object.fromEntries(
        props.roles.map((role) => [role.value, false]),
    ) as Record<RoleValue, boolean>,
);

const busy = ref(false);

watch(
    () => props.roles,
    (roles) => {
        roles.forEach((role) => {
            grants[role.value] = [...role.permissions];
        });
    },
);

const isLocked = (role: RoleValue, permission: string): boolean =>
    (role === 'customer' && permission === INTERNAL_NOTE_PERMISSION) ||
    (role === 'admin' && permission === USER_MANAGEMENT_PERMISSION);

function toggle(role: RoleValue, permission: string, checked: boolean): void {
    const granted = new Set(grants[role]);
    if (checked) {
        granted.add(permission);
    } else {
        granted.delete(permission);
    }

    const candidate = [...granted];
    grants[role] = candidate;
    saving[role] = true;
    busy.value = true;

    router.patch(
        rolesUpdate.url(role),
        { permissions: candidate },
        {
            preserveScroll: true,
            onError: () => {
                const server = props.roles.find(({ value }) => value === role);
                if (server) {
                    grants[role] = [...server.permissions];
                }
            },
            onFinish: () => {
                saving[role] = false;
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Roles & permissions" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Roles & permissions">
            <template #meta>
                <p class="text-sm text-muted-foreground">
                    Roles are fixed. Check or uncheck a permission to grant or
                    revoke it from a role; changes apply immediately to every
                    signed-in user. Internal notes can never be granted to
                    customers, and the admin role always keeps user management.
                    To change what someone can do, change their role on the
                    <Link
                        :href="usersIndex()"
                        class="underline underline-offset-4"
                        >Users</Link
                    >
                    page.
                </p>
            </template>
        </PageHeader>

        <TableCard>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Permission</TableHead>
                        <TableHead
                            v-for="role in props.roles"
                            :key="role.value"
                            class="text-center"
                            >{{ role.label }}</TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="permission in props.permissions"
                        :key="permission.value"
                    >
                        <TableCell>
                            <div class="font-medium">
                                {{ permission.label }}
                            </div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ permission.value }}
                            </div>
                        </TableCell>
                        <TableCell
                            v-for="role in props.roles"
                            :key="role.value"
                            :class="[
                                'text-center',
                                saving[role.value] ? 'opacity-60' : '',
                            ]"
                        >
                            <Checkbox
                                class="mx-auto"
                                :checked="
                                    grants[role.value].includes(
                                        permission.value,
                                    )
                                "
                                :disabled="
                                    saving[role.value] ||
                                    (busy && !saving[role.value]) ||
                                    isLocked(role.value, permission.value)
                                "
                                :aria-label="`${permission.label} — ${role.label}`"
                                @update:checked="
                                    (checked: boolean | 'indeterminate') =>
                                        toggle(
                                            role.value,
                                            permission.value,
                                            checked === true,
                                        )
                                "
                            />
                            <span class="sr-only">{{
                                grants[role.value].includes(permission.value)
                                    ? 'Granted'
                                    : 'Not granted'
                            }}</span>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </TableCard>
    </div>
</template>
