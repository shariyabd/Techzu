<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Option, TicketCan, TicketDetail } from '@/types/tickets';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    ticket: TicketDetail;
    can: TicketCan;
    statuses: Option[];
    priorities: Option[];
}>();

const busy = ref(false);

const statusButtons = computed<Option[]>(() =>
    props.can.allowedStatuses.map((value) => ({
        value,
        label: props.statuses.find((s) => s.value === value)?.label ?? value,
    })),
);

const changeStatus = (status: string): void => {
    router.patch(
        `/tickets/${props.ticket.number}/status`,
        { status },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
};

const changePriority = (priority: string): void => {
    if (priority === props.ticket.priority.value) {
        return;
    }
    router.patch(
        `/tickets/${props.ticket.number}/priority`,
        { priority },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
};
</script>

<template>
    <Card v-if="can.changeStatus || can.changePriority">
        <CardHeader>
            <CardTitle class="text-base">Status &amp; priority</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
            <div v-if="can.changeStatus" class="flex flex-wrap gap-2">
                <Button
                    v-for="option in statusButtons"
                    :key="option.value"
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    :data-status-target="option.value"
                    @click="changeStatus(option.value)"
                >
                    Move to {{ option.label }}
                </Button>
            </div>
            <div v-if="can.changePriority" class="grid gap-2">
                <Label for="ticket-priority">Priority</Label>
                <Select
                    :model-value="ticket.priority.value"
                    :disabled="busy"
                    @update:model-value="(v) => changePriority(String(v ?? ''))"
                >
                    <SelectTrigger id="ticket-priority"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in priorities"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </CardContent>
    </Card>
</template>
