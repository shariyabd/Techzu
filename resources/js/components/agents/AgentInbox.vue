<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import { onMounted, ref } from 'vue';
import AgentListPanel from '@/components/agents/AgentListPanel.vue';
import type {
    AgentDirectoryFilters,
    AgentDirectoryList,
    AgentDirectoryOptions,
    AgentTab,
} from '@/types/agents';

const { selectedId = null, tab = null } = defineProps<{
    agents: AgentDirectoryList;
    filters: AgentDirectoryFilters;
    options: AgentDirectoryOptions;
    listPages: number;
    selectedId?: number | null;
    tab?: AgentTab | null;
}>();

const workspace = ref<HTMLElement | null>(null);
const workspaceTop = ref<string | undefined>(undefined);

function measureWorkspace(): void {
    if (!workspace.value) {
        return;
    }

    const top = workspace.value.getBoundingClientRect().top + window.scrollY;
    workspaceTop.value = `${Math.max(0, Math.round(top))}px`;
}

onMounted(measureWorkspace);
useEventListener('resize', measureWorkspace, { passive: true });
</script>

<template>
    <div
        ref="workspace"
        class="grid min-w-0 gap-4 p-4 lg:h-[calc(100svh-var(--workspace-top,6.5rem))] lg:min-h-[30rem] lg:grid-cols-[300px_minmax(0,1fr)] lg:grid-rows-[minmax(0,1fr)] lg:overflow-hidden"
        :style="workspaceTop ? { '--workspace-top': workspaceTop } : undefined"
    >
        <AgentListPanel
            :class="selectedId === null ? 'flex' : 'hidden lg:flex'"
            :agents="agents"
            :filters="filters"
            :options="options"
            :list-pages="listPages"
            :selected-id="selectedId"
            :tab="tab"
        />
        <div
            :class="[
                'min-w-0 flex-col gap-4 lg:min-h-0 lg:overflow-y-auto lg:overscroll-contain lg:pr-1 lg:pb-1',
                selectedId === null ? 'hidden lg:flex' : 'flex',
            ]"
        >
            <slot />
        </div>
    </div>
</template>
