<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { assign, claim } from '@/routes/tickets';
import type { AgentOption, TicketCan, TicketDetail } from '@/types/tickets';

const props = defineProps<{
    ticket: TicketDetail;
    agents: AgentOption[];
    can: TicketCan;
}>();

const selectedAgent = ref('');
const busy = ref<'claim' | 'assign' | null>(null);
const error = ref<string | undefined>();

const isAssigned = computed(() => props.ticket.assigned_agent !== null);
const candidates = computed(() =>
    props.agents.filter(
        (agent) => agent.id !== props.ticket.assigned_agent?.id,
    ),
);

function claimTicket(): void {
    router.post(
        claim.url(props.ticket.number),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = 'claim';
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function assignTicket(): void {
    router.post(
        assign.url(props.ticket.number),
        { agent_id: Number(selectedAgent.value) },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = 'assign';
            },
            onFinish: () => {
                busy.value = null;
            },
            onSuccess: () => {
                selectedAgent.value = '';
                error.value = undefined;
            },
            onError: (errors) => {
                error.value = errors.agent_id;
            },
        },
    );
}
</script>

<template>
    <Card v-if="can.claim || can.assign || isAssigned">
        <CardHeader
            ><CardTitle class="text-base">Assignment</CardTitle></CardHeader
        >
        <CardContent class="flex flex-col gap-4">
            <p class="text-sm">
                <span class="text-muted-foreground">Assigned to</span>
                <span class="ml-1 font-medium" data-testid="assigned-agent">{{
                    ticket.assigned_agent?.name ?? 'Nobody yet'
                }}</span>
            </p>

            <Button
                v-if="can.claim && !isAssigned"
                class="w-full"
                :disabled="busy !== null"
                @click="claimTicket"
            >
                <Spinner v-if="busy === 'claim'" />
                Claim ticket
            </Button>

            <form
                v-if="can.assign && candidates.length > 0"
                class="grid gap-2"
                @submit.prevent="assignTicket"
            >
                <Label for="assign-agent">{{
                    isAssigned ? 'Hand off to' : 'Assign to'
                }}</Label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <NativeSelect
                        id="assign-agent"
                        v-model="selectedAgent"
                        class="flex-1"
                    >
                        <NativeSelectOption value="" disabled
                            >Choose an agent</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-for="agent in candidates"
                            :key="agent.id"
                            :value="String(agent.id)"
                            >{{ agent.name }}</NativeSelectOption
                        >
                    </NativeSelect>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="busy !== null || selectedAgent === ''"
                    >
                        <Spinner v-if="busy === 'assign'" />
                        {{ isAssigned ? 'Hand off' : 'Assign' }}
                    </Button>
                </div>
                <InputError :message="error" />
            </form>
            <p v-else-if="can.assign" class="text-sm text-muted-foreground">
                No other agents are available.
            </p>
        </CardContent>
    </Card>
</template>
