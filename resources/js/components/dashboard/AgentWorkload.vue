<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ChartHref } from '@/components/dashboard/chart';
import type { AgentLoad } from '@/types/dashboard';

const props = defineProps<{
    agents: AgentLoad[];
    hrefFor: (agent: AgentLoad) => ChartHref;
}>();

const largest = computed(() =>
    Math.max(1, ...props.agents.map((agent) => agent.active)),
);

const width = (value: number): string => `${(value / largest.value) * 100}%`;
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1.5"
                ><span
                    class="size-2.5 rounded-sm bg-primary"
                    aria-hidden="true"
                />
                Active, within SLA</span
            >
            <span class="flex items-center gap-1.5"
                ><span
                    class="size-2.5 rounded-sm bg-red-600 dark:bg-red-500"
                    aria-hidden="true"
                />
                Breached</span
            >
        </div>
        <ul class="grid gap-x-8 gap-y-1 lg:grid-cols-2">
            <li v-for="agent in agents" :key="agent.agent_id">
                <Link
                    :href="hrefFor(agent)"
                    class="group grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-md px-2 py-2 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    :title="`${agent.name}: ${agent.active} active, ${agent.breached} breached`"
                >
                    <span class="truncate text-sm group-hover:underline">{{
                        agent.name
                    }}</span>
                    <span
                        class="flex h-2.5 overflow-hidden rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <span
                            class="h-full bg-primary transition-[width] duration-500 ease-out motion-reduce:transition-none"
                            :style="{
                                width: width(agent.active - agent.breached),
                            }"
                        />
                        <span
                            class="h-full bg-red-600 transition-[width] duration-500 ease-out motion-reduce:transition-none dark:bg-red-500"
                            :style="{ width: width(agent.breached) }"
                        />
                    </span>
                    <span class="text-right text-sm tabular-nums"
                        ><span class="font-medium">{{ agent.active }}</span
                        ><span
                            v-if="agent.breached > 0"
                            class="ml-1.5 rounded bg-red-600/10 px-1 text-[11px] font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300"
                            aria-hidden="true"
                            >{{ agent.breached }}</span
                        ><span class="sr-only">
                            active, {{ agent.breached }} breached</span
                        ></span
                    >
                </Link>
            </li>
        </ul>
    </div>
</template>
