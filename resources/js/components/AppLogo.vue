<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div class="flex items-center gap-2">
        <div
            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
        >
            <AppLogoIcon class="size-5 fill-current" />
        </div>
        <span
            class="hidden truncate text-sm leading-tight font-semibold sm:block"
            >{{ name }}</span
        >
    </div>
</template>
