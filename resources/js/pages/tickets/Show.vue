<script setup lang="ts">
import ActivityTimeline from '@/components/tickets/ActivityTimeline.vue';
import AssignmentPanel from '@/components/tickets/AssignmentPanel.vue';
import AttachmentList from '@/components/tickets/AttachmentList.vue';
import ConversationPanel from '@/components/tickets/ConversationPanel.vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import TicketEditForm from '@/components/tickets/TicketEditForm.vue';
import TicketStateControls from '@/components/tickets/TicketStateControls.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type {
    ActivityItem,
    AgentOption,
    AttachmentItem,
    ReplyItem,
    TicketCan,
    TicketDetail,
    TicketFormOptions,
} from '@/types/tickets';
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';

const props = defineProps<{
    ticket: TicketDetail;
    replies: ReplyItem[];
    attachments: AttachmentItem[];
    activities: ActivityItem[];
    agents: AgentOption[];
    can: TicketCan;
    options: TicketFormOptions;
}>();

watchEffect(() => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tickets', href: '/tickets' },
        { title: props.ticket.number, href: `/tickets/${props.ticket.number}` },
    ];
    setLayoutProps({ breadcrumbs });
});

const formatTime = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '—';
</script>

<template>
    <Head :title="`${ticket.number} · ${ticket.subject}`" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <span class="font-mono text-sm text-muted-foreground">{{
                ticket.number
            }}</span>
            <h1 class="text-xl font-semibold">{{ ticket.subject }}</h1>
            <StatusBadge :status="ticket.status" />
            <PriorityBadge :priority="ticket.priority" />
            <SlaBadge :sla="ticket.sla" />
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Description</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <p class="text-sm whitespace-pre-line">
                            {{ ticket.description }}
                        </p>
                        <AttachmentList :attachments="attachments" />
                    </CardContent>
                </Card>

                <TicketEditForm
                    v-if="can.update"
                    :ticket="ticket"
                    :can="can"
                    :categories="options.categories"
                />

                <ConversationPanel
                    :ticket="ticket"
                    :replies="replies"
                    :can="can"
                />
            </div>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid grid-cols-2 gap-x-2 gap-y-1 text-sm">
                            <dt class="text-muted-foreground">Customer</dt>
                            <dd>{{ ticket.customer?.name ?? '—' }}</dd>
                            <dt class="text-muted-foreground">Category</dt>
                            <dd>{{ ticket.category?.name ?? '—' }}</dd>
                            <dt class="text-muted-foreground">Agent</dt>
                            <dd>
                                {{
                                    ticket.assigned_agent?.name ?? 'Unassigned'
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Created</dt>
                            <dd>{{ formatTime(ticket.created_at) }}</dd>
                            <dt class="text-muted-foreground">Due</dt>
                            <dd>{{ formatTime(ticket.sla.due_at) }}</dd>
                            <dt
                                v-if="ticket.resolved_at"
                                class="text-muted-foreground"
                            >
                                Resolved
                            </dt>
                            <dd v-if="ticket.resolved_at">
                                {{ formatTime(ticket.resolved_at) }}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <TicketStateControls
                    :ticket="ticket"
                    :can="can"
                    :statuses="options.statuses"
                    :priorities="options.priorities"
                />

                <AssignmentPanel :ticket="ticket" :agents="agents" :can="can" />

                <ActivityTimeline :activities="activities" />
            </div>
        </div>
    </div>
</template>
