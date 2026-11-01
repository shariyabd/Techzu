<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    RotateCcw,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import CustomerPicker from '@/components/tickets/CustomerPicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { formatDate } from '@/lib/format';
import { index } from '@/routes/tickets';
import type {
    CustomerOption,
    TicketFilterOptions,
    TicketScope,
} from '@/types/tickets';

const props = defineProps<{
    scope: TicketScope;
    filters: Record<string, unknown>;
    options: TicketFilterOptions;
    errors: Partial<Record<string, string>>;
}>();

const DEFAULT_SORT = '-created_at';
const PANEL_STORAGE_KEY = 'tickets.filters.advanced-open';

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
    response: string;
    sort: string;
};

type ActiveChip = { id: string; label: string; remove: () => void };
type TextKey =
    | 'number'
    | 'q'
    | 'category_id'
    | 'assigned_agent_id'
    | 'customer_id'
    | 'created_from'
    | 'created_to'
    | 'sla_state'
    | 'response';

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
    'response',
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
    'response',
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
        response: text(filters.response),
        sort: text(filters.sort) || DEFAULT_SORT,
    };
}

const state = reactive<FilterState>(fromFilters(props.filters));
const customer = ref<CustomerOption | null>(null);

function setCustomer(value: CustomerOption | null): void {
    customer.value = value;
    state.customer_id = value === null ? '' : String(value.id);
}

watch(
    () => state.customer_id,
    (value) => {
        if (value === '') {
            customer.value = null;
        }
    },
);

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
const showAdvanced = ref(false);
const animate = ref(false);
const panelSettled = ref(false);
const PANEL_TRANSITION_MS = 320;
let settleTimer: ReturnType<typeof setTimeout> | undefined;

function readPanelPreference(): boolean {
    try {
        return window.localStorage.getItem(PANEL_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function togglePanel(): void {
    animate.value = true;
    showAdvanced.value = !showAdvanced.value;
    panelSettled.value = false;
    clearTimeout(settleTimer);
    settleTimer = setTimeout(() => {
        panelSettled.value = showAdvanced.value;
    }, PANEL_TRANSITION_MS);

    try {
        window.localStorage.setItem(
            PANEL_STORAGE_KEY,
            showAdvanced.value ? '1' : '0',
        );
    } catch {
        return;
    }
}

onMounted(() => {
    showAdvanced.value = readPanelPreference();
    panelSettled.value = showAdvanced.value;
    void nextTick(() => {
        animate.value = true;
    });
});

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

onBeforeUnmount(() => {
    clearTimeout(timer);
    clearTimeout(settleTimer);
});

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

function labelFor(
    options: { value: string; label: string }[],
    value: string,
): string {
    return options.find((option) => option.value === value)?.label ?? value;
}

const chips = computed<ActiveChip[]>(() => {
    const result: ActiveChip[] = [];
    const clear = (key: TextKey) => () => {
        state[key] = '';
    };

    if (state.q.trim() !== '') {
        result.push({
            id: 'q',
            label: `Keyword: “${state.q.trim()}”`,
            remove: clear('q'),
        });
    }
    if (state.number.trim() !== '') {
        result.push({
            id: 'number',
            label: `ID: ${state.number.trim()}`,
            remove: clear('number'),
        });
    }
    for (const value of state.status) {
        result.push({
            id: `status-${value}`,
            label: `Status: ${labelFor(props.options.statuses, value)}`,
            remove: () => toggle(state.status, value),
        });
    }
    for (const value of state.priority) {
        result.push({
            id: `priority-${value}`,
            label: `Priority: ${labelFor(props.options.priorities, value)}`,
            remove: () => toggle(state.priority, value),
        });
    }
    if (state.category_id !== '') {
        const category = props.options.categories.find(
            (candidate) => String(candidate.id) === state.category_id,
        );
        result.push({
            id: 'category_id',
            label: `Category: ${category?.name ?? `#${state.category_id}`}`,
            remove: clear('category_id'),
        });
    }
    if (state.sla_state !== '') {
        result.push({
            id: 'sla_state',
            label: `SLA: ${labelFor(props.options.slaStates, state.sla_state)}`,
            remove: clear('sla_state'),
        });
    }
    if (state.response !== '') {
        result.push({
            id: 'response',
            label: `Response: ${labelFor(props.options.responses, state.response)}`,
            remove: clear('response'),
        });
    }
    if (state.created_from !== '') {
        result.push({
            id: 'created_from',
            label: `From ${formatDate(`${state.created_from}T00:00:00`)}`,
            remove: clear('created_from'),
        });
    }
    if (state.created_to !== '') {
        result.push({
            id: 'created_to',
            label: `To ${formatDate(`${state.created_to}T00:00:00`)}`,
            remove: clear('created_to'),
        });
    }
    if (state.assigned_agent_id !== '') {
        const agent = props.options.agents.find(
            (candidate) => String(candidate.id) === state.assigned_agent_id,
        );
        result.push({
            id: 'assigned_agent_id',
            label: `Agent: ${agent?.name ?? `#${state.assigned_agent_id}`}`,
            remove: clear('assigned_agent_id'),
        });
    }
    if (state.unassigned) {
        result.push({
            id: 'unassigned',
            label: 'Unassigned only',
            remove: () => {
                state.unassigned = false;
            },
        });
    }
    if (state.customer_id.trim() !== '') {
        const selected =
            customer.value !== null &&
            String(customer.value.id) === state.customer_id.trim()
                ? customer.value.name
                : `#${state.customer_id.trim()}`;
        result.push({
            id: 'customer_id',
            label: `Customer: ${selected}`,
            remove: clear('customer_id'),
        });
    }

    return result;
});

const toggleClass = (active: boolean): string =>
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
        class="flex flex-col rounded-xl border bg-card shadow-xs"
    >
        <div
            class="grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_auto] md:grid-cols-[minmax(0,1fr)_11rem_11rem_auto] md:items-center"
        >
            <div class="relative">
                <Label for="filter-q" class="sr-only">Keyword</Label>
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    id="filter-q"
                    v-model="state.q"
                    type="search"
                    placeholder="Search subject or description"
                    class="pl-8"
                    maxlength="200"
                    :aria-invalid="errors.q ? true : undefined"
                />
            </div>
            <div class="hidden md:block">
                <Label for="filter-number" class="sr-only">Ticket ID</Label>
                <Input
                    id="filter-number"
                    v-model="state.number"
                    placeholder="Ticket ID"
                    maxlength="32"
                />
            </div>
            <div class="hidden md:block">
                <Label for="filter-sort" class="sr-only">Sort</Label>
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
                :variant="showAdvanced ? 'secondary' : 'outline'"
                :aria-expanded="showAdvanced"
                aria-controls="ticket-filters-advanced"
                @click="togglePanel"
            >
                <SlidersHorizontal aria-hidden="true" />
                Filters
                <span
                    v-if="activeAdvanced > 0"
                    class="inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] leading-5 font-semibold text-primary-foreground tabular-nums"
                    >{{ activeAdvanced
                    }}<span class="sr-only"> active</span></span
                >
                <ChevronDown
                    class="transition-transform duration-200 motion-reduce:transition-none"
                    :class="showAdvanced ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </Button>
        </div>
        <p v-if="errors.q" class="-mt-1 px-3 pb-2 text-sm text-destructive">
            {{ errors.q }}
        </p>

        <div
            class="grid"
            :class="[
                showAdvanced ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
                animate
                    ? 'transition-[grid-template-rows] duration-300 ease-out motion-reduce:transition-none'
                    : '',
            ]"
        >
            <div
                id="ticket-filters-advanced"
                class="min-h-0"
                :class="
                    showAdvanced && panelSettled
                        ? 'overflow-visible'
                        : 'overflow-hidden'
                "
                :inert="!showAdvanced"
                :aria-hidden="!showAdvanced"
            >
                <div class="flex flex-col gap-4 border-t bg-muted/30 p-3">
                    <div class="grid gap-3 sm:grid-cols-2 md:hidden">
                        <div class="grid gap-2">
                            <Label for="filter-number-sm">Ticket ID</Label>
                            <Input
                                id="filter-number-sm"
                                v-model="state.number"
                                placeholder="TKT-2026-000123"
                                maxlength="32"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="filter-sort-sm">Sort</Label>
                            <NativeSelect
                                id="filter-sort-sm"
                                v-model="state.sort"
                            >
                                <NativeSelectOption
                                    v-for="option in options.sorts"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ option.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <fieldset class="flex flex-wrap items-center gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Status
                            </legend>
                            <button
                                v-for="option in options.statuses"
                                :key="option.value"
                                type="button"
                                :class="
                                    toggleClass(
                                        state.status.includes(option.value),
                                    )
                                "
                                :aria-pressed="
                                    state.status.includes(option.value)
                                "
                                @click="toggle(state.status, option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </fieldset>

                        <fieldset class="flex flex-wrap items-center gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Priority
                            </legend>
                            <button
                                v-for="option in options.priorities"
                                :key="option.value"
                                type="button"
                                :class="
                                    toggleClass(
                                        state.priority.includes(option.value),
                                    )
                                "
                                :aria-pressed="
                                    state.priority.includes(option.value)
                                "
                                @click="toggle(state.priority, option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </fieldset>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            v-if="options.categories.length > 0"
                            class="grid gap-2"
                        >
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
                            <NativeSelect
                                id="filter-sla"
                                v-model="state.sla_state"
                            >
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
                        class="grid gap-3 sm:grid-cols-2 sm:items-end lg:grid-cols-4"
                    >
                        <div
                            v-if="options.agents.length > 0"
                            class="grid gap-2"
                        >
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
                        <div class="grid min-w-0 gap-2">
                            <Label for="filter-customer">Customer</Label>
                            <CustomerPicker
                                id="filter-customer"
                                :model-value="customer"
                                :resolve-id="state.customer_id"
                                :invalid="Boolean(errors.customer_id)"
                                @update:model-value="setCustomer"
                            />
                            <p
                                v-if="errors.customer_id"
                                class="text-sm text-destructive"
                            >
                                {{ errors.customer_id }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="filter-response">Response</Label>
                            <NativeSelect
                                id="filter-response"
                                v-model="state.response"
                            >
                                <NativeSelectOption value=""
                                    >Any</NativeSelectOption
                                >
                                <NativeSelectOption
                                    v-for="option in options.responses"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ option.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </div>
                        <div class="flex h-9 items-center gap-2">
                            <Checkbox
                                id="filter-unassigned"
                                :model-value="state.unassigned"
                                @update:model-value="setUnassigned"
                            />
                            <Label for="filter-unassigned"
                                >Unassigned only</Label
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="hasFilters"
            class="flex flex-wrap items-center gap-2 border-t px-3 py-2"
        >
            <span class="text-xs font-medium text-muted-foreground"
                >Active:</span
            >
            <ul class="contents" aria-label="Active filters">
                <li v-for="chip in chips" :key="chip.id">
                    <button
                        type="button"
                        class="inline-flex h-7 items-center gap-1 rounded-full border border-primary/30 bg-primary/10 pr-1.5 pl-2.5 text-xs font-medium text-primary transition-colors hover:bg-primary/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="`Remove filter ${chip.label}`"
                        @click="chip.remove()"
                    >
                        {{ chip.label }}
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="ml-auto"
                @click="reset"
            >
                <RotateCcw aria-hidden="true" />
                Reset
            </Button>
        </div>
    </section>
</template>
