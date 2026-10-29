<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { EnumValue, TicketStatusValue } from '@/types/tickets';

defineProps<{ status: EnumValue<TicketStatusValue> }>();

const tones: Record<TicketStatusValue, string> = {
    open: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
    in_progress:
        'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    waiting_for_customer:
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
    resolved:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    closed: 'border-border bg-muted text-muted-foreground',
};
</script>

<template>
    <Badge
        variant="outline"
        :class="tones[status.value]"
        :data-status="status.value"
        >{{ status.label }}</Badge
    >
</template>
