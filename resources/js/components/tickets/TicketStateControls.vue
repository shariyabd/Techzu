<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    priority as priorityRoute,
    status as statusRoute,
} from '@/routes/tickets';
import type { Option, TicketCan, TicketDetail } from '@/types/tickets';

const props = defineProps<{
    ticket: TicketDetail;
    can: TicketCan;
    statuses: Option[];
    priorities: Option[];
}>();

const pending = ref<string | null>(null);

const label = (value: string): string => {
    if (value === 'closed') {
        return 'Close ticket';
    }
    if (
        value === 'in_progress' &&
        ['resolved', 'closed'].includes(props.ticket.status.value)
    ) {
        return 'Reopen';
    }
    const name = props.statuses.find((s) => s.value === value)?.label ?? value;
    return `Mark as ${name.toLowerCase()}`;
};

const targets = computed(() =>
    props.can.allowedStatuses.filter((value) => value !== 'closed'),
);
const canClose = computed(() => props.can.allowedStatuses.includes('closed'));

const visit = (key: string) => ({
    preserveScroll: true,
    onStart: () => {
        pending.value = key;
    },
    onFinish: () => {
        pending.value = null;
    },
});

function changeStatus(value: string): void {
    router.patch(
        statusRoute.url(props.ticket.number),
        { status: value },
        visit(value),
    );
}

function changePriority(value: unknown): void {
    const next = String(value ?? '');
    if (next === '' || next === props.ticket.priority.value) {
        return;
    }
    router.patch(
        priorityRoute.url(props.ticket.number),
        { priority: next },
        visit('priority'),
    );
}
</script>

<template>
    <Card
        v-if="
            (can.changeStatus && can.allowedStatuses.length > 0) ||
            can.changePriority
        "
    >
        <CardHeader
            ><CardTitle class="text-base">Actions</CardTitle></CardHeader
        >
        <CardContent class="flex flex-col gap-4">
            <div v-if="can.changeStatus" class="flex flex-wrap gap-2">
                <Button
                    v-for="value in targets"
                    :key="value"
                    size="sm"
                    variant="outline"
                    :disabled="pending !== null"
                    :data-status-target="value"
                    @click="changeStatus(value)"
                >
                    <Spinner v-if="pending === value" />
                    {{ label(value) }}
                </Button>
                <ConfirmDialog
                    v-if="canClose"
                    title="Close this ticket?"
                    description="A closed ticket can no longer receive replies."
                    confirm-label="Close ticket"
                    @confirm="changeStatus('closed')"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="pending !== null"
                        data-status-target="closed"
                    >
                        <Spinner v-if="pending === 'closed'" />
                        Close ticket
                    </Button>
                </ConfirmDialog>
            </div>
            <div v-if="can.changePriority" class="grid gap-2">
                <Label for="ticket-priority">Priority</Label>
                <NativeSelect
                    id="ticket-priority"
                    :model-value="ticket.priority.value"
                    :disabled="pending !== null"
                    @change="
                        (event: Event) =>
                            changePriority(
                                (event.target as HTMLSelectElement).value,
                            )
                    "
                >
                    <NativeSelectOption
                        v-for="option in priorities"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</NativeSelectOption
                    >
                </NativeSelect>
            </div>
        </CardContent>
    </Card>
</template>
