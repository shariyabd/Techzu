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

const isAwaitingResponse = computed(
    () => props.ticket.has_agent_response === false,
);

const clientName = computed(
    () => props.ticket.customer?.name ?? props.ticket.category?.name ?? '—',
);
</script>

<template>
    <article
        :class="[
            'relative grid items-center gap-x-4 gap-y-3 overflow-hidden rounded-xl border p-3 pl-4 shadow-xs transition-shadow focus-within:ring-2 focus-within:ring-ring hover:shadow-md lg:grid-cols-[minmax(0,13rem)_8.5rem_minmax(0,1fr)_minmax(0,13rem)_minmax(0,10rem)_2rem] lg:py-2.5',
            isAwaitingResponse
                ? 'border-ticket-awaiting-border bg-ticket-awaiting before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-ticket-awaiting-accent'
                : 'border-ticket-responded-border bg-ticket-responded',
        ]"
        :data-ticket="ticket.number"
        :data-response="isAwaitingResponse ? 'awaiting' : 'responded'"
    >
        <div class="flex min-w-0 items-center gap-3 pr-10 lg:pr-0">
            <Avatar class="size-9 shrink-0">
                <AvatarFallback>{{ getInitials(clientName) }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ clientName }}</p>
                <p
                    class="truncate text-xs text-muted-foreground"
                    :title="formatDateTime(ticket.created_at)"
                >
                    {{ formatRelative(ticket.created_at) }}
                </p>
            </div>
        </div>

        <p class="font-mono text-xs text-muted-foreground lg:text-sm">
            {{ ticket.number }}
        </p>

        <div class="min-w-0">
            <Link
                :href="show(ticket.number)"
                class="block truncate font-semibold outline-none after:absolute after:inset-0 after:rounded-xl hover:underline"
                >{{ ticket.subject
                }}<span v-if="isAwaitingResponse" class="sr-only">
                    (awaiting first agent response)</span
                ></Link
            >
            <div
                class="mt-1 flex min-w-0 items-center gap-2 text-xs text-muted-foreground"
            >
                <span
                    v-if="ticket.category && showCustomer"
                    class="truncate rounded bg-muted px-1.5 py-0.5"
                    >{{ ticket.category.name }}</span
                >
                <span
                    v-if="ticket.replies_count"
                    class="inline-flex shrink-0 items-center gap-1"
                    :title="`${ticket.replies_count} replies`"
                    ><MessageSquare class="size-3" aria-hidden="true" />{{
                        ticket.replies_count
                    }}<span class="sr-only"> replies</span></span
                >
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-1.5">
            <div class="flex flex-wrap items-center gap-1.5">
                <StatusBadge :status="ticket.status" />
                <PriorityBadge :priority="ticket.priority" />
            </div>
            <SlaBadge :sla="ticket.sla" />
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
            <span v-else class="text-sm text-muted-foreground italic"
                >Unassigned</span
            >
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
