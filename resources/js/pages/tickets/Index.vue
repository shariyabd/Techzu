<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Inbox, Plus, SearchX } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import TicketFilterBar from '@/components/tickets/TicketFilterBar.vue';
import TicketQueuePanel from '@/components/tickets/TicketQueuePanel.vue';
import TicketRow from '@/components/tickets/TicketRow.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/tickets';
import type {
    Paginated,
    TicketFilterOptions,
    TicketListItem,
    TicketQueues,
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
    queues: TicketQueues;
}>();

const page = usePage();
const errors = computed(
    () => page.props.errors as Partial<Record<string, string>>,
);

const isFiltered = computed(() =>
    Object.keys(props.filters).some(
        (key) =>
            ![
                'scope',
                'page',
                'per_page',
                'unassigned',
                'assigned_agent_id',
                'category_id',
            ].includes(key),
    ),
);
const showCustomer = computed(() => props.filterOptions.showPeopleFilters);
const queueKey = computed(() =>
    JSON.stringify([
        props.scope,
        props.filters.unassigned ?? null,
        props.filters.assigned_agent_id ?? null,
        props.filters.category_id ?? null,
    ]),
);
</script>

<template>
    <Head title="Tickets" />

    <div class="flex min-h-full w-full flex-col md:flex-row">
        <TicketQueuePanel
            :queues="queues"
            :scope="scope"
            :filters="filters"
            :options="filterOptions"
        />

        <div class="flex min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">
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

            <TicketFilterBar
                :key="queueKey"
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
                <ul class="flex flex-col gap-2">
                    <li v-for="ticket in tickets.data" :key="ticket.number">
                        <TicketRow
                            :ticket="ticket"
                            :show-customer="showCustomer"
                        />
                    </li>
                </ul>
                <ResourcePagination
                    :links="tickets.links"
                    :meta="tickets.meta"
                    noun="tickets"
                />
            </template>
        </div>
    </div>
</template>
