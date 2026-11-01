<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useSlaCountdown } from '@/composables/useSlaCountdown';
import {
    formatBusinessDateTime,
    formatBusinessDuration,
    formatDateTime,
    formatRelative,
    formatSlaRemaining,
} from '@/lib/format';
import type { TicketDetail } from '@/types/tickets';

const props = defineProps<{ ticket: TicketDetail }>();

const page = usePage();

const businessTime = (iso: string | null | undefined): string =>
    formatBusinessDateTime(iso, page.props.businessTimezone);

const escalationLabels: Record<number, string> = {
    1: 'SLA warning sent',
    2: 'Escalated (breached)',
};

const showCreatedBy = computed(
    () =>
        props.ticket.created_by != null &&
        props.ticket.created_by.id !== props.ticket.customer?.id,
);

const escalationLabel = computed(() =>
    props.ticket.escalation_level === undefined
        ? null
        : (escalationLabels[props.ticket.escalation_level] ?? null),
);

const countdown = useSlaCountdown(() => props.ticket.sla.clock);

const countdownOverdue = computed(() => (countdown.value?.seconds ?? 0) < 0);

const countdownText = computed(() => {
    const current = countdown.value;

    if (!current) {
        return null;
    }

    if (props.ticket.sla.state.value === 'paused') {
        return current.seconds < 0
            ? `${formatSlaRemaining(current.seconds)} · paused until the customer replies`
            : `${formatBusinessDuration(current.seconds)} left when the customer replies`;
    }

    if (current.running) {
        const remaining = formatSlaRemaining(current.seconds, true);

        return current.seconds < 0
            ? remaining
            : `${remaining} · business hours`;
    }

    const remaining = formatSlaRemaining(current.seconds);

    return current.resumesAt
        ? `${remaining} · clock resumes ${businessTime(current.resumesAt)}`
        : `${remaining} · outside business hours`;
});

const countdownLabel = computed(() => {
    const current = countdown.value;

    if (!current) {
        return '';
    }

    const remaining = `${formatSlaRemaining(current.seconds)} in business time`;

    if (props.ticket.sla.state.value === 'paused') {
        return `${remaining}, paused until the customer replies`;
    }

    if (current.running) {
        return `${remaining}, counting down during business hours`;
    }

    return current.resumesAt
        ? `${remaining}, clock resumes ${businessTime(current.resumesAt)}`
        : `${remaining}, clock stopped outside business hours`;
});

const showDue = computed(
    () =>
        props.ticket.sla.due_at !== null &&
        props.ticket.sla.state.value !== 'stopped',
);
</script>

<template>
    <dl
        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2.5 text-sm"
        aria-label="Ticket details"
    >
        <dt class="text-muted-foreground">Category</dt>
        <dd>{{ ticket.category?.name ?? '—' }}</dd>
        <dt class="text-muted-foreground">Priority</dt>
        <dd>{{ ticket.priority.label }}</dd>
        <template v-if="showCreatedBy">
            <dt class="text-muted-foreground">Created by</dt>
            <dd class="break-words">{{ ticket.created_by?.name }}</dd>
        </template>
        <dt class="text-muted-foreground">Created</dt>
        <dd :title="formatDateTime(ticket.created_at)">
            {{ formatRelative(ticket.created_at) }}
        </dd>
        <dt class="text-muted-foreground">Updated</dt>
        <dd :title="formatDateTime(ticket.updated_at)">
            {{ formatRelative(ticket.updated_at) }}
        </dd>
        <dt class="text-muted-foreground">SLA</dt>
        <dd>{{ ticket.sla.state.label }}</dd>
        <template v-if="countdown && countdownText">
            <dt class="text-muted-foreground">Time left</dt>
            <dd
                role="timer"
                aria-live="off"
                class="flex items-start gap-1.5 tabular-nums"
                :class="countdownOverdue ? 'text-destructive' : undefined"
                :title="countdownLabel"
                :aria-label="countdownLabel"
                data-sla-countdown
            >
                <span
                    v-if="countdown.running"
                    class="mt-2 size-1.5 shrink-0 rounded-full motion-safe:animate-pulse"
                    :class="
                        countdownOverdue ? 'bg-destructive' : 'bg-emerald-500'
                    "
                    aria-hidden="true"
                />
                <span>{{ countdownText }}</span>
            </dd>
        </template>
        <template v-if="showDue">
            <dt class="text-muted-foreground">SLA due</dt>
            <dd>{{ businessTime(ticket.sla.due_at) }}</dd>
        </template>
        <template v-if="ticket.sla.paused_at">
            <dt class="text-muted-foreground">Paused since</dt>
            <dd>{{ businessTime(ticket.sla.paused_at) }}</dd>
        </template>
        <template v-if="escalationLabel">
            <dt class="text-muted-foreground">Escalation</dt>
            <dd class="text-destructive">
                {{ escalationLabel }}
            </dd>
        </template>
        <template v-if="ticket.resolved_at">
            <dt class="text-muted-foreground">Resolved</dt>
            <dd>{{ formatDateTime(ticket.resolved_at) }}</dd>
        </template>
        <template v-if="ticket.closed_at">
            <dt class="text-muted-foreground">Closed</dt>
            <dd>{{ formatDateTime(ticket.closed_at) }}</dd>
        </template>
    </dl>
</template>
