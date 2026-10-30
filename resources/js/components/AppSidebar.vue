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
        class="sticky top-14 hidden h-[calc(100svh-3.5rem)] w-[72px] shrink-0 flex-col items-center gap-1 overflow-y-auto border-r border-rail-border bg-rail px-2 py-3 md:flex"
        data-test="icon-rail"
    >
        <Link
            v-for="entry in entries"
            :key="entry.key"
            :href="entry.href"
            :aria-current="isActive(entry.hrefs) ? 'page' : undefined"
            :data-test="`rail-${entry.key}`"
            class="flex w-full flex-col items-center gap-1 rounded-lg px-1 py-2 text-rail-foreground transition-colors outline-none hover:bg-rail-accent/60 hover:text-rail-accent-foreground focus-visible:ring-2 focus-visible:ring-ring"
            :class="
                isActive(entry.hrefs)
                    ? 'bg-rail-accent text-rail-accent-foreground'
                    : ''
            "
        >
            <component :is="entry.icon" class="size-5" aria-hidden="true" />
            <span class="text-[11px] leading-none font-medium">{{
                entry.label
            }}</span>
        </Link>
    </nav>
</template>
