<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Tags } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create, destroy, edit, index } from '@/routes/admin/categories';
import type { AdminCategory } from '@/types/admin';
import type { Paginated } from '@/types/pagination';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Categories', href: index() }] },
});

defineProps<{ categories: Paginated<AdminCategory> }>();

const inUse = (category: AdminCategory): boolean => category.tickets_count > 0;

function remove(category: AdminCategory): void {
    router.delete(destroy.url(category.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Categories" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Categories"
            description="Ticket categories, used for routing tickets to agents and for CSV imports."
        >
            <template #actions>
                <Button as-child
                    ><Link :href="create()"
                        ><Plus aria-hidden="true" />New category</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <EmptyState
            v-if="categories.data.length === 0"
            :icon="Tags"
            title="No categories yet"
            description="Customers need at least one active category to open a ticket."
        >
            <Button as-child
                ><Link :href="create()">Create a category</Link></Button
            >
        </EmptyState>

        <template v-else>
            <div class="overflow-x-auto rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Slug</TableHead
                            >
                            <TableHead>Status</TableHead>
                            <TableHead class="hidden text-right sm:table-cell"
                                >Tickets</TableHead
                            >
                            <TableHead class="text-right"
                                ><span class="sr-only">Actions</span></TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="category in categories.data"
                            :key="category.id"
                        >
                            <TableCell class="font-medium">{{
                                category.name
                            }}</TableCell>
                            <TableCell
                                class="hidden font-mono text-xs md:table-cell"
                                >{{ category.slug }}</TableCell
                            >
                            <TableCell>
                                <Badge
                                    variant="outline"
                                    :class="
                                        category.is_active
                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    >{{
                                        category.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }}</Badge
                                >
                            </TableCell>
                            <TableCell
                                class="hidden text-right tabular-nums sm:table-cell"
                                >{{ category.tickets_count }}</TableCell
                            >
                            <TableCell>
                                <div class="flex justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link :href="edit(category.id)"
                                            ><Pencil aria-hidden="true" /><span
                                                class="sr-only sm:not-sr-only"
                                                >Edit</span
                                            ></Link
                                        >
                                    </Button>
                                    <ConfirmDialog
                                        v-if="
                                            category.is_active ||
                                            !inUse(category)
                                        "
                                        :title="
                                            inUse(category)
                                                ? `Deactivate “${category.name}”?`
                                                : `Delete “${category.name}”?`
                                        "
                                        :description="
                                            inUse(category)
                                                ? 'It has tickets, so it is deactivated instead of deleted. Existing tickets keep it; new tickets can no longer choose it.'
                                                : 'This category has no tickets and will be removed permanently.'
                                        "
                                        :confirm-label="
                                            inUse(category)
                                                ? 'Deactivate'
                                                : 'Delete'
                                        "
                                        @confirm="remove(category)"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="text-destructive"
                                            >{{
                                                inUse(category)
                                                    ? 'Deactivate'
                                                    : 'Delete'
                                            }}</Button
                                        >
                                    </ConfirmDialog>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <ResourcePagination
                :links="categories.links"
                :meta="categories.meta"
                noun="categories"
            />
        </template>
    </div>
</template>
