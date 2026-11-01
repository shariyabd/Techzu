<script setup lang="ts">
import { LoaderCircle, Search, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { lookup } from '@/routes/customers';
import type { CustomerOption } from '@/types/tickets';

const props = withDefaults(
    defineProps<{
        id: string;
        modelValue: CustomerOption | null;
        resolveId?: string;
        placeholder?: string;
        invalid?: boolean;
    }>(),
    {
        resolveId: '',
        placeholder: 'Search by name or email',
        invalid: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [customer: CustomerOption | null];
}>();

const DEBOUNCE_MS = 250;

const term = ref('');
const results = ref<CustomerOption[]>([]);
const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const highlighted = ref(-1);
const input = ref<InstanceType<typeof Input> | null>(null);
const listboxId = computed(() => `${props.id}-listbox`);
const optionId = (index: number): string => `${props.id}-option-${index}`;
const activeDescendant = computed(() =>
    open.value && highlighted.value >= 0
        ? optionId(highlighted.value)
        : undefined,
);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function request(
    query: Record<string, string>,
): Promise<CustomerOption[]> {
    controller?.abort();
    controller = new AbortController();

    const response = await fetch(lookup.url({ query }), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal: controller.signal,
    });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    const body = (await response.json()) as { data: CustomerOption[] };

    return body.data;
}

async function search(value: string): Promise<void> {
    loading.value = true;
    failed.value = false;

    try {
        results.value = await request({ q: value });
        highlighted.value = results.value.length > 0 ? 0 : -1;
        loading.value = false;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }
        results.value = [];
        failed.value = true;
        loading.value = false;
    }
}

watch(term, (value) => {
    clearTimeout(timer);
    const trimmed = value.trim();

    if (trimmed === '') {
        controller?.abort();
        results.value = [];
        loading.value = false;
        open.value = false;
        return;
    }

    open.value = true;
    loading.value = true;
    timer = setTimeout(() => void search(trimmed), DEBOUNCE_MS);
});

watch(
    () => props.resolveId,
    async (value) => {
        if (value === '' || String(props.modelValue?.id ?? '') === value) {
            return;
        }

        try {
            const [customer] = await request({ id: value });
            if (customer !== undefined && props.resolveId === value) {
                emit('update:modelValue', customer);
            }
        } catch {
            return;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

function focusInput(): void {
    void nextTick(() => {
        const element = (input.value?.$el ?? null) as HTMLInputElement | null;
        element?.focus();
    });
}

function choose(customer: CustomerOption): void {
    emit('update:modelValue', customer);
    term.value = '';
    open.value = false;
    results.value = [];
}

function clear(): void {
    emit('update:modelValue', null);
    focusInput();
}

function move(step: number): void {
    if (!open.value) {
        open.value = term.value.trim() !== '';
        return;
    }
    if (results.value.length === 0) {
        return;
    }
    highlighted.value =
        (highlighted.value + step + results.value.length) %
        results.value.length;
}

function onKeydown(event: KeyboardEvent): void {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Enter': {
            const customer = results.value[highlighted.value];
            if (open.value && customer !== undefined) {
                event.preventDefault();
                choose(customer);
            }
            break;
        }
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                open.value = false;
            }
            break;
    }
}

function onBlur(): void {
    setTimeout(() => {
        open.value = false;
    }, 150);
}
</script>

<template>
    <div class="relative w-full min-w-0">
        <div
            v-if="modelValue"
            class="flex min-h-9 w-full min-w-0 items-center gap-2 rounded-md border border-input bg-background px-2 py-1 shadow-xs"
        >
            <span
                class="inline-flex min-w-0 items-center gap-1 rounded-full border border-primary/30 bg-primary/10 py-0.5 pr-1 pl-2.5 text-xs font-medium text-primary"
            >
                <span class="truncate" :title="modelValue.email">{{
                    modelValue.name
                }}</span>
                <button
                    :id="id"
                    type="button"
                    class="inline-flex size-5 shrink-0 items-center justify-center rounded-full hover:bg-primary/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Remove customer ${modelValue.name}`"
                    @click="clear"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>
            <span class="min-w-0 truncate text-xs text-muted-foreground">{{
                modelValue.email
            }}</span>
        </div>
        <template v-else>
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                :id="id"
                ref="input"
                v-model="term"
                type="text"
                role="combobox"
                autocomplete="off"
                maxlength="100"
                class="pl-8"
                :placeholder="placeholder"
                :aria-expanded="open"
                :aria-controls="listboxId"
                aria-autocomplete="list"
                :aria-activedescendant="activeDescendant"
                :aria-invalid="invalid ? true : undefined"
                @keydown="onKeydown"
                @blur="onBlur"
            />
            <LoaderCircle
                v-if="loading && open"
                class="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-muted-foreground motion-reduce:animate-none"
                aria-hidden="true"
            />
            <ul
                v-show="open"
                :id="listboxId"
                role="listbox"
                class="absolute z-20 mt-1 max-h-64 w-full min-w-0 overflow-y-auto rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"
            >
                <li
                    v-for="(customer, index) in results"
                    :id="optionId(index)"
                    :key="customer.id"
                    role="option"
                    :aria-selected="index === highlighted"
                    class="flex cursor-pointer flex-col rounded-sm px-2 py-1.5"
                    :class="index === highlighted ? 'bg-accent' : ''"
                    @mousedown.prevent="choose(customer)"
                    @mouseenter="highlighted = index"
                >
                    <span class="truncate font-medium">{{
                        customer.name
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{
                        customer.email
                    }}</span>
                </li>
                <li
                    v-if="!loading && results.length === 0"
                    role="presentation"
                    class="px-2 py-1.5 text-muted-foreground"
                >
                    {{
                        failed
                            ? 'Could not load customers.'
                            : 'No matching customers.'
                    }}
                </li>
                <li
                    v-if="loading && results.length === 0"
                    role="presentation"
                    class="px-2 py-1.5 text-muted-foreground"
                >
                    Searching…
                </li>
            </ul>
            <span class="sr-only" aria-live="polite">{{
                open && !loading
                    ? `${results.length} customer${results.length === 1 ? '' : 's'} found`
                    : ''
            }}</span>
        </template>
    </div>
</template>
