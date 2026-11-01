<script setup lang="ts">
import { LoaderCircle, MessageSquareText, Search } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { renderSavedReply } from '@/lib/savedReplies';
import { picker } from '@/routes/saved-replies';
import type { SavedReply, SavedReplyContext } from '@/types/saved-replies';

const props = withDefaults(
    defineProps<{
        context: SavedReplyContext;
        categoryId?: number | null;
    }>(),
    { categoryId: null },
);

const emit = defineEmits<{ insert: [text: string] }>();

const DEBOUNCE_MS = 250;

const baseId = `saved-reply-picker-${useId()}`;
const listboxId = `${baseId}-listbox`;
const optionId = (index: number): string => `${baseId}-option-${index}`;

const open = ref(false);
const term = ref('');
const replies = ref<SavedReply[]>([]);
const loaded = ref(false);
const loading = ref(false);
const failed = ref(false);
const highlighted = ref(-1);
const input = ref<InstanceType<typeof Input> | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const isCategoryMatch = (reply: SavedReply): boolean =>
    props.categoryId !== null && reply.category_id === props.categoryId;

const ordered = computed(() => [
    ...replies.value.filter(isCategoryMatch),
    ...replies.value.filter((reply) => !isCategoryMatch(reply)),
]);

const activeDescendant = computed(() =>
    highlighted.value >= 0 ? optionId(highlighted.value) : undefined,
);

async function load(value: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    failed.value = false;

    const query: Record<string, string> = {};

    if (value !== '') {
        query.q = value;
    }

    if (props.categoryId !== null) {
        query.category_id = String(props.categoryId);
    }

    try {
        const response = await fetch(picker.url({ query }), {
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

        const body = (await response.json()) as { data: SavedReply[] };
        replies.value = body.data;
        loaded.value = true;
        highlighted.value = body.data.length > 0 ? 0 : -1;
        loading.value = false;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        replies.value = [];
        highlighted.value = -1;
        failed.value = true;
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen && !loaded.value && !loading.value) {
        void load(term.value.trim());
    }

    if (isOpen) {
        void nextTick(() => {
            const element = (input.value?.$el ??
                null) as HTMLInputElement | null;
            element?.focus();
        });
    }
});

watch(term, (value) => {
    clearTimeout(timer);
    loading.value = true;
    timer = setTimeout(() => void load(value.trim()), DEBOUNCE_MS);
});

watch(
    () => props.categoryId,
    () => {
        loaded.value = false;
    },
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

function scrollHighlightedIntoView(): void {
    void nextTick(() => {
        document
            .getElementById(optionId(highlighted.value))
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function move(step: number): void {
    const count = ordered.value.length;

    if (count === 0) {
        return;
    }

    highlighted.value = (highlighted.value + step + count) % count;
    scrollHighlightedIntoView();
}

let handedOff = false;

function choose(reply: SavedReply): void {
    handedOff = true;
    emit('insert', renderSavedReply(reply.body, props.context));
    open.value = false;
}

function onCloseAutoFocus(event: Event): void {
    if (handedOff) {
        event.preventDefault();
        handedOff = false;
    }
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
        case 'Home':
            if (ordered.value.length > 0) {
                event.preventDefault();
                highlighted.value = 0;
                scrollHighlightedIntoView();
            }
            break;
        case 'End':
            if (ordered.value.length > 0) {
                event.preventDefault();
                highlighted.value = ordered.value.length - 1;
                scrollHighlightedIntoView();
            }
            break;
        case 'Enter': {
            const reply = ordered.value[highlighted.value];
            event.preventDefault();

            if (reply !== undefined) {
                choose(reply);
            }
            break;
        }
    }
}

const status = computed(() => {
    if (loading.value) {
        return 'Loading saved replies…';
    }

    if (failed.value) {
        return 'Could not load saved replies.';
    }

    const count = ordered.value.length;

    return `${count} saved repl${count === 1 ? 'y' : 'ies'}`;
});
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" variant="outline" size="sm"
                ><MessageSquareText aria-hidden="true" />Saved replies</Button
            >
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="6"
                class="z-50 flex w-[min(28rem,calc(100vw-2rem))] flex-col gap-2 rounded-md border bg-popover p-2 text-popover-foreground shadow-md outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
                aria-label="Saved replies"
                @close-auto-focus="onCloseAutoFocus"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        ref="input"
                        v-model="term"
                        type="text"
                        role="combobox"
                        autocomplete="off"
                        maxlength="100"
                        class="pl-8"
                        placeholder="Search saved replies"
                        aria-label="Search saved replies"
                        aria-expanded="true"
                        :aria-controls="listboxId"
                        aria-autocomplete="list"
                        :aria-activedescendant="activeDescendant"
                        @keydown="onKeydown"
                    />
                    <LoaderCircle
                        v-if="loading"
                        class="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-muted-foreground motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                </div>
                <ul
                    :id="listboxId"
                    role="listbox"
                    aria-label="Saved replies"
                    class="max-h-72 overflow-y-auto"
                >
                    <li
                        v-for="(reply, index) in ordered"
                        :id="optionId(index)"
                        :key="reply.id"
                        role="option"
                        :aria-selected="index === highlighted"
                        class="flex cursor-pointer flex-col gap-0.5 rounded-sm px-2 py-1.5 text-sm"
                        :class="index === highlighted ? 'bg-accent' : ''"
                        @mousedown.prevent="choose(reply)"
                        @mouseenter="highlighted = index"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-medium">{{
                                reply.title
                            }}</span>
                            <Badge
                                v-if="isCategoryMatch(reply)"
                                variant="secondary"
                                class="shrink-0"
                                >Suggested</Badge
                            >
                            <Badge variant="outline" class="ml-auto shrink-0">{{
                                reply.scope === 'team' ? 'Team' : 'Mine'
                            }}</Badge>
                        </span>
                        <span
                            class="line-clamp-2 text-xs break-words text-muted-foreground"
                            >{{ renderSavedReply(reply.body, context) }}</span
                        >
                    </li>
                    <li
                        v-if="!loading && ordered.length === 0"
                        role="presentation"
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                    >
                        {{
                            failed
                                ? 'Could not load saved replies.'
                                : term.trim() !== ''
                                  ? 'No saved replies match.'
                                  : 'No saved replies yet.'
                        }}
                    </li>
                </ul>
                <span class="sr-only" aria-live="polite">{{ status }}</span>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
