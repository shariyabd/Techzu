<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Pencil,
    Plus,
} from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import TableCard from '@/components/admin/TableCard.vue';
import EmptyState from '@/components/EmptyState.vue';
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
import { create, destroy, edit, index } from '@/routes/admin/holidays';
import type { HolidayItem } from '@/types/admin';
import type { Paginated } from '@/types/pagination';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Holidays', href: index() }] },
});

defineProps<{ holidays: Paginated<HolidayItem>; year: number }>();

function remove(holiday: HolidayItem): void {
    router.delete(destroy.url(holiday.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Holidays" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Holidays"
            description="SLA deadlines skip these dates, just like weekends and out-of-hours time."
        >
            <template #actions>
                <Button as-child
                    ><Link :href="create()"
                        ><Plus aria-hidden="true" />Add holiday</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <nav aria-label="Year" class="flex items-center gap-2">
            <Button variant="outline" size="icon" as-child>
                <Link
                    :href="index({ query: { year: year - 1 } })"
                    :aria-label="`Show ${year - 1}`"
                    ><ChevronLeft aria-hidden="true"
                /></Link>
            </Button>
            <span
                class="min-w-16 text-center text-lg font-semibold tabular-nums"
                >{{ year }}</span
            >
            <Button variant="outline" size="icon" as-child>
                <Link
                    :href="index({ query: { year: year + 1 } })"
                    :aria-label="`Show ${year + 1}`"
                    ><ChevronRight aria-hidden="true"
                /></Link>
            </Button>
        </nav>

        <EmptyState
            v-if="holidays.data.length === 0"
            :icon="CalendarDays"
            :title="`No holidays in ${year}`"
            description="Business days are counted normally for this year."
        >
            <Button variant="outline" as-child
                ><Link :href="create()">Add holiday</Link></Button
            >
        </EmptyState>

        <template v-else>
            <TableCard>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead class="hidden sm:table-cell"
                                >Day</TableHead
                            >
                            <TableHead>Name</TableHead>
                            <TableHead class="text-right"
                                ><span class="sr-only">Actions</span></TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="holiday in holidays.data"
                            :key="holiday.id"
                        >
                            <TableCell
                                class="font-mono text-xs whitespace-nowrap"
                                >{{ holiday.date }}</TableCell
                            >
                            <TableCell class="hidden sm:table-cell">{{
                                holiday.weekday
                            }}</TableCell>
                            <TableCell class="break-words">{{
                                holiday.name
                            }}</TableCell>
                            <TableCell>
                                <div class="flex justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link :href="edit(holiday.id)"
                                            ><Pencil aria-hidden="true" /><span
                                                class="sr-only sm:not-sr-only"
                                                >Edit</span
                                            ></Link
                                        >
                                    </Button>
                                    <ConfirmDialog
                                        :title="`Delete “${holiday.name}”?`"
                                        :description="`${holiday.weekday} ${holiday.date} becomes a normal business day for new SLA deadlines.`"
                                        confirm-label="Delete"
                                        @confirm="remove(holiday)"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="text-destructive"
                                            >Delete</Button
                                        >
                                    </ConfirmDialog>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </TableCard>
            <ResourcePagination
                :links="holidays.links"
                :meta="holidays.meta"
                noun="holidays"
            />
        </template>
    </div>
</template>
