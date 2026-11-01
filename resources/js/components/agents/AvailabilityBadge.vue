<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

defineProps<{ available: boolean }>();
</script>

<template>
    <Badge
        v-if="available"
        variant="outline"
        class="border-transparent bg-tint-green text-tint-green-foreground"
        >Available</Badge
    >
    <Badge v-else variant="secondary">Unavailable</Badge>
</template>
