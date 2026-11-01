<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ListFilter, Search, Users } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import AgentListItem from '@/components/agents/AgentListItem.vue';
import { agentListQuery, DEFAULT_AGENT_TAB } from '@/components/agents/links';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { usePresenceClock } from '@/composables/usePresence';
import { index, show } from '@/routes/agents';
import type {
    AgentDirectoryFilters,
    AgentDirectoryList,
    AgentDirectoryOptions,
    AgentTab,
} from '@/types/agents';

const props = defineProps<{
    agents: AgentDirectoryList;
    filters: AgentDirectoryFilters;
    options: AgentDirectoryOptions;
    listPages: number;
    selectedId: number | null;
    tab: AgentTab | null;
}>();

const now = usePresenceClock();
const search = ref(props.filters.q ?? '');
const loadingMore = ref(false);

watch(
    () => props.filters.q,
    (value) => {
        if ((value ?? '') !== search.value.trim()) {
            search.value = value ?? '';
        }
    },
);

const tabQuery = computed((): Record<string, string> =>
    props.tab && props.tab !== DEFAULT_AGENT_TAB ? { tab: props.tab } : {},
);

const listQuery = computed(() =>
    agentListQuery(props.filters, props.listPages),
);

const showRole = computed(() => props.filters.role !== 'agent');

const activeFilterCount = computed(
    () =>
        [
            props.filters.role !== 'agent',
            props.filters.availability !== null,
            props.filters.category_id !== null,
            props.filters.sort !== 'name',
        ].filter(Boolean).length,
);

const categoryLabel = computed(
    () =>
        props.options.categories.find(
            (category) => category.id === props.filters.category_id,
        )?.name ?? 'All categories',
);

const agentHref = (id: number) =>
    show(id, { query: { ...listQuery.value, ...tabQuery.value } });

function applyFilters(changes: Partial<AgentDirectoryFilters>): void {
    const query = {
        ...agentListQuery({
            ...props.filters,
            q: search.value,
            ...changes,
        }),
        ...tabQuery.value,
    };

    router.get(
        props.selectedId === null ? index.url() : show.url(props.selectedId),
        query,
        {
            only: ['agents', 'filters', 'listPages'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watchDebounced(
    search,
    (value) => {
        if (value.trim() !== (props.filters.q ?? '')) {
            applyFilters({});
        }
    },
    { debounce: 350 },
);

function resetFilters(): void {
    applyFilters({
        role: 'agent',
        availability: null,
        category_id: null,
        sort: 'name',
    });
}

function loadMore(): void {
    router.reload({
        data: { list_pages: props.listPages + 1 },
        only: ['agents', 'listPages'],
        onStart: () => (loadingMore.value = true),
        onFinish: () => (loadingMore.value = false),
    });
}

const availabilityValue = computed(() => props.filters.availability ?? 'any');
const categoryValue = computed(() =>
    props.filters.category_id === null
        ? 'all'
        : String(props.filters.category_id),
);
</script>

<template>
    <section
        aria-label="Agents"
        class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border bg-surface shadow-xs lg:h-full"
    >
        <form
            class="flex items-center gap-2 border-b p-3"
            role="search"
            @submit.prevent="applyFilters({})"
        >
            <div class="relative min-w-0 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search agents"
                    aria-label="Search agents by name or email"
                    class="h-8 bg-background pl-8"
                />
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="relative size-8 shrink-0"
                        :aria-label="
                            activeFilterCount > 0
                                ? `Filters (${activeFilterCount} active)`
                                : 'Filters'
                        "
                    >
                        <ListFilter aria-hidden="true" />
                        <span
                            v-if="activeFilterCount > 0"
                            class="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground"
                            aria-hidden="true"
                            >{{ activeFilterCount }}</span
                        >
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuLabel>Show</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        :model-value="filters.role"
                        @update:model-value="
                            (value) =>
                                applyFilters({
                                    role: value as AgentDirectoryFilters['role'],
                                })
                        "
                    >
                        <DropdownMenuRadioItem
                            v-for="option in options.roles"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</DropdownMenuRadioItem
                        >
                    </DropdownMenuRadioGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel>Availability</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        :model-value="availabilityValue"
                        @update:model-value="
                            (value) =>
                                applyFilters({
                                    availability:
                                        value === 'any'
                                            ? null
                                            : (value as AgentDirectoryFilters['availability']),
                                })
                        "
                    >
                        <DropdownMenuRadioItem value="any"
                            >Any availability</DropdownMenuRadioItem
                        >
                        <DropdownMenuRadioItem
                            v-for="option in options.availability"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</DropdownMenuRadioItem
                        >
                    </DropdownMenuRadioGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <span class="flex min-w-0 flex-col">
                                <span class="text-xs text-muted-foreground"
                                    >Category</span
                                >
                                <span class="truncate">{{
                                    categoryLabel
                                }}</span>
                            </span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent
                            class="max-h-72 w-56 overflow-y-auto"
                        >
                            <DropdownMenuRadioGroup
                                :model-value="categoryValue"
                                @update:model-value="
                                    (value) =>
                                        applyFilters({
                                            category_id:
                                                value === 'all'
                                                    ? null
                                                    : Number(value),
                                        })
                                "
                            >
                                <DropdownMenuRadioItem value="all"
                                    >All categories</DropdownMenuRadioItem
                                >
                                <DropdownMenuRadioItem
                                    v-for="category in options.categories"
                                    :key="category.id"
                                    :value="String(category.id)"
                                    >{{ category.name }}</DropdownMenuRadioItem
                                >
                            </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <span class="flex min-w-0 flex-col">
                                <span class="text-xs text-muted-foreground"
                                    >Sort</span
                                >
                                <span class="truncate">{{
                                    options.sorts.find(
                                        (option) =>
                                            option.value === filters.sort,
                                    )?.label
                                }}</span>
                            </span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent class="w-56">
                            <DropdownMenuRadioGroup
                                :model-value="filters.sort"
                                @update:model-value="
                                    (value) =>
                                        applyFilters({
                                            sort: value as AgentDirectoryFilters['sort'],
                                        })
                                "
                            >
                                <DropdownMenuRadioItem
                                    v-for="option in options.sorts"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ option.label }}</DropdownMenuRadioItem
                                >
                            </DropdownMenuRadioGroup>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <template v-if="activeFilterCount > 0">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="resetFilters"
                            >Reset filters</DropdownMenuItem
                        >
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </form>

        <div
            class="flex items-center justify-between gap-2 px-4 pt-3 pb-1 text-xs text-muted-foreground"
        >
            <span class="font-semibold tracking-wide uppercase">{{
                options.roles.find((option) => option.value === filters.role)
                    ?.label ?? 'Agents'
            }}</span>
            <span class="tabular-nums">{{ agents.meta.total }} total</span>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2">
            <div
                v-if="agents.data.length === 0"
                class="flex flex-col items-center gap-2 px-4 py-10 text-center text-sm text-muted-foreground"
            >
                <Users class="size-6" aria-hidden="true" />
                <p class="font-medium text-foreground">No agents found</p>
                <p>Try another name, email or filter.</p>
                <Button
                    v-if="activeFilterCount > 0 || (filters.q ?? '') !== ''"
                    variant="outline"
                    size="sm"
                    @click="
                        search = '';
                        resetFilters();
                    "
                    >Clear filters</Button
                >
            </div>
            <ul v-else class="flex flex-col gap-0.5">
                <li v-for="agent in agents.data" :key="agent.id">
                    <AgentListItem
                        :agent="agent"
                        :href="agentHref(agent.id)"
                        :selected="agent.id === selectedId"
                        :now="now"
                        :show-role="showRole"
                    />
                </li>
            </ul>
            <div v-if="agents.has_more" class="p-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="w-full"
                    :disabled="loadingMore"
                    @click="loadMore"
                    >{{ loadingMore ? 'Loading…' : 'Load more' }}</Button
                >
            </div>
            <p
                v-else-if="agents.data.length < agents.meta.total"
                class="px-3 py-2 text-center text-xs text-muted-foreground"
            >
                Showing {{ agents.data.length }} of {{ agents.meta.total }}.
                Refine the search to find the rest.
            </p>
        </div>
    </section>
</template>
