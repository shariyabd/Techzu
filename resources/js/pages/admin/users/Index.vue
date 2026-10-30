<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import TableCard from '@/components/admin/TableCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create, edit, index } from '@/routes/admin/users';
import type { Paginated } from '@/types/pagination';
import type { AdminUser, RoleOption, UserFilters } from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: UserFilters;
    roles: RoleOption[];
}>();

const search = ref(props.filters.q ?? '');
const role = ref<string>(props.filters.role ?? '');

const isFiltered = computed(
    () => (props.filters.q ?? '') !== '' || (props.filters.role ?? '') !== '',
);

const applyFilters = (): void => {
    const query: Record<string, string> = {};

    if (search.value !== '') {
        query.q = search.value;
    }

    if (role.value !== '') {
        query.role = role.value;
    }

    router.get(index.url(), query, { preserveState: true, replace: true });
};

watch(role, applyFilters);

const clearFilters = (): void => {
    search.value = '';

    if (role.value === '') {
        applyFilters();

        return;
    }

    role.value = '';
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

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Users"
            description="Accounts, their role and, for agents, their assignment profile."
        >
            <template #actions>
                <Button as-child
                    ><Link :href="create()"
                        ><Plus aria-hidden="true" />New user</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <form
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"
            @submit.prevent="applyFilters"
        >
            <Input
                v-model="search"
                type="search"
                placeholder="Name or email starts with…"
                class="sm:max-w-xs"
                aria-label="Search users"
            />
            <div class="sm:w-44">
                <NativeSelect v-model="role" aria-label="Filter by role">
                    <NativeSelectOption value="">All roles</NativeSelectOption>
                    <NativeSelectOption
                        v-for="option in roles"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</NativeSelectOption
                    >
                </NativeSelect>
            </div>
            <Button type="submit" variant="outline"
                ><Search aria-hidden="true" />Search</Button
            >
        </form>

        <EmptyState
            v-if="users.data.length === 0"
            :icon="Users"
            :title="
                isFiltered ? 'No users match these filters' : 'No users yet'
            "
            :description="
                isFiltered
                    ? 'Try a different name, email or role.'
                    : 'Create the first account to get started.'
            "
        >
            <Button v-if="isFiltered" variant="outline" @click="clearFilters"
                >Clear filters</Button
            >
            <Button v-else as-child
                ><Link :href="create()">Create a user</Link></Button
            >
        </EmptyState>

        <template v-else>
            <TableCard>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Email</TableHead
                            >
                            <TableHead>Role</TableHead>
                            <TableHead class="hidden lg:table-cell"
                                >Agent profile</TableHead
                            >
                            <TableHead class="text-right"
                                ><span class="sr-only">Actions</span></TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="user in users.data" :key="user.id">
                            <TableCell class="font-medium">
                                <Link
                                    :href="edit(user.id)"
                                    class="hover:underline"
                                    >{{ user.name }}</Link
                                >
                                <div
                                    class="text-xs font-normal text-muted-foreground md:hidden"
                                >
                                    {{ user.email }}
                                </div>
                            </TableCell>
                            <TableCell class="hidden md:table-cell">{{
                                user.email
                            }}</TableCell>
                            <TableCell>
                                <Badge variant="secondary">{{
                                    roleLabel(user.role)
                                }}</Badge>
                            </TableCell>
                            <TableCell
                                class="hidden text-xs text-muted-foreground lg:table-cell"
                                >{{
                                    user.role === 'agent'
                                        ? agentProfile(user)
                                        : '—'
                                }}</TableCell
                            >
                            <TableCell>
                                <div class="flex justify-end">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link :href="edit(user.id)"
                                            ><Pencil aria-hidden="true" /><span
                                                class="sr-only sm:not-sr-only"
                                                >Edit</span
                                            ></Link
                                        >
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </TableCard>
            <ResourcePagination
                :links="users.links"
                :meta="users.meta"
                noun="users"
            />
        </template>
    </div>
</template>
