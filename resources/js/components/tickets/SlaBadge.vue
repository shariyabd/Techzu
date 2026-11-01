<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useSlaCountdown } from '@/composables/useSlaCountdown';
import {
    formatBusinessDateTime,
    formatBusinessDuration,
    formatSlaRemaining,
} from '@/lib/format';
import type { SlaStateValue, TicketSla } from '@/types/tickets';

const { sla, showDue = true } = defineProps<{
    sla: Pick<TicketSla, 'state' | 'due_at' | 'clock'>;
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

const page = usePage();

const businessTime = (iso: string | null): string =>
    formatBusinessDateTime(iso, page.props.businessTimezone);

const countdown = useSlaCountdown(() => sla.clock);

const showsCountdown = computed(() => showDue && countdown.value !== null);

const overdue = computed(() => (countdown.value?.seconds ?? 0) < 0);

const countdownText = computed(() => {
    const seconds = countdown.value?.seconds ?? 0;
    const duration = formatBusinessDuration(seconds);

    return seconds < 0 ? `Overdue ${duration}` : `${duration} left`;
});

const countdownTitle = computed(() => {
    const current = countdown.value;

    if (!current) {
        return '';
    }

    const parts = [`${formatSlaRemaining(current.seconds)} in business time`];

    if (sla.state.value === 'paused') {
        parts.push('paused until the customer replies');
    } else {
        if (current.resumesAt) {
            parts.push(`clock resumes ${businessTime(current.resumesAt)}`);
        } else if (!current.running) {
            parts.push('outside business hours');
        }

        parts.push(`due ${businessTime(sla.due_at)}`);
    }

    return parts.join(' · ');
});
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
            v-if="showsCountdown"
            role="timer"
            aria-live="off"
            class="text-xs tabular-nums"
            :class="overdue ? 'text-destructive' : 'text-muted-foreground'"
            :title="countdownTitle"
            :aria-label="countdownTitle"
            data-sla-countdown
            >{{ countdownText }}</span
        >
    </span>
</template>
