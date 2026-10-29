<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { EnumValue, TicketPriorityValue } from '@/types/tickets';

defineProps<{ priority: EnumValue<TicketPriorityValue> }>();

const tones: Record<TicketPriorityValue, string> = {
    low: 'border-border bg-muted text-muted-foreground',
    medium: 'border-slate-300 bg-slate-50 text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
    high: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-200',
    critical: 'border-transparent bg-red-600 text-white dark:bg-red-700',
};
</script>

<template>
    <Badge
        variant="outline"
        :class="tones[priority.value]"
        :data-priority="priority.value"
        >{{ priority.label }}</Badge
    >
</template>
