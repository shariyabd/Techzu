<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Copy, Pencil } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
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
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import PresenceAvatar from '@/components/chat/PresenceAvatar.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import {
    latestSeen,
    presenceStatus,
    usePresenceClock,
} from '@/composables/usePresence';
import { realtimeChannels, useRealtimeReload } from '@/composables/useRealtime';
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
    UserSummary,
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
const loadingMore = ref<'replies' | 'activities' | null>(null);

function loadMore(kind: 'replies' | 'activities'): void {
    const pages = kind === 'replies' ? repliesPages : activitiesPages;
    pages.value += 1;
    router.reload({
        data: { [`${kind}_pages`]: pages.value },
        only:
            kind === 'replies'
                ? ['replies', 'repliesHasMore']
                : ['activities', 'activitiesHasMore'],
        onStart: () => (loadingMore.value = kind),
        onFinish: () => (loadingMore.value = null),
    });
}

useRealtimeReload(
    () =>
        props.can.addInternalNote
            ? [
                  realtimeChannels.ticket(props.ticket.number),
                  realtimeChannels.ticketStaff(props.ticket.number),
              ]
            : [realtimeChannels.ticket(props.ticket.number)],
    ['ticket.changed', 'ticket.internal-note'],
    () => ({
        data: {
            replies_pages: repliesPages.value,
            activities_pages: activitiesPages.value,
        },
        only: [
            'ticket',
            'replies',
            'repliesHasMore',
            'attachments',
            'activities',
            'activitiesHasMore',
            'agents',
            'can',
        ],
    }),
);

const editing = ref(false);
const editingDetails = ref(false);
const copied = ref(false);

const page = usePage();
const introIsOwn = computed(
    () => page.props.auth.user.id === props.ticket.customer?.id,
);
const customer = computed<UserSummary | null>(() => props.ticket.customer);
const now = usePresenceClock();
const customerStatus = computed(() =>
    presenceStatus(latestSeen(customer.value?.last_seen_at), false, now.value),
);

const workspace = ref<HTMLElement | null>(null);
const workspaceTop = ref<string | undefined>(undefined);

function measureWorkspace(): void {
    if (!workspace.value) {
        return;
    }

    const top = workspace.value.getBoundingClientRect().top + window.scrollY;
    workspaceTop.value = `${Math.max(0, Math.round(top))}px`;
}

onMounted(measureWorkspace);
useEventListener('resize', measureWorkspace, { passive: true });

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
        ref="workspace"
        class="grid gap-4 p-4 lg:h-[calc(100svh-var(--workspace-top,6.5rem))] lg:min-h-[30rem] lg:grid-cols-[340px_minmax(0,1fr)] lg:grid-rows-[minmax(0,1fr)] lg:overflow-hidden"
        :style="workspaceTop ? { '--workspace-top': workspaceTop } : undefined"
    >
        <aside
            class="flex min-w-0 flex-col gap-4 lg:min-h-0 lg:overflow-y-auto lg:overscroll-contain lg:pr-1 lg:pb-1"
            aria-label="Ticket details"
        >
            <Card class="gap-3 bg-surface py-4">
                <CardContent class="flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <h2 class="font-mono text-lg font-semibold">
                            {{ ticket.number }}
                        </h2>
                        <Button
                            v-if="
                                can.update &&
                                !can.editDescription &&
                                !editingDetails
                            "
                            variant="ghost"
                            size="sm"
                            class="ml-auto"
                            @click="editingDetails = true"
                        >
                            <Pencil aria-hidden="true" />
                            Edit
                        </Button>
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
                    <TicketEditForm
                        v-if="editingDetails"
                        :ticket="ticket"
                        :can="can"
                        :categories="options.categories"
                        @done="editingDetails = false"
                    />
                    <p v-else class="text-sm font-medium break-words">
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
                    <PresenceAvatar
                        :name="ticket.customer.name"
                        :src="customer?.avatar_url"
                        :active="!introIsOwn && customerStatus.active"
                        class="size-10"
                    />
                    <div class="min-w-0">
                        <p class="text-xs text-muted-foreground">Requester</p>
                        <p class="truncate text-sm font-semibold">
                            {{ ticket.customer.name }}
                        </p>
                        <p
                            v-if="!introIsOwn"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ customerStatus.label }}
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
                                :loading="loadingMore === 'activities'"
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
            class="h-[75svh] min-h-[28rem] min-w-0 gap-0 overflow-hidden bg-surface py-0 lg:h-auto lg:min-h-0"
        >
            <ConversationPanel
                :ticket="ticket"
                :replies="replies"
                :has-more="repliesHasMore"
                :loading="loadingMore === 'replies'"
                :can="can"
                :attachment-rules="attachmentRules"
                @load-more="loadMore('replies')"
            >
                <template #intro>
                    <div
                        :class="[
                            'flex items-end gap-2',
                            introIsOwn ? 'flex-row-reverse' : 'flex-row',
                        ]"
                    >
                        <UserAvatar
                            :name="ticket.customer?.name ?? 'Customer'"
                            :src="customer?.avatar_url"
                        />
                        <div
                            :class="[
                                'flex max-w-[85%] min-w-0 flex-col gap-2 rounded-2xl border px-4 py-3 shadow-xs sm:max-w-[75%]',
                                introIsOwn
                                    ? 'border-transparent bg-tint-green text-tint-green-foreground'
                                    : 'bg-card text-card-foreground',
                            ]"
                        >
                            <div
                                class="flex items-center justify-between gap-2 text-xs"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="text-sm font-semibold">{{
                                        introIsOwn
                                            ? 'You'
                                            : (ticket.customer?.name ??
                                              'Customer')
                                    }}</span>
                                    <span class="opacity-70">Customer</span>
                                </span>
                                <Button
                                    v-if="can.editDescription && !editing"
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
                    </div>
                </template>
            </ConversationPanel>
        </Card>
    </div>
</template>
