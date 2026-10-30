<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { index } from '@/routes/tickets';

const ticketNumberPattern = /^TKT-\d+$/i;

const input = ref<HTMLInputElement | null>(null);
const term = ref('');

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

function onKeydown(event: KeyboardEvent): void {
    const isShortcut =
        (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';
    const isSlash =
        event.key === '/' &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey &&
        !isTypingTarget(event.target);

    if (!isShortcut && !isSlash) {
        return;
    }

    event.preventDefault();
    input.value?.focus();
    input.value?.select();
}

function submit(): void {
    const value = term.value.trim();

    if (value === '') {
        return;
    }

    const query = ticketNumberPattern.test(value) ? value.toUpperCase() : value;

    router.visit(index({ query: { q: query } }));
    term.value = '';
    input.value?.blur();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <form
        role="search"
        class="relative w-full max-w-xl"
        data-test="global-search"
        @submit.prevent="submit"
    >
        <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <input
            ref="input"
            v-model="term"
            type="search"
            name="q"
            maxlength="200"
            autocomplete="off"
            aria-label="Search tickets"
            aria-keyshortcuts="Control+K /"
            placeholder="Search tickets or enter a number"
            class="h-9 w-full rounded-full border border-transparent bg-accent/70 pr-3 pl-9 text-sm text-foreground transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:bg-surface focus-visible:ring-2 focus-visible:ring-ring/40 sm:pr-16 [&::-webkit-search-cancel-button]:hidden"
        />
        <kbd
            class="pointer-events-none absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border bg-surface px-1.5 py-0.5 text-[11px] leading-none font-medium text-muted-foreground sm:block"
            aria-hidden="true"
            >Ctrl K</kbd
        >
    </form>
</template>
