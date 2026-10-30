<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { navigationFor } from '@/config/navigation';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();

const sectionItems = computed(() => {
    const visible = navigationFor(page.props);
    const current = visible.find(
        (item) => item.railKey && isCurrentOrParentUrl(item.href),
    );

    if (!current) {
        return [];
    }

    const siblings = visible.filter((item) => item.railKey === current.railKey);

    return siblings.length > 1 ? siblings : [];
});

const showBreadcrumbs = computed(() => props.breadcrumbs.length > 1);
</script>

<template>
    <div
        v-if="sectionItems.length > 0 || showBreadcrumbs"
        class="flex shrink-0 flex-col border-b bg-surface"
    >
        <nav
            v-if="sectionItems.length > 0"
            aria-label="Section"
            class="hidden h-11 items-center gap-1 overflow-x-auto px-4 md:flex md:px-6"
            data-test="section-nav"
        >
            <Link
                v-for="item in sectionItems"
                :key="item.href"
                :href="item.href"
                :aria-current="
                    isCurrentOrParentUrl(item.href) ? 'page' : undefined
                "
                class="flex shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    isCurrentOrParentUrl(item.href)
                        ? 'bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                <component
                    :is="item.icon"
                    v-if="item.icon"
                    class="size-4"
                    aria-hidden="true"
                />
                {{ item.title }}
            </Link>
        </nav>
        <div
            v-if="showBreadcrumbs"
            class="flex h-10 items-center px-4 md:px-6"
            :class="sectionItems.length > 0 ? 'md:border-t' : ''"
        >
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
    </div>
</template>
