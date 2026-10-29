<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { SlaStateValue, TicketSla } from '@/types/tickets';

const { sla, showDue = true } = defineProps<{
    sla: Pick<TicketSla, 'state' | 'due_at'>;
    showDue?: boolean;
}>();

const tones: Record<SlaStateValue, string> = {
    within: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    approaching:
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
    breached: 'border-transparent bg-red-600 text-white dark:bg-red-700',
    paused: 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
    stopped: 'border-border bg-muted text-muted-foreground',
};

const showsDue = computed(
    () =>
        showDue &&
        sla.due_at !== null &&
        !['stopped', 'paused'].includes(sla.state.value),
);
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <Badge
            variant="outline"
            :class="tones[sla.state.value]"
            :data-sla="sla.state.value"
            >{{ sla.state.label }}</Badge
        >
        <span
            v-if="showsDue"
            class="text-xs text-muted-foreground"
            :title="formatDateTime(sla.due_at)"
            >due {{ formatRelative(sla.due_at) }}</span
        >
    </span>
</template>
