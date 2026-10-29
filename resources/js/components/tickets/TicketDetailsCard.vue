<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { TicketDetail } from '@/types/tickets';

const props = defineProps<{ ticket: TicketDetail }>();

const escalationLabels: Record<number, string> = {
    1: 'SLA warning sent',
    2: 'Escalated (breached)',
};

const showDue = computed(
    () =>
        props.ticket.sla.due_at !== null &&
        props.ticket.sla.state.value !== 'stopped',
);
</script>

<template>
    <Card>
        <CardHeader
            ><CardTitle class="text-base">Details</CardTitle></CardHeader
        >
        <CardContent>
            <dl
                class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm"
            >
                <dt class="text-muted-foreground">Customer</dt>
                <dd class="break-words">{{ ticket.customer?.name ?? '—' }}</dd>
                <dt class="text-muted-foreground">Category</dt>
                <dd>{{ ticket.category?.name ?? '—' }}</dd>
                <dt class="text-muted-foreground">Agent</dt>
                <dd>{{ ticket.assigned_agent?.name ?? 'Unassigned' }}</dd>
                <dt class="text-muted-foreground">Created</dt>
                <dd :title="formatDateTime(ticket.created_at)">
                    {{ formatRelative(ticket.created_at) }}
                </dd>
                <dt class="text-muted-foreground">Updated</dt>
                <dd :title="formatDateTime(ticket.updated_at)">
                    {{ formatRelative(ticket.updated_at) }}
                </dd>
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
        </CardContent>
    </Card>
</template>
