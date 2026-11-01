<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

const { id = 'recalculate_active_tickets' } = defineProps<{ id?: string }>();

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <div class="flex items-start gap-3 rounded-lg border bg-muted/30 p-3">
        <Checkbox
            :id="id"
            :model-value="checked"
            class="mt-0.5"
            @update:model-value="(value) => (checked = value === true)"
        />
        <div class="grid gap-1">
            <Label :for="id">Recalculate active tickets</Label>
            <p class="text-xs text-muted-foreground">
                New tickets always use the new rules. Tick this to also
                recalculate the due and warning times of every open, in-progress
                and waiting ticket from its original start. It runs in the
                background and never changes resolved or closed tickets. Left
                unticked, active tickets keep their deadlines until their
                priority changes, which recalculates them with the new rules, or
                their paused clock restarts, which counts the remaining time in
                the new business hours.
            </p>
        </div>
    </div>
</template>
