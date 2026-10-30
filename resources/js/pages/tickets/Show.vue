<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Copy, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { getInitials } from '@/composables/useInitials';
import { formatDateTime, formatRelative } from '@/lib/format';
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
const copied = ref(false);

const page = usePage();
const introIsOwn = computed(
    () => page.props.auth.user.id === props.ticket.customer?.id,
);

async function copyNumber(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.ticket.number);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Head :title="`${ticket.number} · ${ticket.subject}`" />

    <div
        class="grid gap-4 p-4 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start"
    >
        <aside class="flex min-w-0 flex-col gap-4">
            <Card class="gap-3 bg-surface py-4">
                <CardContent class="flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <h2 class="font-mono text-lg font-semibold">
                            {{ ticket.number }}
                        </h2>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            aria-label="Copy ticket number"
                            @click="copyNumber"
                        >
                            <Check v-if="copied" aria-hidden="true" />
                            <Copy v-else aria-hidden="true" />
                        </Button>
                    </div>
                    <p class="text-sm font-medium break-words">
                        {{ ticket.subject }}
                    </p>
                    <p
                        class="text-xs text-muted-foreground"
                        :title="formatDateTime(ticket.created_at)"
                    >
                        Created {{ formatDateTime(ticket.created_at) }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge :status="ticket.status" />
                        <PriorityBadge :priority="ticket.priority" />
                        <SlaBadge :sla="ticket.sla" />
                    </div>
                </CardContent>
            </Card>

            <Card v-if="ticket.customer" class="bg-surface py-4">
                <CardContent class="flex items-center gap-3">
                    <Avatar class="size-10">
                        <AvatarFallback>{{
                            getInitials(ticket.customer.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0">
                        <p class="text-xs text-muted-foreground">Requester</p>
                        <p class="truncate text-sm font-semibold">
                            {{ ticket.customer.name }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <TicketStateControls
                :ticket="ticket"
                :can="can"
                :statuses="options.statuses"
                :priorities="options.priorities"
            />

            <Card class="bg-surface py-4">
                <CardContent>
                    <Tabs default-value="details">
                        <TabsList class="w-full">
                            <TabsTrigger value="details">Details</TabsTrigger>
                            <TabsTrigger value="activity"
                                >Activity{{
                                    activitiesHasMore ? '+' : ''
                                }}</TabsTrigger
                            >
                            <TabsTrigger value="files"
                                >Files ({{ attachments.length }})</TabsTrigger
                            >
                        </TabsList>
                        <TabsContent
                            value="details"
                            class="flex flex-col gap-5 pt-4"
                        >
                            <AssignmentPanel
                                :ticket="ticket"
                                :agents="agents"
                                :can="can"
                            />
                            <TicketDetailsCard :ticket="ticket" />
                        </TabsContent>
                        <TabsContent value="activity" class="pt-4">
                            <ActivityTimeline
                                :activities="activities"
                                :has-more="activitiesHasMore"
                                :loading="loadingMore"
                                @load-more="loadMore('activities')"
                            />
                        </TabsContent>
                        <TabsContent value="files" class="pt-4">
                            <AttachmentList
                                v-if="attachments.length > 0"
                                :attachments="attachments"
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                No files attached.
                            </p>
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>
        </aside>

        <Card
            class="min-w-0 gap-0 overflow-hidden bg-surface py-0 lg:h-[calc(100svh-9rem)] lg:min-h-[32rem]"
        >
            <ConversationPanel
                :ticket="ticket"
                :replies="replies"
                :has-more="repliesHasMore"
                :loading="loadingMore"
                :can="can"
                :attachment-rules="attachmentRules"
                class="max-h-[80svh] lg:max-h-none"
                @load-more="loadMore('replies')"
            >
                <template #intro>
                    <div
                        :class="[
                            'flex flex-col gap-2 rounded-2xl border px-4 py-3 shadow-xs sm:max-w-[75%]',
                            introIsOwn
                                ? 'self-end border-transparent bg-tint-green text-tint-green-foreground'
                                : 'self-start bg-card text-card-foreground',
                        ]"
                    >
                        <div
                            class="flex items-center justify-between gap-2 text-xs"
                        >
                            <span class="text-sm font-semibold">{{
                                ticket.customer?.name ?? 'Customer'
                            }}</span>
                            <Button
                                v-if="can.update && !editing"
                                variant="ghost"
                                size="sm"
                                @click="editing = true"
                            >
                                <Pencil aria-hidden="true" />
                                Edit
                            </Button>
                        </div>
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
                        <AttachmentList
                            v-if="!editing"
                            :attachments="attachments"
                        />
                        <time
                            :datetime="ticket.created_at ?? undefined"
                            class="text-xs opacity-70"
                            >{{ formatRelative(ticket.created_at) }}</time
                        >
                    </div>
                </template>
            </ConversationPanel>
        </Card>
    </div>
</template>
