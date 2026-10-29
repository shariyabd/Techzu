<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const {
    processing = false,
    disabled = false,
    variant = 'default',
} = defineProps<{
    processing?: boolean;
    disabled?: boolean;
    variant?: 'default' | 'destructive' | 'outline' | 'secondary';
}>();
</script>

<template>
    <Button
        type="submit"
        :variant="variant"
        :disabled="processing || disabled"
        :aria-busy="processing"
    >
        <Spinner v-if="processing" />
        <slot />
    </Button>
</template>
