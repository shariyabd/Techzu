<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { PaginationLinks, PaginationMeta } from '@/types/pagination';

const { only, noun = 'results' } = defineProps<{
    links: PaginationLinks;
    meta: PaginationMeta;
    only?: string[];
    noun?: string;
}>();
</script>

<template>
    <nav
        v-if="meta.total > 0"
        class="flex flex-col items-center justify-between gap-2 text-sm sm:flex-row"
        aria-label="Pagination"
    >
        <p class="text-muted-foreground tabular-nums">
            {{ meta.from }}–{{ meta.to }} of {{ meta.total }} {{ noun }}
        </p>
        <div v-if="meta.last_page > 1" class="flex items-center gap-2">
            <Button v-if="links.prev" variant="outline" size="sm" as-child>
                <Link
                    :href="links.prev"
                    :only="only"
                    preserve-scroll
                    preserve-state
                    ><ChevronLeft aria-hidden="true" />Previous</Link
                >
            </Button>
            <Button v-else variant="outline" size="sm" disabled
                ><ChevronLeft aria-hidden="true" />Previous</Button
            >
            <span class="px-1 text-muted-foreground tabular-nums"
                >{{ meta.current_page }} / {{ meta.last_page }}</span
            >
            <Button v-if="links.next" variant="outline" size="sm" as-child>
                <Link
                    :href="links.next"
                    :only="only"
                    preserve-scroll
                    preserve-state
                    >Next<ChevronRight aria-hidden="true"
                /></Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled
                >Next<ChevronRight aria-hidden="true"
            /></Button>
        </div>
    </nav>
</template>
