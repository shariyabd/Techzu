<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, Minus } from '@lucide/vue';
import TableCard from '@/components/admin/TableCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import type { PermissionOption, RolePermissions } from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: rolesIndex() }],
    },
});

const props = defineProps<{
    roles: RolePermissions[];
    permissions: PermissionOption[];
}>();

const grants = (role: RolePermissions, permission: string): boolean =>
    role.permissions.includes(permission);
</script>

<template>
    <Head title="Roles & permissions" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Roles & permissions">
            <template #meta>
                <p class="text-sm text-muted-foreground">
                    Roles are fixed and their permissions are read-only. To
                    change what someone can do, change their role on the
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
                            class="text-center"
                        >
                            <Check
                                v-if="grants(role, permission.value)"
                                class="mx-auto size-4 text-green-600 dark:text-green-400"
                                aria-hidden="true"
                            />
                            <Minus
                                v-else
                                class="mx-auto size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="sr-only">{{
                                grants(role, permission.value)
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
