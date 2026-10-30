<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { index } from '@/routes/tickets';
import type {
    QueueCount,
    TicketFilterOptions,
    TicketQueues,
    TicketScope,
} from '@/types/tickets';

type QueueItem = {
    key: string;
    label: string;
    counts: QueueCount;
    query: Record<string, string>;
};

const props = defineProps<{
    queues: TicketQueues;
    scope: TicketScope;
    filters: Record<string, unknown>;
    options: Pick<TicketFilterOptions, 'categories'>;
}>();

const page = usePage();
const viewerId = computed(() => page.props.auth.user.id);

const items = computed<QueueItem[]>(() => {
    const list: QueueItem[] = [
        {
            key: 'active',
            label: 'All active',
            counts: props.queues.active,
            query: { scope: 'active' },
        },
    ];

    if (props.queues.assigned_to_me) {
        list.push({
            key: 'mine',
            label: 'Assigned to me',
            counts: props.queues.assigned_to_me,
            query: {
                scope: 'active',
                assigned_agent_id: String(viewerId.value),
            },
        });
    }

    if (props.queues.unassigned) {
        list.push({
            key: 'unassigned',
            label: 'Unassigned',
            counts: props.queues.unassigned,
            query: { scope: 'active', unassigned: '1' },
        });
    }

    list.push({
        key: 'resolved',
        label: 'Resolved & closed',
        counts: props.queues.resolved,
        query: { scope: 'resolved' },
    });

    return list;
});

const categoryItems = computed<QueueItem[]>(() =>
    props.queues.categories.flatMap((entry) => {
        const category = props.options.categories.find(
            (candidate) => candidate.id === entry.category_id,
        );

        return category
            ? [
                  {
                      key: `category-${category.id}`,
                      label: category.name,
                      counts: { open: entry.open, total: entry.total },
                      query: {
                          scope: 'active',
                          category_id: String(category.id),
                      },
                  },
              ]
            : [];
    }),
);

const allItems = computed(() => [...items.value, ...categoryItems.value]);

const activeKey = computed(() => {
    const filters = props.filters;

    if (props.scope === 'resolved') {
        return 'resolved';
    }

    if (filters.unassigned === '1' || filters.unassigned === true) {
        return 'unassigned';
    }

    if (
        filters.assigned_agent_id !== undefined &&
        String(filters.assigned_agent_id) === String(viewerId.value)
    ) {
        return 'mine';
    }

    if (filters.category_id !== undefined) {
        return `category-${String(filters.category_id)}`;
    }

    return 'active';
});

function visit(key?: unknown): void {
    const item = allItems.value.find((candidate) => candidate.key === key);

    if (item) {
        router.get(index.url({ query: item.query }));
    }
}

const linkClass = (active: boolean): string =>
    [
        'flex items-center justify-between gap-2 rounded-md px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
        active
            ? 'bg-primary/10 font-medium text-primary'
            : 'text-foreground hover:bg-accent',
    ].join(' ');
</script>

<template>
    <div class="px-4 pt-4 md:hidden">
        <label for="queue-select" class="sr-only">Ticket queue</label>
        <NativeSelect
            id="queue-select"
            :model-value="activeKey"
            @update:model-value="visit"
        >
            <NativeSelectOption
                v-for="item in allItems"
                :key="item.key"
                :value="item.key"
                >{{ item.label }} ({{ item.counts.open }} /
                {{ item.counts.total }})</NativeSelectOption
            >
        </NativeSelect>
    </div>

    <aside
        aria-label="Ticket queues"
        class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-60 shrink-0 self-start overflow-y-auto border-r bg-surface p-3 md:block"
    >
        <nav class="flex flex-col gap-1">
            <Link
                v-for="item in items"
                :key="item.key"
                :href="index({ query: item.query })"
                :aria-current="activeKey === item.key ? 'page' : undefined"
                :class="linkClass(activeKey === item.key)"
            >
                <span class="truncate">{{ item.label }}</span>
                <span class="text-xs text-muted-foreground tabular-nums"
                    ><span class="font-semibold text-foreground">{{
                        item.counts.open
                    }}</span>
                    / {{ item.counts.total }}</span
                >
            </Link>
        </nav>

        <div v-if="categoryItems.length > 0" class="mt-4">
            <h2
                class="px-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Categories
            </h2>
            <nav class="flex flex-col gap-1" aria-label="Categories">
                <Link
                    v-for="item in categoryItems"
                    :key="item.key"
                    :href="index({ query: item.query })"
                    :aria-current="activeKey === item.key ? 'page' : undefined"
                    :class="linkClass(activeKey === item.key)"
                >
                    <span class="truncate">{{ item.label }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums"
                        ><span class="font-semibold text-foreground">{{
                            item.counts.open
                        }}</span>
                        / {{ item.counts.total }}</span
                    >
                </Link>
            </nav>
        </div>
    </aside>
</template>
