<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { AgentOption, TicketCan, TicketDetail } from '@/types/tickets';

defineProps<{ ticket: TicketDetail; agents: AgentOption[]; can: TicketCan }>();
</script>

<template>
    <Card data-stub="assignment-panel">
        <CardHeader>
            <CardTitle class="text-base">Assignment</CardTitle>
        </CardHeader>
        <CardContent>
            <p class="text-sm">
                {{ ticket.assigned_agent?.name ?? 'Unassigned' }}
            </p>
        </CardContent>
    </Card>
</template>
