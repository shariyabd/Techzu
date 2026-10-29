<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { EnumValue, TicketPriorityValue } from '@/types/tickets';
import { computed } from 'vue';

const props = defineProps<{ priority: EnumValue<TicketPriorityValue> }>();

const variant = computed<'default' | 'secondary' | 'destructive' | 'outline'>(
    () => {
        switch (props.priority.value) {
            case 'critical':
                return 'destructive';
            case 'high':
                return 'default';
            case 'medium':
                return 'secondary';
            default:
                return 'outline';
        }
    },
);
</script>

<template>
    <Badge :variant="variant" :data-priority="priority.value">{{
        priority.label
    }}</Badge>
</template>
