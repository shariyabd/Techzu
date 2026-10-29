<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { EnumValue, TicketStatusValue } from '@/types/tickets';
import { computed } from 'vue';

const props = defineProps<{ status: EnumValue<TicketStatusValue> }>();

const variant = computed<'default' | 'secondary' | 'destructive' | 'outline'>(
    () => {
        switch (props.status.value) {
            case 'open':
                return 'default';
            case 'in_progress':
                return 'secondary';
            case 'waiting_for_customer':
                return 'outline';
            default:
                return 'outline';
        }
    },
);
</script>

<template>
    <Badge :variant="variant" :data-status="status.value">{{
        status.label
    }}</Badge>
</template>
