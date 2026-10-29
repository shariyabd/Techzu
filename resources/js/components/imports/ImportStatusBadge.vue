<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ImportStatusValue } from '@/types/imports';
import type { EnumValue } from '@/types/tickets';

defineProps<{ status: EnumValue<ImportStatusValue> }>();

const tones: Record<ImportStatusValue, string> = {
    pending: 'border-border bg-muted text-muted-foreground',
    processing:
        'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
    completed:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    completed_with_errors:
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
    failed: 'border-transparent bg-red-600 text-white dark:bg-red-700',
};
</script>

<template>
    <Badge
        variant="outline"
        :class="tones[status.value]"
        :data-import-status="status.value"
        >{{ status.label }}</Badge
    >
</template>
