<script setup lang="ts">
import { computed } from 'vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { TicketDetail } from '@/types/tickets';

const props = defineProps<{ ticket: TicketDetail }>();

const escalationLabels: Record<number, string> = {
    1: 'SLA warning sent',
    2: 'Escalated (breached)',
};

const showCreatedBy = computed(
    () =>
        props.ticket.created_by != null &&
        props.ticket.created_by.id !== props.ticket.customer?.id,
);

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
        <template v-if="showDue">
            <dt class="text-muted-foreground">SLA due</dt>
            <dd>{{ formatDateTime(ticket.sla.due_at) }}</dd>
        </template>
        <template v-if="ticket.sla.paused_at">
            <dt class="text-muted-foreground">Paused since</dt>
            <dd>{{ formatDateTime(ticket.sla.paused_at) }}</dd>
        </template>
        <template v-if="ticket.escalation_level > 0">
            <dt class="text-muted-foreground">Escalation</dt>
            <dd class="text-destructive">
                {{ escalationLabels[ticket.escalation_level] }}
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
