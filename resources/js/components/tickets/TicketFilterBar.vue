<script setup lang="ts">
defineProps<{
    scope: 'active' | 'resolved';
    filters: Record<string, unknown>;
}>();
</script>

<template>
    <div data-stub="ticket-filter-bar" />
</template>
