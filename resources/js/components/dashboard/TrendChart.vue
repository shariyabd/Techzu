<script setup lang="ts">
import { computed, ref } from 'vue';
import { niceCeiling } from '@/components/dashboard/chart';
import type { TrendPoint } from '@/types/dashboard';

const props = defineProps<{ points: TrendPoint[] }>();

const active = ref<number | null>(null);

const dayFormat = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
});
const longFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
});

const asDate = (value: string): Date => new Date(`${value}T00:00:00`);

const ceiling = computed(() =>
    niceCeiling(
        Math.max(0, ...props.points.flatMap((p) => [p.created, p.resolved])),
    ),
);
const ticks = computed(() => [ceiling.value, ceiling.value / 2, 0]);

const height = (value: number): string =>
    value === 0 ? '0%' : `${Math.max((value / ceiling.value) * 100, 1.5)}%`;

const totals = computed(() => ({
    created: props.points.reduce((sum, p) => sum + p.created, 0),
    resolved: props.points.reduce((sum, p) => sum + p.resolved, 0),
}));

const activePoint = computed(() =>
    active.value === null ? null : (props.points[active.value] ?? null),
);

const tooltipLeft = computed(() =>
    active.value === null
        ? '0%'
        : `${Math.min(Math.max(((active.value + 0.5) / props.points.length) * 100, 14), 86)}%`,
);

const labelEvery = computed(() =>
    Math.max(1, Math.ceil(props.points.length / 7)),
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span class="flex items-center gap-2">
                <span
                    class="size-2.5 rounded-sm bg-primary"
                    aria-hidden="true"
                />
                Created
                <span class="font-semibold tabular-nums">{{
                    totals.created
                }}</span>
            </span>
            <span class="flex items-center gap-2">
                <span
                    class="size-2.5 rounded-sm bg-emerald-500 dark:bg-emerald-400"
                    aria-hidden="true"
                />
                Resolved
                <span class="font-semibold tabular-nums">{{
                    totals.resolved
                }}</span>
            </span>
        </div>

        <div class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-2">
            <div
                class="relative h-52 text-right text-[11px] text-muted-foreground tabular-nums"
                aria-hidden="true"
            >
                <span
                    v-for="(tick, position) in ticks"
                    :key="tick"
                    class="absolute right-0 -translate-y-1/2"
                    :style="{ top: `${position * 50}%` }"
                    >{{ tick }}</span
                >
            </div>

            <div class="relative h-52">
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                    aria-hidden="true"
                >
                    <span class="border-t border-dashed border-border" />
                    <span class="border-t border-dashed border-border" />
                    <span class="border-t border-border" />
                </div>

                <ol
                    class="relative flex h-full items-stretch"
                    aria-label="Tickets created and resolved per day"
                    @mouseleave="active = null"
                >
                    <li
                        v-for="(point, position) in points"
                        :key="point.date"
                        tabindex="0"
                        class="flex flex-1 cursor-default items-end justify-center gap-[2px] rounded-sm px-[2px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="active === position ? 'bg-muted/70' : ''"
                        :aria-label="`${longFormat.format(asDate(point.date))}: ${point.created} created, ${point.resolved} resolved`"
                        @mouseenter="active = position"
                        @focus="active = position"
                        @blur="active = null"
                    >
                        <span
                            class="w-full max-w-3 rounded-t-sm bg-primary transition-[height] duration-500 ease-out motion-reduce:transition-none"
                            :style="{ height: height(point.created) }"
                        />
                        <span
                            class="w-full max-w-3 rounded-t-sm bg-emerald-500 transition-[height] duration-500 ease-out motion-reduce:transition-none dark:bg-emerald-400"
                            :style="{ height: height(point.resolved) }"
                        />
                    </li>
                </ol>

                <div
                    v-if="activePoint"
                    class="pointer-events-none absolute -top-2 z-10 min-w-32 -translate-x-1/2 -translate-y-full rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                    :style="{ left: tooltipLeft }"
                    aria-hidden="true"
                >
                    <p class="mb-1 font-medium">
                        {{ longFormat.format(asDate(activePoint.date)) }}
                    </p>
                    <p class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5"
                            ><span class="size-2 rounded-sm bg-primary" />
                            Created</span
                        >
                        <span class="font-semibold tabular-nums">{{
                            activePoint.created
                        }}</span>
                    </p>
                    <p class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="size-2 rounded-sm bg-emerald-500 dark:bg-emerald-400"
                            />
                            Resolved</span
                        >
                        <span class="font-semibold tabular-nums">{{
                            activePoint.resolved
                        }}</span>
                    </p>
                </div>
            </div>

            <ol
                class="col-start-2 mt-2 flex text-[11px] text-muted-foreground"
                aria-hidden="true"
            >
                <li
                    v-for="(point, position) in points"
                    :key="point.date"
                    class="flex-1 text-center whitespace-nowrap"
                >
                    {{
                        (points.length - 1 - position) % labelEvery === 0
                            ? dayFormat.format(asDate(point.date))
                            : ''
                    }}
                </li>
            </ol>
        </div>
    </div>
</template>
