<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { TicketSla } from '@/types/tickets';
import { computed } from 'vue';

const props = defineProps<{ sla: Pick<TicketSla, 'state' | 'due_at'> }>();

const variant = computed<'default' | 'secondary' | 'destructive' | 'outline'>(
    () => {
        switch (props.sla.state.value) {
            case 'breached':
                return 'destructive';
            case 'approaching':
                return 'default';
            case 'within':
                return 'secondary';
            default:
                return 'outline';
        }
    },
);

const due = computed(() =>
    props.sla.due_at ? new Date(props.sla.due_at).toLocaleString() : null,
);
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <Badge :variant="variant" :data-sla="sla.state.value">{{
            sla.state.label
        }}</Badge>
        <span
            v-if="due && sla.state.value !== 'stopped'"
            class="text-xs text-muted-foreground"
            >due {{ due }}</span
        >
    </span>
</template>
