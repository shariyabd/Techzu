<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

const { tone = 'default' } = defineProps<{
    label: string;
    value: number;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: Component;
    tone?: 'default' | 'warning' | 'danger' | 'success';
}>();

const tones = {
    default: 'bg-primary/10 text-primary',
    warning: 'bg-tint-amber text-tint-amber-foreground',
    danger: 'bg-destructive/10 text-destructive',
    success: 'bg-tint-green text-tint-green-foreground',
} as const;
</script>

<template>
    <Link
        :href="href"
        class="flex items-center gap-3 rounded-xl border bg-card p-4 shadow-xs transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
        <span
            class="flex size-10 shrink-0 items-center justify-center rounded-lg"
            :class="tones[tone]"
        >
            <component :is="icon" class="size-5" aria-hidden="true" />
        </span>
        <span class="flex min-w-0 flex-col">
            <span class="text-sm leading-tight text-muted-foreground">{{
                label
            }}</span>
            <span class="text-2xl font-semibold tabular-nums">{{ value }}</span>
        </span>
    </Link>
</template>
