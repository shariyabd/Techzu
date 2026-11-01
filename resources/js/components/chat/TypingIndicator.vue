<script setup lang="ts">
import { computed } from 'vue';
import type { Typer } from '@/composables/useTyping';

const props = defineProps<{ typer: Typer }>();

const label = computed(() =>
    props.typer.mode === 'internal'
        ? `${props.typer.name} is writing an internal note`
        : `${props.typer.name} is typing`,
);
</script>

<template>
    <div
        class="flex items-end gap-2 motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in"
        :data-typing="typer.mode"
    >
        <div
            :class="[
                'flex items-center gap-2 rounded-2xl border px-3 py-2 text-xs shadow-xs',
                typer.mode === 'internal'
                    ? 'border-dashed border-tint-amber-border bg-tint-amber text-tint-amber-foreground'
                    : 'bg-card text-muted-foreground',
            ]"
        >
            <span class="flex items-center gap-1" aria-hidden="true">
                <span
                    v-for="delay in ['0ms', '150ms', '300ms']"
                    :key="delay"
                    class="size-1.5 rounded-full bg-current opacity-70 motion-safe:animate-bounce"
                    :style="{ animationDelay: delay }"
                />
            </span>
            <span>{{ label }}…</span>
        </div>
    </div>
</template>
