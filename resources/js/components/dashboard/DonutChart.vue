<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { percentOf } from '@/components/dashboard/chart';
import type { ChartSegment } from '@/components/dashboard/chart';

const props = defineProps<{
    segments: ChartSegment[];
    totalLabel: string;
    label: string;
}>();

const hovered = ref<string | null>(null);

const total = computed(() =>
    props.segments.reduce((sum, segment) => sum + segment.value, 0),
);

const visibleCount = computed(
    () => props.segments.filter((segment) => segment.value > 0).length,
);

const arcs = computed(() => {
    const gap = visibleCount.value > 1 ? 1.2 : 0;
    let offset = 0;

    return props.segments.flatMap((segment) => {
        if (segment.value === 0 || total.value === 0) {
            return [];
        }

        const length = (segment.value / total.value) * 100;
        const arc = {
            segment,
            dash: `${Math.max(length - gap, 0.4)} ${100 - Math.max(length - gap, 0.4)}`,
            offset: 25 - offset,
        };
        offset += length;

        return [arc];
    });
});

const focus = computed(
    () =>
        props.segments.find((segment) => segment.key === hovered.value) ?? null,
);

function open(segment: ChartSegment): void {
    if (segment.href) {
        router.visit(segment.href);
    }
}
</script>

<template>
    <div
        class="flex flex-col items-center gap-5 sm:flex-row sm:items-center xl:flex-col 2xl:flex-row"
    >
        <div class="relative size-40 shrink-0">
            <svg
                viewBox="0 0 42 42"
                class="size-full"
                role="img"
                :aria-label="`${label}: ${segments.map((segment) => `${segment.label} ${segment.value}`).join(', ')}`"
            >
                <circle
                    cx="21"
                    cy="21"
                    r="15.915"
                    fill="none"
                    class="stroke-muted"
                    stroke-width="5"
                />
                <circle
                    v-for="arc in arcs"
                    :key="arc.segment.key"
                    cx="21"
                    cy="21"
                    r="15.915"
                    fill="none"
                    :stroke-width="hovered === arc.segment.key ? 6.5 : 5"
                    :stroke-dasharray="arc.dash"
                    :stroke-dashoffset="arc.offset"
                    :class="[
                        arc.segment.strokeClass,
                        arc.segment.href ? 'cursor-pointer' : '',
                        hovered !== null && hovered !== arc.segment.key
                            ? 'opacity-40'
                            : '',
                    ]"
                    class="transition-[stroke-width,opacity] duration-150 motion-reduce:transition-none"
                    @mouseenter="hovered = arc.segment.key"
                    @mouseleave="hovered = null"
                    @click="open(arc.segment)"
                >
                    <title>
                        {{ arc.segment.label }}: {{ arc.segment.value }} ({{
                            percentOf(arc.segment.value, total)
                        }}%)
                    </title>
                </circle>
            </svg>
            <div
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center"
                aria-hidden="true"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    focus ? focus.value : total
                }}</span>
                <span
                    class="max-w-24 text-xs leading-tight text-muted-foreground"
                    >{{
                        focus
                            ? `${focus.label} · ${percentOf(focus.value, total)}%`
                            : totalLabel
                    }}</span
                >
            </div>
        </div>

        <ul class="flex w-full min-w-0 flex-col gap-1">
            <li v-for="segment in segments" :key="segment.key">
                <component
                    :is="segment.href ? Link : 'div'"
                    :href="segment.href"
                    class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="[
                        segment.href ? 'hover:bg-muted' : '',
                        hovered === segment.key ? 'bg-muted' : '',
                    ]"
                    @mouseenter="hovered = segment.key"
                    @mouseleave="hovered = null"
                    @focus="hovered = segment.key"
                    @blur="hovered = null"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :class="segment.fillClass"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 truncate">{{
                        segment.label
                    }}</span>
                    <span class="font-medium tabular-nums">{{
                        segment.value
                    }}</span>
                    <span
                        class="w-10 text-right text-xs text-muted-foreground tabular-nums"
                        >{{ percentOf(segment.value, total) }}%</span
                    >
                </component>
            </li>
        </ul>
    </div>
</template>
