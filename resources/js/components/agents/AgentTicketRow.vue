<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed } from 'vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { presenceStatus } from '@/composables/usePresence';
import { formatDateTime, formatRelative } from '@/lib/format';
import { show } from '@/routes/tickets';
import type { TicketListItem } from '@/types/tickets';

const props = defineProps<{
    ticket: TicketListItem;
    now: number;
}>();

const isAwaitingResponse = computed(
    () => props.ticket.has_agent_response === false,
);
const clientName = computed(() => props.ticket.customer?.name ?? '—');
const customerActive = computed(
    () =>
        props.ticket.customer !== null &&
        presenceStatus(props.ticket.customer.last_seen_at, false, props.now)
            .active,
);
</script>

<template>
    <article
        :class="[
            'relative grid items-center gap-x-4 gap-y-2 overflow-hidden rounded-xl border p-3 pl-4 shadow-xs transition-shadow focus-within:ring-2 focus-within:ring-ring hover:shadow-md @2xl:grid-cols-[minmax(0,12rem)_8.5rem_minmax(0,1fr)_minmax(0,9.5rem)_2rem] @2xl:py-2.5',
            isAwaitingResponse
                ? 'border-ticket-awaiting-border bg-ticket-awaiting before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-ticket-awaiting-accent'
                : 'border-border bg-card',
        ]"
        :data-ticket="ticket.number"
    >
        <div class="flex min-w-0 items-center gap-3 pr-10 @2xl:pr-0">
            <span class="relative inline-flex shrink-0">
                <Avatar class="size-9">
                    <AvatarImage
                        v-if="ticket.customer?.avatar_url"
                        :src="ticket.customer.avatar_url"
                        :alt="clientName"
                    />
                    <AvatarFallback class="text-xs">{{
                        getInitials(clientName)
                    }}</AvatarFallback>
                </Avatar>
                <span
                    v-if="customerActive"
                    class="absolute right-0 bottom-0 size-2.5 rounded-full bg-emerald-500 ring-2 ring-background"
                    data-presence="active"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ clientName }}</p>
                <p
                    class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span
                        v-if="ticket.category"
                        class="truncate rounded bg-muted px-1.5 py-px"
                        >{{ ticket.category.name }}</span
                    >
                    <span
                        class="shrink-0"
                        :title="formatDateTime(ticket.created_at)"
                        >{{ formatRelative(ticket.created_at) }}</span
                    >
                </p>
            </div>
        </div>

        <p
            class="font-mono text-xs whitespace-nowrap text-muted-foreground @2xl:text-sm"
        >
            {{ ticket.number }}
        </p>

        <div class="min-w-0">
            <Link
                :href="show(ticket.number)"
                class="block truncate text-sm font-semibold outline-none after:absolute after:inset-0 after:rounded-xl hover:underline"
                >{{ ticket.subject
                }}<span v-if="isAwaitingResponse" class="sr-only">
                    (awaiting first agent response)</span
                ></Link
            >
            <p
                v-if="ticket.latest_message"
                class="mt-0.5 truncate text-xs text-muted-foreground"
                data-preview
            >
                {{ ticket.latest_message }}
            </p>
        </div>

        <div class="flex min-w-0 flex-col items-start gap-1.5">
            <div class="flex flex-wrap items-center gap-1.5">
                <StatusBadge :status="ticket.status" />
                <PriorityBadge :priority="ticket.priority" />
            </div>
            <SlaBadge :sla="ticket.sla" />
        </div>

        <div
            class="absolute top-2 right-2 z-10 @2xl:relative @2xl:top-auto @2xl:right-auto @2xl:justify-self-end"
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
                        <Link :href="show(ticket.number)">Open ticket</Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>
