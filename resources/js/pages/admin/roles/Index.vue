<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { PermissionOption, RolePermissions } from '@/types/users';
import { Head, Link } from '@inertiajs/vue3';
import { Check, Minus } from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: '/admin/roles' }],
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

    <div class="p-4">
        <Card>
            <CardHeader>
                <CardTitle>Roles &amp; permissions</CardTitle>
                <CardDescription>
                    Roles are fixed and their permissions are read-only. To
                    change what someone can do, change their role on the
                    <Link
                        href="/admin/users"
                        class="underline underline-offset-4"
                        >Users</Link
                    >
                    page.
                </CardDescription>
            </CardHeader>
            <CardContent>
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
                                    class="mx-auto size-4 text-green-600"
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
            </CardContent>
        </Card>
    </div>
</template>
