<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ActivityTimeline from '@/components/tickets/ActivityTimeline.vue';
import AssignmentPanel from '@/components/tickets/AssignmentPanel.vue';
import AttachmentList from '@/components/tickets/AttachmentList.vue';
import ConversationPanel from '@/components/tickets/ConversationPanel.vue';
import PriorityBadge from '@/components/tickets/PriorityBadge.vue';
import SlaBadge from '@/components/tickets/SlaBadge.vue';
import StatusBadge from '@/components/tickets/StatusBadge.vue';
import TicketDetailsCard from '@/components/tickets/TicketDetailsCard.vue';
import TicketEditForm from '@/components/tickets/TicketEditForm.vue';
import TicketStateControls from '@/components/tickets/TicketStateControls.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index, show } from '@/routes/tickets';
import type { BreadcrumbItem } from '@/types';
import type {
    ActivityItem,
    AgentOption,
    AttachmentItem,
    AttachmentRules,
    ReplyItem,
    TicketCan,
    TicketDetail,
    TicketFormOptions,
} from '@/types/tickets';

defineOptions({
    layout: (pageProps: { ticket: { number: string } }) => ({
        breadcrumbs: [
            { title: 'Tickets', href: index() },
            {
                title: pageProps.ticket.number,
                href: show(pageProps.ticket.number),
            },
        ] satisfies BreadcrumbItem[],
    }),
});

const props = defineProps<{
    ticket: TicketDetail;
    replies: ReplyItem[];
    attachments: AttachmentItem[];
    repliesHasMore: boolean;
    activities: ActivityItem[];
    activitiesHasMore: boolean;
    repliesPages: number;
    activitiesPages: number;
    agents: AgentOption[];
    can: TicketCan;
    options: TicketFormOptions;
    attachmentRules: AttachmentRules;
}>();

const repliesPages = ref(props.repliesPages);
const activitiesPages = ref(props.activitiesPages);
const loadingMore = ref(false);

function loadMore(kind: 'replies' | 'activities'): void {
    const pages = kind === 'replies' ? repliesPages : activitiesPages;
    pages.value += 1;
    router.reload({
        data: { [`${kind}_pages`]: pages.value },
        only:
            kind === 'replies'
                ? ['replies', 'repliesHasMore']
                : ['activities', 'activitiesHasMore'],
        onStart: () => (loadingMore.value = true),
        onFinish: () => (loadingMore.value = false),
    });
}

const editing = ref(false);
</script>

<template>
    <Head :title="`${ticket.number} · ${ticket.subject}`" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="ticket.subject">
            <template #meta>
                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <span class="font-mono text-sm text-muted-foreground">{{
                        ticket.number
                    }}</span>
                    <StatusBadge :status="ticket.status" />
                    <PriorityBadge :priority="ticket.priority" />
                    <SlaBadge :sla="ticket.sla" />
                </div>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between gap-2"
                    >
                        <CardTitle class="text-base">Description</CardTitle>
                        <Button
                            v-if="can.update && !editing"
                            variant="ghost"
                            size="sm"
                            @click="editing = true"
                        >
                            <Pencil aria-hidden="true" />
                            Edit
                        </Button>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <TicketEditForm
                            v-if="editing"
                            :ticket="ticket"
                            :can="can"
                            :categories="options.categories"
                            @done="editing = false"
                        />
                        <p
                            v-else
                            class="text-sm break-words whitespace-pre-line"
                        >
                            {{ ticket.description }}
                        </p>
                        <AttachmentList :attachments="attachments" />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        <Tabs default-value="conversation">
                            <TabsList>
                                <TabsTrigger value="conversation"
                                    >Conversation ({{ replies.length
                                    }}{{
                                        repliesHasMore ? '+' : ''
                                    }})</TabsTrigger
                                >
                                <TabsTrigger value="activity"
                                    >Activity ({{ activities.length
                                    }}{{
                                        activitiesHasMore ? '+' : ''
                                    }})</TabsTrigger
                                >
                            </TabsList>
                            <TabsContent value="conversation" class="pt-4">
                                <ConversationPanel
                                    :ticket="ticket"
                                    :replies="replies"
                                    :has-more="repliesHasMore"
                                    :loading="loadingMore"
                                    :can="can"
                                    :attachment-rules="attachmentRules"
                                    @load-more="loadMore('replies')"
                                />
                            </TabsContent>
                            <TabsContent value="activity" class="pt-4">
                                <ActivityTimeline
                                    :activities="activities"
                                    :has-more="activitiesHasMore"
                                    :loading="loadingMore"
                                    @load-more="loadMore('activities')"
                                />
                            </TabsContent>
                        </Tabs>
                    </CardContent>
                </Card>
            </div>

            <aside
                class="order-first flex min-w-0 flex-col gap-6 lg:order-last"
            >
                <TicketStateControls
                    :ticket="ticket"
                    :can="can"
                    :statuses="options.statuses"
                    :priorities="options.priorities"
                />
                <AssignmentPanel :ticket="ticket" :agents="agents" :can="can" />
                <TicketDetailsCard :ticket="ticket" />
            </aside>
        </div>
    </div>
</template>
