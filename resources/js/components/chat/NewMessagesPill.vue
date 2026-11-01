<script setup lang="ts">
import { ArrowDown } from '@lucide/vue';

defineProps<{ count: number }>();

const emit = defineEmits<{ jump: [] }>();
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-full bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground shadow-md outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in motion-safe:slide-in-from-bottom-2"
        @click="emit('jump')"
    >
        {{ count === 1 ? 'New message' : `${count} new messages` }}
        <ArrowDown class="size-3.5" aria-hidden="true" />
    </button>
</template>
