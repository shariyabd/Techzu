<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ellipsis, MessageSquare } from '@lucide/vue';
import { computed } from 'vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { formatDateTime, formatRelative } from '@/lib/format';
import { show } from '@/routes/tickets';
import type { TicketListItem } from '@/types/tickets';

const props = defineProps<{
    ticket: TicketListItem;
    showCustomer: boolean;
}>();

const isActive = computed(() =>
    ['open', 'in_progress', 'waiting_for_customer'].includes(
        props.ticket.status.value,
    ),
);
const isBreached = computed(() => props.ticket.sla.state.value === 'breached');
const isUnassigned = computed(
    () => isActive.value && props.ticket.assigned_agent === null,
);

const tone = computed(() => {
    if (isBreached.value) {
        return 'border-tint-amber-border bg-tint-amber';
    }

    if (isUnassigned.value && props.showCustomer) {
        return 'border-transparent bg-tint-green';
    }

    return 'border-border bg-card';
});

const clientName = computed(
    () => props.ticket.customer?.name ?? props.ticket.category?.name ?? '—',
);
</script>

<template>
    <article
        :class="[
            'relative grid items-center gap-x-4 gap-y-2 rounded-xl border p-3 shadow-xs transition-shadow focus-within:ring-2 focus-within:ring-ring hover:shadow-sm lg:grid-cols-[minmax(0,13rem)_8.5rem_minmax(0,1fr)_9rem_2rem]',
            tone,
        ]"
        :data-ticket="ticket.number"
    >
        <div class="flex min-w-0 items-center gap-3 pr-10 lg:pr-0">
            <Avatar class="size-9 shrink-0">
                <AvatarFallback>{{ getInitials(clientName) }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ clientName }}</p>
                <div class="flex items-center gap-2 text-xs">
                    <span
                        v-if="ticket.category && showCustomer"
                        class="truncate rounded bg-muted px-1.5 py-0.5 text-muted-foreground"
                        >{{ ticket.category.name }}</span
                    >
                    <span
                        class="shrink-0 text-muted-foreground"
                        :title="formatDateTime(ticket.created_at)"
                        >{{ formatRelative(ticket.created_at) }}</span
                    >
                </div>
            </div>
        </div>

        <p class="font-mono text-sm text-muted-foreground">
            {{ ticket.number }}
        </p>

        <div class="min-w-0">
            <Link
                :href="show(ticket.number)"
                class="block truncate font-semibold outline-none after:absolute after:inset-0 after:rounded-xl hover:underline"
                >{{ ticket.subject }}</Link
            >
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <StatusBadge :status="ticket.status" />
                <PriorityBadge :priority="ticket.priority" />
                <SlaBadge :sla="ticket.sla" />
                <span
                    v-if="ticket.replies_count"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                    ><MessageSquare class="size-3" aria-hidden="true" />{{
                        ticket.replies_count
                    }}</span
                >
            </div>
        </div>

        <div class="flex min-w-0 items-center gap-2">
            <template v-if="ticket.assigned_agent">
                <Avatar class="size-6 shrink-0">
                    <AvatarFallback class="text-[10px]">{{
                        getInitials(ticket.assigned_agent.name)
                    }}</AvatarFallback>
                </Avatar>
                <span class="truncate text-sm">{{
                    ticket.assigned_agent.name
                }}</span>
            </template>
            <span v-else class="text-sm text-muted-foreground">Unassigned</span>
        </div>

        <div
            class="absolute top-2 right-2 z-10 lg:relative lg:top-auto lg:right-auto lg:justify-self-end"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :aria-label="`Actions for ${ticket.number}`"
                    >
                        <Ellipsis aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem as-child>
                        <Link :href="show(ticket.number)">Open</Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>
