<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Inbox, Mail, Pencil } from '@lucide/vue';
import { computed } from 'vue';
import AgentInbox from '@/components/agents/AgentInbox.vue';
import AgentTicketRow from '@/components/agents/AgentTicketRow.vue';
import AvailabilityBadge from '@/components/agents/AvailabilityBadge.vue';
import type { AgentTabTone } from '@/components/agents/links';
import {
    agentListQuery,
    agentTabs,
    DEFAULT_AGENT_TAB,
} from '@/components/agents/links';
import EmptyState from '@/components/EmptyState.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';
import { presenceStatus, usePresenceClock } from '@/composables/usePresence';
import { realtimeChannels, useRealtimeReload } from '@/composables/useRealtime';
import { formatDateTime } from '@/lib/format';
import { index, show } from '@/routes/agents';
import { edit } from '@/routes/admin/users';
import type { BreadcrumbItem } from '@/types';
import type {
    AgentDirectoryFilters,
    AgentDirectoryList,
    AgentDirectoryOptions,
    AgentTab,
    DirectoryAgent,
} from '@/types/agents';
import type { Paginated, TicketListItem } from '@/types/tickets';

const props = defineProps<{
    agents: AgentDirectoryList;
    filters: AgentDirectoryFilters;
    options: AgentDirectoryOptions;
    listPages: number;
    agent: DirectoryAgent;
    tab: AgentTab;
    tickets: Paginated<TicketListItem>;
    resolvedWindowDays: number;
}>();

defineOptions({
    layout: (pageProps: { agent: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Agents', href: index() },
            { title: pageProps.agent.name, href: show(pageProps.agent.id) },
        ] satisfies BreadcrumbItem[],
    }),
});

const page = usePage();
const canManage = computed(() => page.props.auth.can.manageUsers);
const now = usePresenceClock();

const presence = computed(() =>
    presenceStatus(props.agent.last_seen_at, false, now.value),
);

useRealtimeReload(
    () => [realtimeChannels.queue()],
    ['ticket.changed', 'queue.changed'],
    () => ({ only: ['agents', 'agent', 'tickets'] }),
    1500,
);

const listQuery = computed(() =>
    agentListQuery(props.filters, props.listPages),
);

const backHref = computed(() => index({ query: listQuery.value }));

const tabs = computed(() =>
    agentTabs(props.resolvedWindowDays).map((definition) => ({
        ...definition,
        count: props.agent.stats[definition.stat],
        href: show(props.agent.id, {
            query: {
                ...listQuery.value,
                ...(definition.key === DEFAULT_AGENT_TAB
                    ? {}
                    : { tab: definition.key }),
            },
        }),
    })),
);

const currentTab = computed(
    () => tabs.value.find((item) => item.key === props.tab) ?? tabs.value[0],
);

const countTones: Record<AgentTabTone, string> = {
    default: 'bg-muted text-foreground',
    warning: 'bg-tint-amber text-tint-amber-foreground',
    danger: 'bg-destructive/10 text-destructive',
    success: 'bg-tint-green text-tint-green-foreground',
};

const emptyCopy: Record<AgentTab, string> = {
    active: 'No open tickets are assigned to this agent right now.',
    unresponded: 'Every active ticket has had a staff reply.',
    awaiting: 'No customer is waiting on a reply from this agent.',
    breached: 'No active ticket has passed its SLA deadline.',
    resolved: 'No tickets were resolved in this window.',
    all: 'No tickets have been assigned to this agent yet.',
};
</script>

<template>
    <Head :title="agent.name" />

    <AgentInbox
        :agents="agents"
        :filters="filters"
        :options="options"
        :list-pages="listPages"
        :selected-id="agent.id"
        :tab="tab"
    >
        <Link
            :href="backHref"
            class="inline-flex w-fit items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground lg:hidden"
            ><ArrowLeft class="size-4" aria-hidden="true" />Agents</Link
        >

        <section
            aria-label="Agent profile"
            class="flex flex-col gap-4 rounded-xl border bg-surface p-4 shadow-xs md:p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="flex min-w-0 flex-1 items-start gap-4">
                    <span class="relative inline-flex shrink-0">
                        <UserAvatar
                            :name="agent.name"
                            :src="agent.avatar_url"
                            class="size-16"
                        />
                        <span
                            v-if="presence.active"
                            class="absolute right-0.5 bottom-0.5 size-3.5 rounded-full bg-emerald-500 ring-2 ring-background"
                            aria-hidden="true"
                        />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <p
                            :class="[
                                'text-xs font-medium',
                                presence.active
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-muted-foreground',
                            ]"
                            :title="
                                agent.last_seen_at
                                    ? formatDateTime(agent.last_seen_at)
                                    : undefined
                            "
                            data-presence-label
                        >
                            {{ presence.label }}
                        </p>
                        <h1
                            class="text-xl font-semibold tracking-tight break-words"
                        >
                            {{ agent.name }}
                        </h1>
                        <a
                            :href="`mailto:${agent.email}`"
                            class="inline-flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            ><Mail
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            /><span class="truncate">{{ agent.email }}</span></a
                        >
                        <div
                            class="flex flex-wrap items-center gap-1.5 pt-1 text-xs"
                        >
                            <AvailabilityBadge
                                :available="agent.is_available"
                            />
                            <Badge
                                v-if="agent.role === 'admin'"
                                variant="secondary"
                                >Admin</Badge
                            >
                            <span
                                v-for="category in agent.categories ?? []"
                                :key="category.id"
                                class="rounded bg-muted px-1.5 py-0.5 text-muted-foreground"
                                >{{ category.name }}</span
                            >
                        </div>
                    </div>
                </div>
                <Button
                    v-if="canManage"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    as-child
                >
                    <Link :href="edit(agent.id)"
                        ><Pencil aria-hidden="true" />Edit profile</Link
                    >
                </Button>
            </div>

            <nav
                aria-label="Ticket views"
                class="-mx-1 flex flex-wrap gap-1.5 px-1"
            >
                <Link
                    v-for="item in tabs"
                    :key="item.key"
                    :href="item.href"
                    :only="['tab', 'tickets', 'agent']"
                    preserve-scroll
                    preserve-state
                    :aria-current="item.key === tab ? 'page' : undefined"
                    :data-tab="item.key"
                    :class="[
                        'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        item.key === tab
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-background hover:bg-muted',
                    ]"
                >
                    <span>{{ item.label }}</span>
                    <span
                        :class="[
                            'min-w-5 rounded-full px-1.5 text-center text-xs font-semibold tabular-nums',
                            item.key === tab
                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                : item.count > 0
                                  ? countTones[item.tone]
                                  : 'bg-muted text-muted-foreground',
                        ]"
                        >{{ item.count }}</span
                    >
                </Link>
            </nav>
        </section>

        <section
            aria-labelledby="agent-tickets"
            class="@container flex flex-col gap-3"
        >
            <h2 id="agent-tickets" class="sr-only">
                {{ currentTab?.label }} tickets
            </h2>

            <EmptyState
                v-if="tickets.data.length === 0"
                :icon="Inbox"
                :title="`No ${currentTab?.label.toLowerCase()} tickets`"
                :description="emptyCopy[tab]"
            />

            <template v-else>
                <div
                    class="hidden grid-cols-[minmax(0,12rem)_8.5rem_minmax(0,1fr)_minmax(0,9.5rem)_2rem] gap-x-4 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase @2xl:grid"
                    aria-hidden="true"
                >
                    <span>Clients</span>
                    <span>Ticket</span>
                    <span>Conversation</span>
                    <span>Status</span>
                    <span />
                </div>
                <ul class="flex flex-col gap-2">
                    <li v-for="ticket in tickets.data" :key="ticket.number">
                        <AgentTicketRow :ticket="ticket" :now="now" />
                    </li>
                </ul>
                <ResourcePagination
                    :links="tickets.links"
                    :meta="tickets.meta"
                    :only="['tickets']"
                    noun="tickets"
                />
            </template>
        </section>
    </AgentInbox>
</template>
