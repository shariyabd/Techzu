<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    AlarmClock,
    CheckCircle2,
    CircleDot,
    Flame,
    Inbox,
    ShieldAlert,
    UserX,
} from '@lucide/vue';
import { computed } from 'vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { index as ticketsIndex, show as ticketShow } from '@/routes/tickets';
import type { DashboardStats } from '@/types/dashboard';
import type { TicketListItem, TicketPriorityValue } from '@/types/tickets';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const props = defineProps<{
    stats: DashboardStats;
    resolvedWindowDays: number;
    myTickets: TicketListItem[];
}>();

usePoll(60_000, { only: ['stats', 'myTickets'] });

const active = (query: Record<string, string | string[]> = {}) =>
    ticketsIndex({ query: { scope: 'active', ...query } });

const cards = computed(() => [
    {
        label: 'Open',
        value: props.stats.totals.open,
        href: active(),
        icon: CircleDot,
        tone: 'default' as const,
    },
    {
        label: 'Unassigned',
        value: props.stats.totals.unassigned,
        href: active({ unassigned: '1' }),
        icon: UserX,
        tone: 'warning' as const,
    },
    {
        label: 'Critical',
        value: props.stats.totals.critical,
        href: active({ priority: ['critical'] }),
        icon: Flame,
        tone: 'danger' as const,
    },
    {
        label: 'SLA breached',
        value: props.stats.totals.breached,
        href: active({ sla_state: 'breached' }),
        icon: ShieldAlert,
        tone: 'danger' as const,
    },
    {
        label: 'Approaching SLA',
        value: props.stats.totals.approaching,
        href: active({ sla_state: 'approaching' }),
        icon: AlarmClock,
        tone: 'warning' as const,
    },
    {
        label: `Resolved (${props.resolvedWindowDays} days)`,
        value: props.stats.totals.resolved,
        href: ticketsIndex({ query: { scope: 'resolved' } }),
        icon: CheckCircle2,
        tone: 'success' as const,
    },
]);

const priorityLabels: Record<TicketPriorityValue, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

const totalActive = computed(() =>
    props.stats.by_priority.reduce((sum, row) => sum + row.count, 0),
);

const share = (count: number): number =>
    totalActive.value === 0 ? 0 : Math.round((count / totalActive.value) * 100);
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Dashboard"
            description="Live ticket workload. Figures refresh every minute."
        />

        <section
            aria-label="Totals"
            class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
        >
            <StatCard v-for="card in cards" :key="card.label" v-bind="card" />
        </section>

        <section aria-labelledby="my-tickets" class="flex flex-col gap-3">
            <h2 id="my-tickets" class="text-base font-semibold">My tickets</h2>
            <EmptyState
                v-if="myTickets.length === 0"
                :icon="Inbox"
                title="Nothing assigned to you"
                description="Claim a ticket from the unassigned queue to start working on it."
            />
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="ticket in myTickets"
                    :key="ticket.number"
                    class="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3 shadow-xs sm:flex-row sm:items-center sm:justify-between"
                >
                    <Link
                        :href="ticketShow(ticket.number)"
                        class="min-w-0 underline-offset-4 hover:underline"
                    >
                        <span class="font-mono text-xs text-muted-foreground">{{
                            ticket.number
                        }}</span>
                        <span class="ml-2 font-medium break-words">{{
                            ticket.subject
                        }}</span>
                    </Link>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <StatusBadge :status="ticket.status" />
                        <PriorityBadge :priority="ticket.priority" />
                        <SlaBadge :sla="ticket.sla" />
                    </div>
                </li>
            </ul>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader
                    ><CardTitle class="text-base"
                        >Active tickets by priority</CardTitle
                    ></CardHeader
                >
                <CardContent>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="row in stats.by_priority"
                            :key="row.priority"
                            class="grid gap-1"
                        >
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <Link
                                    :href="active({ priority: [row.priority] })"
                                    class="underline-offset-4 hover:underline"
                                    >{{ priorityLabels[row.priority] }}</Link
                                >
                                <span class="text-muted-foreground tabular-nums"
                                    >{{ row.count }} ·
                                    {{ share(row.count) }}%</span
                                >
                            </div>
                            <Progress
                                :model-value="share(row.count)"
                                :aria-label="`${priorityLabels[row.priority]}: ${share(row.count)}% of active tickets`"
                            />
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle class="text-base"
                        >Active tickets by agent</CardTitle
                    ></CardHeader
                >
                <CardContent>
                    <EmptyState
                        v-if="stats.by_agent.length === 0"
                        title="No agents yet"
                        description="Agents appear here once an admin creates them."
                    />
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Agent</TableHead>
                                <TableHead class="text-right">Active</TableHead>
                                <TableHead class="text-right"
                                    >Breached</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in stats.by_agent"
                                :key="row.agent_id"
                            >
                                <TableCell>
                                    <Link
                                        :href="
                                            active({
                                                assigned_agent_id: String(
                                                    row.agent_id,
                                                ),
                                            })
                                        "
                                        class="underline-offset-4 hover:underline"
                                        >{{ row.name }}</Link
                                    >
                                </TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    row.active
                                }}</TableCell>
                                <TableCell
                                    class="text-right tabular-nums"
                                    :class="
                                        row.breached > 0
                                            ? 'font-semibold text-red-600 dark:text-red-400'
                                            : 'text-muted-foreground'
                                    "
                                    >{{ row.breached }}</TableCell
                                >
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
