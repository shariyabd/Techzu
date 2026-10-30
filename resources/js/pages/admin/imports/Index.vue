<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileUp, Plus } from '@lucide/vue';
import TableCard from '@/components/admin/TableCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative } from '@/lib/format';
import { create, index, show } from '@/routes/admin/imports';
import type { TicketImportItem } from '@/types/imports';
import type { Paginated } from '@/types/pagination';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Imports', href: index() }] },
});

defineProps<{ imports: Paginated<TicketImportItem> }>();
</script>

<template>
    <Head title="Ticket imports" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Ticket imports"
            description="Bulk-create tickets from a CSV file. Every upload is listed here, newest first."
        >
            <template #actions>
                <Button as-child
                    ><Link :href="create()"
                        ><Plus aria-hidden="true" />New import</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <EmptyState
            v-if="imports.data.length === 0"
            :icon="FileUp"
            title="No imports yet"
            description="Upload a CSV file to create many tickets at once."
        >
            <Button as-child><Link :href="create()">Upload a CSV</Link></Button>
        </EmptyState>

        <template v-else>
            <TableCard>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>File</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="hidden text-right md:table-cell"
                                >Rows</TableHead
                            >
                            <TableHead class="text-right">Imported</TableHead>
                            <TableHead class="text-right">Rejected</TableHead>
                            <TableHead class="hidden lg:table-cell"
                                >Uploaded by</TableHead
                            >
                            <TableHead class="hidden md:table-cell"
                                >Uploaded</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="item in imports.data" :key="item.id">
                            <TableCell class="max-w-[12rem] sm:max-w-xs">
                                <Link
                                    :href="show(item.id)"
                                    class="block truncate font-medium underline-offset-4 hover:underline"
                                    >{{ item.original_name }}</Link
                                >
                            </TableCell>
                            <TableCell
                                ><ImportStatusBadge :status="item.status"
                            /></TableCell>
                            <TableCell
                                class="hidden text-right tabular-nums md:table-cell"
                                >{{ item.total_rows }}</TableCell
                            >
                            <TableCell class="text-right tabular-nums">{{
                                item.succeeded_rows
                            }}</TableCell>
                            <TableCell
                                class="text-right tabular-nums"
                                :class="
                                    item.failed_rows > 0
                                        ? 'text-destructive'
                                        : ''
                                "
                                >{{ item.failed_rows }}</TableCell
                            >
                            <TableCell class="hidden lg:table-cell">{{
                                item.user?.name ?? '—'
                            }}</TableCell>
                            <TableCell
                                class="hidden md:table-cell"
                                :title="formatDateTime(item.created_at)"
                                >{{
                                    formatRelative(item.created_at)
                                }}</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </TableCard>
            <ResourcePagination
                :links="imports.links"
                :meta="imports.meta"
                noun="imports"
            />
        </template>
    </div>
</template>
