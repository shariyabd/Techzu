<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { railFor } from '@/config/navigation';

const page = usePage();
const entries = computed(() => railFor(page.props));
const { isCurrentOrParentUrl } = useCurrentUrl();

function isActive(hrefs: string[]): boolean {
    return hrefs.some((href) => isCurrentOrParentUrl(href));
}
</script>

<template>
    <nav
        v-if="entries.length > 0"
        aria-label="Main"
        class="sticky top-14 hidden h-[calc(100svh-3.5rem)] w-[88px] shrink-0 flex-col items-center gap-2 overflow-y-auto border-r border-rail-border bg-rail px-3 py-5 md:flex"
        data-test="icon-rail"
    >
        <Link
            v-for="entry in entries"
            :key="entry.key"
            :href="entry.href"
            :aria-current="isActive(entry.hrefs) ? 'page' : undefined"
            :data-test="`rail-${entry.key}`"
            class="group relative flex w-full flex-col items-center gap-1.5 rounded-xl px-1 py-3 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-rail-indicator focus-visible:ring-offset-2 focus-visible:ring-offset-rail"
            :class="
                isActive(entry.hrefs)
                    ? 'bg-rail-accent text-rail-accent-foreground'
                    : 'text-rail-foreground hover:bg-rail-muted hover:text-rail-accent-foreground'
            "
        >
            <span
                v-if="isActive(entry.hrefs)"
                class="absolute top-1/2 -left-3 h-8 w-1 -translate-y-1/2 rounded-r-full bg-rail-indicator"
                aria-hidden="true"
            />
            <component
                :is="entry.icon"
                class="size-5 transition-transform group-hover:scale-105"
                :class="isActive(entry.hrefs) ? 'text-rail-indicator' : ''"
                aria-hidden="true"
            />
            <span class="text-[11px] leading-none font-medium tracking-wide">{{
                entry.label
            }}</span>
        </Link>
    </nav>
</template>
