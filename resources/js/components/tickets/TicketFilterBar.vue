<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RotateCcw, Search, SlidersHorizontal } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { index } from '@/routes/tickets';
import type { TicketFilterOptions, TicketScope } from '@/types/tickets';

const props = defineProps<{
    scope: TicketScope;
    filters: Record<string, unknown>;
    options: TicketFilterOptions;
    errors: Partial<Record<string, string>>;
}>();

const DEFAULT_SORT = '-created_at';

type FilterState = {
    number: string;
    q: string;
    status: string[];
    priority: string[];
    category_id: string;
    assigned_agent_id: string;
    unassigned: boolean;
    customer_id: string;
    created_from: string;
    created_to: string;
    sla_state: string;
    sort: string;
};

const ADVANCED: (keyof FilterState)[] = [
    'status',
    'priority',
    'category_id',
    'assigned_agent_id',
    'unassigned',
    'customer_id',
    'created_from',
    'created_to',
    'sla_state',
];
const SCALARS: (keyof FilterState)[] = [
    'number',
    'q',
    'category_id',
    'assigned_agent_id',
    'customer_id',
    'created_from',
    'created_to',
    'sla_state',
];

const text = (value: unknown): string =>
    typeof value === 'string' || typeof value === 'number' ? String(value) : '';
const list = (value: unknown): string[] =>
    Array.isArray(value)
        ? value.filter((v): v is string => typeof v === 'string')
        : [];
const flag = (value: unknown): boolean =>
    value === true || value === '1' || value === 1 || value === 'true';

function fromFilters(filters: Record<string, unknown>): FilterState {
    return {
        number: text(filters.number),
        q: text(filters.q),
        status: list(filters.status),
        priority: list(filters.priority),
        category_id: text(filters.category_id),
        assigned_agent_id: text(filters.assigned_agent_id),
        unassigned: flag(filters.unassigned),
        customer_id: text(filters.customer_id),
        created_from: text(filters.created_from),
        created_to: text(filters.created_to),
        sla_state: text(filters.sla_state),
        sort: text(filters.sort) || DEFAULT_SORT,
    };
}

const state = reactive<FilterState>(fromFilters(props.filters));

function toQuery(s: FilterState): Record<string, string | string[]> {
    const query: Record<string, string | string[]> = { scope: props.scope };

    for (const key of SCALARS) {
        const value = String(s[key]).trim();
        if (value !== '') {
            query[key] = value;
        }
    }
    if (s.status.length > 0) {
        query.status = [...s.status];
    }
    if (s.priority.length > 0) {
        query.priority = [...s.priority];
    }
    if (s.unassigned) {
        query.unassigned = '1';
    }
    if (s.sort !== DEFAULT_SORT) {
        query.sort = s.sort;
    }

    return query;
}

const activeAdvanced = computed(
    () => ADVANCED.filter((key) => key in toQuery(state)).length,
);
const hasFilters = computed(() =>
    Object.keys(toQuery(state)).some((key) => key !== 'scope'),
);
const showAdvanced = ref(activeAdvanced.value > 0);

let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    state,
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get(index.url(), toQuery(state), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);
    },
    { deep: true },
);

onBeforeUnmount(() => clearTimeout(timer));

function toggle(values: string[], value: string): void {
    const position = values.indexOf(value);
    if (position === -1) {
        values.push(value);
    } else {
        values.splice(position, 1);
    }
}

function setUnassigned(value: boolean | 'indeterminate'): void {
    state.unassigned = value === true;
    if (state.unassigned) {
        state.assigned_agent_id = '';
    }
}

function reset(): void {
    Object.assign(state, fromFilters({}));
}

const chipClass = (active: boolean): string =>
    [
        'h-8 rounded-full border px-3 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
        active
            ? 'border-primary bg-primary text-primary-foreground'
            : 'border-input bg-background hover:bg-accent',
    ].join(' ');
</script>

<template>
    <section
        aria-label="Filter tickets"
        class="flex flex-col gap-3 rounded-xl border bg-card p-3 shadow-xs"
    >
        <div
            class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] md:items-end"
        >
            <div class="grid gap-2">
                <Label for="filter-q">Keyword</Label>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="filter-q"
                        v-model="state.q"
                        type="search"
                        placeholder="Subject or description"
                        class="pl-8"
                        maxlength="200"
                    />
                </div>
                <p v-if="errors.q" class="text-sm text-destructive">
                    {{ errors.q }}
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="filter-number">Ticket ID</Label>
                <Input
                    id="filter-number"
                    v-model="state.number"
                    placeholder="TKT-2026-000123"
                    maxlength="32"
                />
            </div>
            <div class="grid gap-2">
                <Label for="filter-sort">Sort</Label>
                <NativeSelect id="filter-sort" v-model="state.sort">
                    <NativeSelectOption
                        v-for="option in options.sorts"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</NativeSelectOption
                    >
                </NativeSelect>
            </div>
            <Button
                type="button"
                variant="outline"
                :aria-expanded="showAdvanced"
                aria-controls="ticket-filters-advanced"
                @click="showAdvanced = !showAdvanced"
            >
                <SlidersHorizontal aria-hidden="true" />
                Filters<span v-if="activeAdvanced > 0" class="tabular-nums">
                    ({{ activeAdvanced }})</span
                >
            </Button>
        </div>

        <div
            v-show="showAdvanced"
            id="ticket-filters-advanced"
            class="flex flex-col gap-4"
        >
            <fieldset class="flex flex-wrap items-center gap-2">
                <legend class="mb-2 text-sm font-medium">Status</legend>
                <button
                    v-for="option in options.statuses"
                    :key="option.value"
                    type="button"
                    :class="chipClass(state.status.includes(option.value))"
                    :aria-pressed="state.status.includes(option.value)"
                    @click="toggle(state.status, option.value)"
                >
                    {{ option.label }}
                </button>
            </fieldset>

            <fieldset class="flex flex-wrap items-center gap-2">
                <legend class="mb-2 text-sm font-medium">Priority</legend>
                <button
                    v-for="option in options.priorities"
                    :key="option.value"
                    type="button"
                    :class="chipClass(state.priority.includes(option.value))"
                    :aria-pressed="state.priority.includes(option.value)"
                    @click="toggle(state.priority, option.value)"
                >
                    {{ option.label }}
                </button>
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-if="options.categories.length > 0" class="grid gap-2">
                    <Label for="filter-category">Category</Label>
                    <NativeSelect
                        id="filter-category"
                        v-model="state.category_id"
                    >
                        <NativeSelectOption value=""
                            >All categories</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-for="category in options.categories"
                            :key="category.id"
                            :value="String(category.id)"
                            >{{ category.name }}</NativeSelectOption
                        >
                    </NativeSelect>
                </div>
                <div class="grid gap-2">
                    <Label for="filter-sla">SLA</Label>
                    <NativeSelect id="filter-sla" v-model="state.sla_state">
                        <NativeSelectOption value=""
                            >Any SLA state</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-for="option in options.slaStates"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</NativeSelectOption
                        >
                    </NativeSelect>
                </div>
                <div class="grid gap-2">
                    <Label for="filter-from">Created from</Label>
                    <Input
                        id="filter-from"
                        v-model="state.created_from"
                        type="date"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="filter-to">Created to</Label>
                    <Input
                        id="filter-to"
                        v-model="state.created_to"
                        type="date"
                        :min="state.created_from || undefined"
                    />
                    <p
                        v-if="errors.created_to"
                        class="text-sm text-destructive"
                    >
                        {{ errors.created_to }}
                    </p>
                </div>
            </div>

            <div
                v-if="options.showPeopleFilters"
                class="grid gap-3 sm:grid-cols-3 sm:items-end"
            >
                <div v-if="options.agents.length > 0" class="grid gap-2">
                    <Label for="filter-agent">Assigned agent</Label>
                    <NativeSelect
                        id="filter-agent"
                        v-model="state.assigned_agent_id"
                        :disabled="state.unassigned"
                    >
                        <NativeSelectOption value=""
                            >Any agent</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-for="agent in options.agents"
                            :key="agent.id"
                            :value="String(agent.id)"
                            >{{ agent.name }}</NativeSelectOption
                        >
                    </NativeSelect>
                </div>
                <div class="flex h-9 items-center gap-2">
                    <Checkbox
                        id="filter-unassigned"
                        :model-value="state.unassigned"
                        @update:model-value="setUnassigned"
                    />
                    <Label for="filter-unassigned">Unassigned only</Label>
                </div>
                <div class="grid gap-2">
                    <Label for="filter-customer">Customer ID</Label>
                    <Input
                        id="filter-customer"
                        v-model="state.customer_id"
                        type="number"
                        min="1"
                        inputmode="numeric"
                    />
                </div>
            </div>
        </div>

        <div v-if="hasFilters" class="flex justify-end">
            <Button type="button" variant="ghost" size="sm" @click="reset">
                <RotateCcw aria-hidden="true" />
                Clear filters
            </Button>
        </div>
    </section>
</template>
