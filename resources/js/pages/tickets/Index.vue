<script setup lang="ts">
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
import type { Paginated, TicketListItem } from '@/types/tickets';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tickets', href: '/tickets' }],
    },
});

const props = defineProps<{
    tickets: Paginated<TicketListItem>;
    scope: 'active' | 'resolved';
    filters: Record<string, unknown>;
}>();

const formatDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString() : '';
</script>

<template>
    <Head title="Tickets" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex gap-2">
                <Button
                    as-child
                    size="sm"
                    :variant="props.scope === 'active' ? 'default' : 'outline'"
                >
                    <Link href="/tickets?scope=active">Active</Link>
                </Button>
                <Button
                    as-child
                    size="sm"
                    :variant="
                        props.scope === 'resolved' ? 'default' : 'outline'
                    "
                >
                    <Link href="/tickets?scope=resolved"
                        >Resolved &amp; closed</Link
                    >
                </Button>
            </div>
            <Button as-child size="sm">
                <Link href="/tickets/create">New ticket</Link>
            </Button>
        </div>

        <TicketFilterBar :scope="props.scope" :filters="props.filters" />

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Ticket</TableHead>
                    <TableHead>Subject</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Priority</TableHead>
                    <TableHead>SLA</TableHead>
                    <TableHead>Agent</TableHead>
                    <TableHead>Created</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-if="props.tickets.data.length === 0">
                    <TableCell
                        colspan="7"
                        class="text-center text-muted-foreground"
                        >No tickets found.</TableCell
                    >
                </TableRow>
                <TableRow
                    v-for="ticket in props.tickets.data"
                    :key="ticket.number"
                >
                    <TableCell class="font-mono text-xs">
                        <Link
                            :href="`/tickets/${ticket.number}`"
                            class="underline-offset-4 hover:underline"
                            >{{ ticket.number }}</Link
                        >
                    </TableCell>
                    <TableCell class="max-w-xs truncate">{{
                        ticket.subject
                    }}</TableCell>
                    <TableCell
                        ><StatusBadge :status="ticket.status"
                    /></TableCell>
                    <TableCell
                        ><PriorityBadge :priority="ticket.priority"
                    /></TableCell>
                    <TableCell><SlaBadge :sla="ticket.sla" /></TableCell>
                    <TableCell>{{
                        ticket.assigned_agent?.name ?? 'Unassigned'
                    }}</TableCell>
                    <TableCell>{{ formatDate(ticket.created_at) }}</TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">
                Page {{ props.tickets.meta.current_page }} of
                {{ props.tickets.meta.last_page }} ·
                {{ props.tickets.meta.total }} tickets
            </span>
            <div class="flex gap-2">
                <Button
                    v-if="props.tickets.links.prev"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="props.tickets.links.prev" preserve-scroll
                        >Previous</Link
                    >
                </Button>
                <Button
                    v-if="props.tickets.links.next"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="props.tickets.links.next" preserve-scroll
                        >Next</Link
                    >
                </Button>
            </div>
        </div>
    </div>
</template>
