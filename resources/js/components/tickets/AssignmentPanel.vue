<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOptGroup,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import UserAvatar from '@/components/UserAvatar.vue';
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
const assignedAgent = computed(() => props.ticket.assigned_agent);
const others = computed(() =>
    props.agents.filter(
        (agent) => agent.id !== props.ticket.assigned_agent?.id,
    ),
);

function handlesCategory(agent: AgentOption): boolean {
    const categoryId = props.ticket.category?.id;

    return (
        agent.category_ids === undefined ||
        categoryId === undefined ||
        agent.category_ids.includes(categoryId)
    );
}

const eligible = computed(() => others.value.filter(handlesCategory));
const outsiders = computed(() =>
    props.can.assignOutsideCategory
        ? others.value.filter((agent) => !handlesCategory(agent))
        : [],
);
const candidates = computed(() => [...eligible.value, ...outsiders.value]);
const selectedOption = computed(() =>
    candidates.value.find((agent) => String(agent.id) === selectedAgent.value),
);
const selectedIsOutsider = computed(() =>
    outsiders.value.some((agent) => String(agent.id) === selectedAgent.value),
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
        {
            agent_id: Number(selectedAgent.value),
            outside_category: selectedIsOutsider.value,
        },
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
    <section
        v-if="can.claim || can.assign || isAssigned"
        class="flex flex-col gap-4"
        aria-label="Assignment"
    >
        <div class="grid gap-1.5">
            <span class="text-xs font-semibold text-muted-foreground"
                >Assigned</span
            >
            <div class="flex items-center gap-2 rounded-md border px-3 py-2">
                <UserAvatar
                    v-if="assignedAgent"
                    :name="assignedAgent.name"
                    :src="assignedAgent.avatar_url"
                    class="size-6"
                />
                <span
                    class="text-sm font-medium"
                    data-testid="assigned-agent"
                    >{{ ticket.assigned_agent?.name ?? 'Nobody yet' }}</span
                >
            </div>
        </div>

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
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <UserAvatar
                    v-if="selectedOption"
                    :name="selectedOption.name"
                    :src="selectedOption.avatar_url"
                    class="hidden size-8 sm:flex"
                />
                <NativeSelect
                    id="assign-agent"
                    v-model="selectedAgent"
                    class="flex-1"
                >
                    <NativeSelectOption value="" disabled
                        >Choose an agent</NativeSelectOption
                    >
                    <NativeSelectOptGroup
                        v-if="eligible.length > 0"
                        :label="`Handles ${ticket.category?.name ?? 'this category'}`"
                    >
                        <NativeSelectOption
                            v-for="agent in eligible"
                            :key="agent.id"
                            :value="String(agent.id)"
                            >{{ agent.name }}</NativeSelectOption
                        >
                    </NativeSelectOptGroup>
                    <NativeSelectOptGroup
                        v-if="outsiders.length > 0"
                        label="Outside this category"
                    >
                        <NativeSelectOption
                            v-for="agent in outsiders"
                            :key="agent.id"
                            :value="String(agent.id)"
                            >{{ agent.name }} (not in
                            category)</NativeSelectOption
                        >
                    </NativeSelectOptGroup>
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
            <p
                v-if="selectedIsOutsider"
                class="text-xs text-muted-foreground"
                data-testid="outside-category-notice"
            >
                This agent does not handle the ticket's category. Assigning
                overrides category routing.
            </p>
            <InputError :message="error" />
        </form>
        <p v-else-if="can.assign" class="text-sm text-muted-foreground">
            No other available agents handle this category.
        </p>
    </section>
</template>
