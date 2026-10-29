<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Paginated } from '@/types/tickets';
import type { AdminUser, RoleOption, UserFilters } from '@/types/users';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
    },
});

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: UserFilters;
    roles: RoleOption[];
}>();

const search = ref(props.filters.q ?? '');
const role = ref<string>(props.filters.role ?? 'all');

const applyFilters = (): void => {
    const query: Record<string, string> = {};

    if (search.value !== '') {
        query.q = search.value;
    }

    if (role.value !== 'all') {
        query.role = role.value;
    }

    router.get('/admin/users', query, { preserveState: true, replace: true });
};

const onRoleChange = (value: unknown): void => {
    role.value = String(value ?? 'all');
    applyFilters();
};

const roleLabel = (value: string | null): string =>
    props.roles.find((option) => option.value === value)?.label ?? 'No role';

const agentProfile = (user: AdminUser): string => {
    const categories =
        user.categories?.map((category) => category.name).join(', ') ||
        'no categories';

    return `${user.is_available ? 'Available' : 'Unavailable'} · ${categories}`;
};
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">Users</h1>
            <Button as-child size="sm">
                <Link href="/admin/users/create">New user</Link>
            </Button>
        </div>

        <form class="flex flex-wrap gap-2" @submit.prevent="applyFilters">
            <Input
                v-model="search"
                type="search"
                placeholder="Name or email starts with…"
                class="max-w-xs"
                aria-label="Search users"
            />
            <Select :model-value="role" @update:model-value="onRoleChange">
                <SelectTrigger class="w-44" aria-label="Filter by role">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All roles</SelectItem>
                    <SelectItem
                        v-for="option in roles"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button type="submit" variant="outline">Search</Button>
        </form>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Agent profile</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-if="props.users.data.length === 0">
                    <TableCell
                        colspan="5"
                        class="text-center text-muted-foreground"
                        >No users match.</TableCell
                    >
                </TableRow>
                <TableRow v-for="user in props.users.data" :key="user.id">
                    <TableCell class="font-medium">{{ user.name }}</TableCell>
                    <TableCell>{{ user.email }}</TableCell>
                    <TableCell>
                        <Badge variant="secondary">{{
                            roleLabel(user.role)
                        }}</Badge>
                    </TableCell>
                    <TableCell class="text-xs text-muted-foreground">{{
                        user.role === 'agent' ? agentProfile(user) : '—'
                    }}</TableCell>
                    <TableCell class="text-right">
                        <Button as-child size="sm" variant="outline">
                            <Link :href="`/admin/users/${user.id}/edit`"
                                >Edit</Link
                            >
                        </Button>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">
                Page {{ props.users.meta.current_page }} of
                {{ props.users.meta.last_page }} ·
                {{ props.users.meta.total }} users
            </span>
            <div class="flex gap-2">
                <Button
                    v-if="props.users.links.prev"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="props.users.links.prev" preserve-scroll
                        >Previous</Link
                    >
                </Button>
                <Button
                    v-if="props.users.links.next"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="props.users.links.next" preserve-scroll
                        >Next</Link
                    >
                </Button>
            </div>
        </div>
    </div>
</template>
