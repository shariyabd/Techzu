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
import AgentWorkload from '@/components/dashboard/AgentWorkload.vue';
import BarList from '@/components/dashboard/BarList.vue';
import type { ChartSegment } from '@/components/dashboard/chart';
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue';
import DonutChart from '@/components/dashboard/DonutChart.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import TrendChart from '@/components/dashboard/TrendChart.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import { realtimeChannels, useRealtimeReload } from '@/composables/useRealtime';
import { dashboard } from '@/routes';
import { index as ticketsIndex, show as ticketShow } from '@/routes/tickets';
import type { AgentLoad, DashboardStats, SlaHealth } from '@/types/dashboard';
import type {
    TicketListItem,
    TicketPriorityValue,
    TicketStatusValue,
} from '@/types/tickets';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const props = defineProps<{
    stats: DashboardStats;
    resolvedWindowDays: number;
    myTickets: TicketListItem[];
}>();

usePoll(60_000, { only: ['stats', 'myTickets'] });

useRealtimeReload(
    () => [realtimeChannels.queue()],
    ['ticket.changed', 'queue.changed'],
    () => ({ only: ['stats', 'myTickets'] }),
    1500,
);

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

const slaStyles: Record<
    keyof SlaHealth,
    { label: string; strokeClass: string; fillClass: string }
> = {
    within: {
        label: 'Within SLA',
        strokeClass: 'stroke-emerald-500 dark:stroke-emerald-400',
        fillClass: 'bg-emerald-500 dark:bg-emerald-400',
    },
    approaching: {
        label: 'Approaching',
        strokeClass: 'stroke-amber-500 dark:stroke-amber-400',
        fillClass: 'bg-amber-500 dark:bg-amber-400',
    },
    breached: {
        label: 'Breached',
        strokeClass: 'stroke-red-600 dark:stroke-red-500',
        fillClass: 'bg-red-600 dark:bg-red-500',
    },
    paused: {
        label: 'Paused',
        strokeClass: 'stroke-slate-400 dark:stroke-slate-500',
        fillClass: 'bg-slate-400 dark:bg-slate-500',
    },
};

const slaSegments = computed<ChartSegment[]>(() =>
    (Object.keys(slaStyles) as (keyof SlaHealth)[]).map((key) => ({
        key,
        value: props.stats.sla_health[key],
        href: active({ sla_state: key }),
        ...slaStyles[key],
    })),
);

const statusStyles: Partial<
    Record<
        TicketStatusValue,
        { label: string; strokeClass: string; fillClass: string }
    >
> = {
    open: {
        label: 'Open',
        strokeClass: 'stroke-sky-500 dark:stroke-sky-400',
        fillClass: 'bg-sky-500 dark:bg-sky-400',
    },
    in_progress: {
        label: 'In progress',
        strokeClass: 'stroke-indigo-500 dark:stroke-indigo-400',
        fillClass: 'bg-indigo-500 dark:bg-indigo-400',
    },
    waiting_for_customer: {
        label: 'Waiting for customer',
        strokeClass: 'stroke-amber-500 dark:stroke-amber-400',
        fillClass: 'bg-amber-500 dark:bg-amber-400',
    },
};

const statusSegments = computed<ChartSegment[]>(() =>
    props.stats.by_status.map((row) => ({
        key: row.status,
        value: row.count,
        href: active({ status: [row.status] }),
        label: statusStyles[row.status]?.label ?? row.status,
        strokeClass: statusStyles[row.status]?.strokeClass ?? 'stroke-primary',
        fillClass: statusStyles[row.status]?.fillClass ?? 'bg-primary',
    })),
);

const priorityStyles: Record<
    TicketPriorityValue,
    { label: string; fillClass: string }
> = {
    critical: { label: 'Critical', fillClass: 'bg-red-600 dark:bg-red-500' },
    high: { label: 'High', fillClass: 'bg-orange-500 dark:bg-orange-400' },
    medium: { label: 'Medium', fillClass: 'bg-slate-500 dark:bg-slate-400' },
    low: { label: 'Low', fillClass: 'bg-slate-300 dark:bg-slate-600' },
};

const prioritySegments = computed<ChartSegment[]>(() =>
    props.stats.by_priority.map((row) => ({
        key: row.priority,
        value: row.count,
        href: active({ priority: [row.priority] }),
        label: priorityStyles[row.priority].label,
        fillClass: priorityStyles[row.priority].fillClass,
        strokeClass: '',
    })),
);

const agentHref = (agent: AgentLoad) =>
    active({ assigned_agent_id: String(agent.agent_id) });
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 2xl:px-8">
        <PageHeader
            title="Dashboard"
            description="Live ticket workload. Figures refresh every minute."
        />

        <section
            aria-label="Totals"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
        >
            <StatCard v-for="card in cards" :key="card.label" v-bind="card" />
        </section>

        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-12">
            <DashboardPanel
                title="Created vs resolved"
                :description="`Tickets per day over the last ${stats.trend.length} days`"
                title-id="trend-title"
                class="md:col-span-2 xl:col-span-8"
            >
                <TrendChart :points="stats.trend" />
            </DashboardPanel>

            <DashboardPanel
                title="SLA health"
                description="Active tickets by SLA state"
                title-id="sla-title"
                class="xl:col-span-4"
            >
                <DonutChart
                    :segments="slaSegments"
                    label="SLA health"
                    total-label="active tickets"
                />
            </DashboardPanel>

            <DashboardPanel
                title="Active by status"
                title-id="status-title"
                class="xl:col-span-4"
            >
                <DonutChart
                    :segments="statusSegments"
                    label="Active tickets by status"
                    total-label="active tickets"
                />
            </DashboardPanel>

            <DashboardPanel
                title="Active by priority"
                title-id="priority-title"
                class="xl:col-span-4"
            >
                <BarList :items="prioritySegments" noun="active tickets" />
            </DashboardPanel>

            <DashboardPanel
                title="My tickets"
                description="Assigned to you, soonest deadline first"
                title-id="my-tickets"
                class="xl:col-span-4"
            >
                <EmptyState
                    v-if="myTickets.length === 0"
                    :icon="Inbox"
                    title="Nothing assigned to you"
                    description="Claim a ticket from the unassigned queue to start working on it."
                />
                <ul v-else class="-mx-2 flex flex-col">
                    <li
                        v-for="ticket in myTickets"
                        :key="ticket.number"
                        class="relative flex flex-col gap-1.5 rounded-md px-2 py-2.5 hover:bg-muted [&:not(:last-child)]:border-b"
                    >
                        <Link
                            :href="ticketShow(ticket.number)"
                            class="min-w-0 truncate text-sm outline-none after:absolute after:inset-0 after:rounded-md focus-visible:after:ring-2 focus-visible:after:ring-ring"
                        >
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ ticket.number }}</span
                            >
                            <span class="ml-2 font-medium">{{
                                ticket.subject
                            }}</span>
                        </Link>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusBadge :status="ticket.status" />
                            <PriorityBadge :priority="ticket.priority" />
                            <SlaBadge :sla="ticket.sla" />
                        </div>
                    </li>
                </ul>
            </DashboardPanel>

            <DashboardPanel
                title="Agent workload"
                description="Active tickets per agent; red marks breached SLAs"
                title-id="agents-title"
                class="md:col-span-2 xl:col-span-12"
            >
                <EmptyState
                    v-if="stats.by_agent.length === 0"
                    title="No agents yet"
                    description="Agents appear here once an admin creates them."
                />
                <AgentWorkload
                    v-else
                    :agents="stats.by_agent"
                    :href-for="agentHref"
                />
            </DashboardPanel>
        </div>
    </div>
</template>
