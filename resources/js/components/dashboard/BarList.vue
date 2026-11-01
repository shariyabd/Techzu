<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { percentOf } from '@/components/dashboard/chart';
import type { ChartSegment } from '@/components/dashboard/chart';

const props = defineProps<{
    items: ChartSegment[];
    noun: string;
}>();

const total = computed(() =>
    props.items.reduce((sum, item) => sum + item.value, 0),
);
const largest = computed(() =>
    Math.max(1, ...props.items.map((item) => item.value)),
);
</script>

<template>
    <ul class="flex flex-col gap-1">
        <li v-for="item in items" :key="item.key">
            <component
                :is="item.href ? Link : 'div'"
                :href="item.href"
                class="group grid gap-1.5 rounded-md px-2 py-2 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="item.href ? 'hover:bg-muted' : ''"
                :title="`${item.label}: ${item.value} ${noun} (${percentOf(item.value, total)}%)`"
            >
                <span class="flex items-center justify-between gap-2 text-sm">
                    <span class="truncate group-hover:underline">{{
                        item.label
                    }}</span>
                    <span class="shrink-0 tabular-nums"
                        ><span class="font-medium">{{ item.value }}</span
                        ><span class="ml-1.5 text-xs text-muted-foreground"
                            >{{ percentOf(item.value, total) }}%</span
                        ></span
                    >
                </span>
                <span
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <span
                        class="block h-full rounded-full transition-[width] duration-500 ease-out motion-reduce:transition-none"
                        :class="item.fillClass"
                        :style="{
                            width: `${item.value === 0 ? 0 : Math.max((item.value / largest) * 100, 2)}%`,
                        }"
                    />
                </span>
            </component>
        </li>
    </ul>
</template>
