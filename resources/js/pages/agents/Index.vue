<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    MessageSquareWarning,
    MousePointerClick,
    ShieldAlert,
    UserCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AgentInbox from '@/components/agents/AgentInbox.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import { realtimeChannels, useRealtimeReload } from '@/composables/useRealtime';
import { index } from '@/routes/agents';
import { index as ticketsIndex } from '@/routes/tickets';
import type {
    AgentDirectoryFilters,
    AgentDirectoryList,
    AgentDirectoryOptions,
    AgentDirectorySummary,
} from '@/types/agents';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Agents', href: index() }],
    },
});

const props = defineProps<{
    agents: AgentDirectoryList;
    summary: AgentDirectorySummary;
    filters: AgentDirectoryFilters;
    options: AgentDirectoryOptions;
    listPages: number;
    resolvedWindowDays: number;
}>();

useRealtimeReload(
    () => [realtimeChannels.queue()],
    ['ticket.changed', 'queue.changed'],
    () => ({ only: ['agents', 'summary'] }),
    1500,
);

const tiles = computed(() => [
    {
        label: 'Agents',
        value: props.summary.agents,
        href: index(),
        icon: Users,
        tone: 'default' as const,
    },
    {
        label: 'Available now',
        value: props.summary.available,
        href: index({ query: { availability: 'available' } }),
        icon: UserCheck,
        tone: 'success' as const,
    },
    {
        label: 'Unresponded',
        value: props.summary.unresponded,
        href: index({ query: { sort: '-unresponded' } }),
        icon: MessageSquareWarning,
        tone: 'warning' as const,
    },
    {
        label: 'SLA breached',
        value: props.summary.breached,
        href: ticketsIndex({
            query: { scope: 'active', sla_state: 'breached' },
        }),
        icon: ShieldAlert,
        tone: 'danger' as const,
    },
]);
</script>

<template>
    <Head title="Agents" />

    <AgentInbox
        :agents="agents"
        :filters="filters"
        :options="options"
        :list-pages="listPages"
    >
        <div
            class="flex min-h-full flex-col gap-6 rounded-xl border bg-surface p-4 shadow-xs md:p-6"
        >
            <PageHeader
                title="Team overview"
                description="Who is on the team and how their queues look right now."
            />

            <section
                aria-label="Team summary"
                class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4"
            >
                <StatCard
                    v-for="tile in tiles"
                    :key="tile.label"
                    :label="tile.label"
                    :value="tile.value"
                    :href="tile.href"
                    :icon="tile.icon"
                    :tone="tile.tone"
                />
            </section>

            <div
                class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-8 text-center"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <MousePointerClick class="size-5" aria-hidden="true" />
                </span>
                <p class="font-medium">Select an agent to see their tickets</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    The counts next to each name show active tickets out of
                    everything assigned. A red dot marks SLA breaches, an amber
                    dot marks tickets still waiting for a first reply.
                </p>
            </div>
        </div>
    </AgentInbox>
</template>
