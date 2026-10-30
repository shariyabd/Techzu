<script setup lang="ts">
import { Head, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { CheckCircle2, Download } from '@lucide/vue';
import { computed, watch, watchEffect } from 'vue';
import TableCard from '@/components/admin/TableCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import ImportStatusBadge from '@/components/imports/ImportStatusBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { index, show } from '@/routes/admin/imports';
import { download } from '@/routes/admin/imports/errors';
import type { TicketImportErrorItem, TicketImportItem } from '@/types/imports';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    ticketImport: TicketImportItem;
    importErrors: Paginated<TicketImportErrorItem>;
}>();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Imports', href: index() },
            {
                title: props.ticketImport.original_name,
                href: show(props.ticketImport.id),
            },
        ],
    });
});

const { stop } = usePoll(
    2000,
    { only: ['ticketImport', 'importErrors'] },
    { autoStart: !props.ticketImport.is_finished },
);

watch(
    () => props.ticketImport.is_finished,
    (finished) => {
        if (finished) {
            stop();
        }
    },
);

const counters = computed(() => [
    { label: 'Rows in file', value: props.ticketImport.total_rows },
    { label: 'Processed', value: props.ticketImport.processed_rows },
    { label: 'Imported', value: props.ticketImport.succeeded_rows },
    { label: 'Rejected', value: props.ticketImport.failed_rows },
]);

const canDownload = computed(
    () =>
        props.ticketImport.failed_rows > 0 ||
        props.ticketImport.status.value === 'failed',
);

const messages = (error: TicketImportErrorItem): string[] =>
    Object.values(error.errors).flat();
const subjectOf = (error: TicketImportErrorItem): string =>
    error.raw.subject ?? '—';
</script>

<template>
    <Head :title="`Import: ${ticketImport.original_name}`" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="ticketImport.original_name">
            <template #meta>
                <div
                    class="flex flex-wrap items-center gap-2 pt-1 text-sm text-muted-foreground"
                >
                    <ImportStatusBadge :status="ticketImport.status" />
                    <span v-if="ticketImport.user?.name"
                        >by {{ ticketImport.user.name }}</span
                    >
                    <span>· {{ formatDateTime(ticketImport.created_at) }}</span>
                </div>
            </template>
            <template #actions>
                <Button v-if="canDownload" variant="outline" as-child>
                    <a :href="download.url(ticketImport.id)"
                        ><Download aria-hidden="true" />Download errors (CSV)</a
                    >
                </Button>
            </template>
        </PageHeader>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Progress</CardTitle>
                <CardDescription>{{
                    ticketImport.is_finished
                        ? `Finished ${formatDateTime(ticketImport.finished_at)}`
                        : 'Updating every few seconds…'
                }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <Progress
                    :model-value="ticketImport.progress_percent"
                    :aria-label="`${ticketImport.progress_percent}% processed`"
                />
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div
                        v-for="counter in counters"
                        :key="counter.label"
                        class="rounded-lg border p-3"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ counter.label }}
                        </dt>
                        <dd class="text-2xl font-semibold tabular-nums">
                            {{ counter.value }}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Rejected rows</CardTitle>
                <CardDescription
                    >Row numbers match the spreadsheet (the header is row 1).
                    Fix these rows and upload them again.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <EmptyState
                    v-if="importErrors.data.length === 0"
                    :icon="CheckCircle2"
                    title="No rejected rows"
                />
                <template v-else>
                    <TableCard>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-16 text-right"
                                        >Row</TableHead
                                    >
                                    <TableHead class="hidden md:table-cell"
                                        >Subject</TableHead
                                    >
                                    <TableHead>Problems</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="error in importErrors.data"
                                    :key="error.id"
                                    class="align-top"
                                >
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{ error.row_number }}</TableCell
                                    >
                                    <TableCell
                                        class="hidden max-w-xs truncate md:table-cell"
                                        >{{ subjectOf(error) }}</TableCell
                                    >
                                    <TableCell class="whitespace-normal">
                                        <ul class="list-disc pl-4">
                                            <li
                                                v-for="message in messages(
                                                    error,
                                                )"
                                                :key="message"
                                            >
                                                {{ message }}
                                            </li>
                                        </ul>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </TableCard>
                    <ResourcePagination
                        :links="importErrors.links"
                        :meta="importErrors.meta"
                        :only="['importErrors']"
                        noun="rejected rows"
                    />
                </template>
            </CardContent>
        </Card>
    </div>
</template>
