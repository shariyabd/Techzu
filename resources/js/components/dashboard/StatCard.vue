<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { Card, CardContent } from '@/components/ui/card';

const { tone = 'default' } = defineProps<{
    label: string;
    value: number;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: Component;
    tone?: 'default' | 'warning' | 'danger' | 'success';
}>();

const tones = {
    default: 'text-foreground',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-red-600 dark:text-red-400',
    success: 'text-emerald-600 dark:text-emerald-400',
} as const;
</script>

<template>
    <Link
        :href="href"
        class="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
        <Card class="h-full gap-0 py-4 transition-colors hover:bg-muted/50">
            <CardContent class="flex items-start justify-between gap-2 px-4">
                <div class="flex flex-col gap-1">
                    <span class="text-sm text-muted-foreground">{{
                        label
                    }}</span>
                    <span
                        class="text-3xl font-semibold tabular-nums"
                        :class="tones[tone]"
                        >{{ value }}</span
                    >
                </div>
                <component
                    :is="icon"
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
            </CardContent>
        </Card>
    </Link>
</template>
