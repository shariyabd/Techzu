<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Inbox, Plus, SearchX } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import TicketFilterBar from '@/components/tickets/TicketFilterBar.vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDateTime } from '@/lib/format';
import { create, index, show } from '@/routes/tickets';
import type {
    Paginated,
    TicketFilterOptions,
    TicketListItem,
    TicketScope,
} from '@/types/tickets';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Tickets', href: index() }] },
});

const props = defineProps<{
    tickets: Paginated<TicketListItem>;
    scope: TicketScope;
    filters: Record<string, unknown>;
    filterOptions: TicketFilterOptions;
}>();

const page = usePage();
const errors = computed(
    () => page.props.errors as Partial<Record<string, string>>,
);

const scopes: { value: TicketScope; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'resolved', label: 'Resolved & closed' },
];

const isFiltered = computed(() =>
    Object.keys(props.filters).some(
        (key) => !['scope', 'page', 'per_page'].includes(key),
    ),
);
const showCustomer = computed(() => props.filterOptions.showPeopleFilters);
</script>

<template>
    <Head title="Tickets" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Tickets"
            description="Search, filter and open support tickets."
        >
            <template #actions>
                <Button as-child>
                    <Link :href="create()"
                        ><Plus aria-hidden="true" />New ticket</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <nav
            aria-label="Ticket scope"
            class="inline-flex w-fit rounded-md border bg-muted p-0.5"
        >
            <Link
                v-for="item in scopes"
                :key="item.value"
                :href="index({ query: { scope: item.value } })"
                :aria-current="scope === item.value ? 'page' : undefined"
                :class="[
                    'rounded px-3 py-1.5 text-sm font-medium transition-colors',
                    scope === item.value
                        ? 'bg-background shadow-xs'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                >{{ item.label }}</Link
            >
        </nav>

        <TicketFilterBar
            :key="scope"
            :scope="scope"
            :filters="filters"
            :options="filterOptions"
            :errors="errors"
        />

        <EmptyState
            v-if="tickets.data.length === 0 && isFiltered"
            :icon="SearchX"
            title="No tickets match these filters"
            description="Try a broader keyword or clear the filters."
        >
            <Button variant="outline" as-child
                ><Link :href="index({ query: { scope } })"
                    >Clear filters</Link
                ></Button
            >
        </EmptyState>
        <EmptyState
            v-else-if="tickets.data.length === 0"
            :icon="Inbox"
            :title="
                scope === 'active'
                    ? 'No active tickets'
                    : 'No resolved tickets yet'
            "
            description="New tickets appear here as soon as they are created."
        >
            <Button v-if="scope === 'active'" as-child
                ><Link :href="create()">New ticket</Link></Button
            >
        </EmptyState>

        <template v-else>
            <div class="overflow-x-auto rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Ticket</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Category</TableHead
                            >
                            <TableHead>Status</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Priority</TableHead
                            >
                            <TableHead>SLA</TableHead>
                            <TableHead
                                v-if="showCustomer"
                                class="hidden lg:table-cell"
                                >Customer</TableHead
                            >
                            <TableHead class="hidden md:table-cell"
                                >Agent</TableHead
                            >
                            <TableHead class="hidden lg:table-cell"
                                >Created</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="ticket in tickets.data"
                            :key="ticket.number"
                        >
                            <TableCell class="max-w-[16rem] sm:max-w-md">
                                <Link
                                    :href="show(ticket.number)"
                                    class="group block min-w-0"
                                >
                                    <span
                                        class="block font-mono text-xs text-muted-foreground"
                                        >{{ ticket.number }}</span
                                    >
                                    <span
                                        class="block truncate font-medium group-hover:underline"
                                        >{{ ticket.subject }}</span
                                    >
                                </Link>
                            </TableCell>
                            <TableCell class="hidden md:table-cell">{{
                                ticket.category?.name ?? '—'
                            }}</TableCell>
                            <TableCell
                                ><StatusBadge :status="ticket.status"
                            /></TableCell>
                            <TableCell class="hidden md:table-cell"
                                ><PriorityBadge :priority="ticket.priority"
                            /></TableCell>
                            <TableCell
                                ><SlaBadge :sla="ticket.sla"
                            /></TableCell>
                            <TableCell
                                v-if="showCustomer"
                                class="hidden lg:table-cell"
                                >{{ ticket.customer?.name ?? '—' }}</TableCell
                            >
                            <TableCell class="hidden md:table-cell">
                                <span
                                    :class="
                                        ticket.assigned_agent
                                            ? ''
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        ticket.assigned_agent?.name ??
                                        'Unassigned'
                                    }}</span
                                >
                            </TableCell>
                            <TableCell
                                class="hidden lg:table-cell"
                                :title="formatDateTime(ticket.created_at)"
                                >{{ formatDate(ticket.created_at) }}</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <ResourcePagination
                :links="tickets.links"
                :meta="tickets.meta"
                noun="tickets"
            />
        </template>
    </div>
</template>
