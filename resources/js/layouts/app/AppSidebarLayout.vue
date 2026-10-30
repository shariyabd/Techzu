<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <AppShell>
        <AppHeader />
        <div class="flex min-h-0 flex-1">
            <AppSidebar />
            <div class="flex min-w-0 flex-1 flex-col">
                <AppSidebarHeader :breadcrumbs="breadcrumbs" />
                <AppContent>
                    <slot />
                </AppContent>
            </div>
        </div>
        <Toaster rich-colors close-button position="top-right" />
    </AppShell>
</template>
