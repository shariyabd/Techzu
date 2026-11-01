<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Lock, MessageSquare } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ChatHeader from '@/components/chat/ChatHeader.vue';
import NewMessagesPill from '@/components/chat/NewMessagesPill.vue';
import TypingIndicator from '@/components/chat/TypingIndicator.vue';
import UnreadDivider from '@/components/chat/UnreadDivider.vue';
import EmptyState from '@/components/EmptyState.vue';
import AttachmentList from '@/components/tickets/AttachmentList.vue';
import ReplyComposer from '@/components/tickets/ReplyComposer.vue';
import type { ReplyDraft } from '@/components/tickets/ReplyComposer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import UserAvatar from '@/components/UserAvatar.vue';
import { useChatScroll } from '@/composables/useChatScroll';
import {
    latestSeen,
    presenceStatus,
    usePresenceClock,
    useTicketPresence,
} from '@/composables/usePresence';
import type { PresenceRole, PresenceStatus } from '@/composables/usePresence';
import { realtimeChannels } from '@/composables/useRealtime';
import { useTyping } from '@/composables/useTyping';
import { formatDateTime, formatRelative } from '@/lib/format';
import type {
    AttachmentRules,
    ReplyItem,
    TicketCan,
    TicketDetail,
    UserSummary,
} from '@/types/tickets';

const props = defineProps<{
    ticket: TicketDetail;
    replies: ReplyItem[];
    hasMore: boolean;
    loading: boolean;
    can: TicketCan;
    attachmentRules: AttachmentRules;
}>();

const emit = defineEmits<{ loadMore: [] }>();

const page = usePage();
const viewer = computed(() => page.props.auth.user);
const scroller = ref<HTMLElement | null>(null);
const content = ref<HTMLElement | null>(null);

const isClosed = computed(() => props.ticket.status.value === 'closed');
const viewerIsCustomer = computed(
    () => viewer.value.id === props.ticket.customer?.id,
);
const viewerRole = computed<PresenceRole>(() =>
    viewerIsCustomer.value ? 'customer' : 'staff',
);
const isCustomer = (reply: ReplyItem): boolean =>
    reply.author.id === props.ticket.customer?.id;
const isOnViewerSide = (reply: ReplyItem): boolean =>
    isCustomer(reply) === viewerIsCustomer.value;
const isOwn = (reply: ReplyItem): boolean =>
    reply.author.id === viewer.value.id;
const avatarOf = (user: UserSummary | null | undefined): string | null =>
    user?.avatar_url ?? null;
const highestId = (replies: ReplyItem[]): number =>
    replies.reduce((highest, reply) => Math.max(highest, reply.id), 0);

const presence = useTicketPresence(() => props.ticket.number);
const typing = useTyping({
    presence: presence.channel,
    members: presence.members,
    staffChannel: () =>
        props.can.addInternalNote
            ? realtimeChannels.ticketStaff(props.ticket.number)
            : null,
    self: () => ({
        id: viewer.value.id,
        name: viewer.value.name,
        role: viewerRole.value,
    }),
});
const now = usePresenceClock();

function statusOf(user: UserSummary): PresenceStatus {
    return presenceStatus(
        latestSeen(user.last_seen_at, presence.departedAt.value[user.id]),
        presence.isLive(user.id),
        now.value,
    );
}

const counterpart = computed(() => {
    if (!viewerIsCustomer.value) {
        const customer: UserSummary | null = props.ticket.customer;
        const status = customer
            ? statusOf(customer)
            : presenceStatus(null, false, now.value);

        return {
            name: customer?.name ?? 'Customer',
            avatarUrl: avatarOf(customer),
            status,
            label: status.label,
        };
    }

    const agent: UserSummary | null = props.ticket.assigned_agent;

    if (agent) {
        const status = statusOf(agent);

        return {
            name: agent.name,
            avatarUrl: avatarOf(agent),
            status,
            label: status.active
                ? 'Your agent is active now'
                : `Your agent · ${status.label}`,
        };
    }

    const live = presence.hasLiveStaff.value;

    return {
        name: 'Support team',
        avatarUrl: null,
        status: { active: live, label: live ? 'Active now' : 'Offline' },
        label: live ? 'An agent is active now' : 'An agent will reply soon',
    };
});

const visibleTypers = computed(() =>
    typing.typers.value
        .filter((typer) => typer.mode === 'public' || !viewerIsCustomer.value)
        .slice(0, 3),
);

const baselineId = highestId(props.replies);
const isFresh = (reply: ReplyItem): boolean => reply.id > baselineId;
const firstUnreadId = computed(
    () =>
        props.replies.find((reply) => isFresh(reply) && !isOwn(reply))?.id ??
        null,
);

const pending = ref<(ReplyDraft & { afterId: number }) | null>(null);
const showPending = computed(() => {
    const draft = pending.value;

    return (
        draft !== null &&
        !props.replies.some((reply) => reply.id > draft.afterId && isOwn(reply))
    );
});

const unseen = ref(0);
const showOlderSkeleton = ref(false);
let loadingOlder = false;

const scroll = useChatScroll(scroller, content);

function loadOlder(): void {
    loadingOlder = true;
    showOlderSkeleton.value = true;
    emit('loadMore');
}

watch(
    () => props.loading,
    (loading) => {
        if (!loading) {
            showOlderSkeleton.value = false;
        }
    },
);

function jumpToLatest(): void {
    unseen.value = 0;
    scroll.scrollToBottom(true);
}

watch(
    () => props.replies,
    (next, previous) => {
        const preserve = loadingOlder;
        loadingOlder = false;
        showOlderSkeleton.value = false;
        scroll.captureBeforeUpdate(preserve);

        const known = new Set(previous.map((reply) => reply.id));
        const previousHighest = highestId(previous);
        const appended = next.filter(
            (reply) => !known.has(reply.id) && reply.id > previousHighest,
        );

        appended.forEach((reply) => typing.clearAuthor(reply.author.id));

        nextTick(() => {
            const stuck = scroll.restoreAfterUpdate();

            if (appended.some(isOwn) || (stuck && appended.length > 0)) {
                jumpToLatest();

                return;
            }

            if (stuck) {
                scroll.scrollToBottom();

                return;
            }

            unseen.value += appended.length;
        });
    },
    { flush: 'pre' },
);

watch(scroll.nearBottom, (near) => {
    if (near) {
        unseen.value = 0;
    }
});

watch(
    () => visibleTypers.value.length,
    (count, previous) => {
        if (count > previous && scroll.nearBottom.value) {
            nextTick(() => scroll.scrollToBottom(true));
        }
    },
);

function onSending(draft: ReplyDraft): void {
    pending.value = { ...draft, afterId: highestId(props.replies) };
    nextTick(() => jumpToLatest());
}

function onSettled(): void {
    pending.value = null;
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <ChatHeader
            :name="counterpart.name"
            :avatar-url="counterpart.avatarUrl"
            :status="counterpart.status"
            :status-label="counterpart.label"
            :context="ticket.number"
        />

        <div class="relative min-h-0 flex-1">
            <div
                ref="scroller"
                class="absolute inset-0 overflow-y-auto overscroll-contain bg-background/60"
                @scroll.passive="scroll.onScroll"
            >
                <div ref="content" class="flex flex-col gap-4 p-4">
                    <slot name="intro" />

                    <div
                        v-if="showOlderSkeleton"
                        class="flex flex-col gap-4"
                        aria-label="Loading older replies"
                        aria-busy="true"
                    >
                        <div
                            v-for="(width, row) in ['w-2/3', 'w-1/2']"
                            :key="row"
                            :class="[
                                'flex items-end gap-2',
                                row % 2 === 0 ? 'flex-row' : 'flex-row-reverse',
                            ]"
                        >
                            <Skeleton class="size-8 shrink-0 rounded-full" />
                            <Skeleton :class="['h-16 rounded-2xl', width]" />
                        </div>
                    </div>
                    <Button
                        v-else-if="hasMore"
                        variant="outline"
                        size="sm"
                        class="self-center"
                        :disabled="loading"
                        @click="loadOlder"
                    >
                        Load older replies
                    </Button>

                    <EmptyState
                        v-if="replies.length === 0 && !hasMore"
                        :icon="MessageSquare"
                        title="No replies yet"
                        :description="
                            can.reply
                                ? 'Start the conversation below.'
                                : undefined
                        "
                    />

                    <ol
                        v-if="replies.length > 0"
                        class="flex flex-col gap-4"
                        aria-label="Conversation"
                    >
                        <template v-for="reply in replies" :key="reply.id">
                            <li v-if="reply.id === firstUnreadId">
                                <UnreadDivider />
                            </li>
                            <li
                                :data-chat-anchor="reply.id"
                                :data-internal="
                                    reply.is_internal ? 'true' : undefined
                                "
                                :class="[
                                    'flex items-end gap-2',
                                    isOnViewerSide(reply)
                                        ? 'flex-row-reverse'
                                        : 'flex-row',
                                    isFresh(reply)
                                        ? 'motion-safe:animate-in motion-safe:duration-300 motion-safe:fade-in motion-safe:slide-in-from-bottom-2'
                                        : '',
                                ]"
                            >
                                <UserAvatar
                                    :name="reply.author.name"
                                    :src="avatarOf(reply.author)"
                                    :fallback-class="
                                        isCustomer(reply)
                                            ? 'bg-muted text-muted-foreground'
                                            : 'bg-primary/15 text-primary'
                                    "
                                />
                                <div
                                    :class="[
                                        'max-w-[85%] min-w-0 rounded-2xl border px-4 py-3 shadow-xs sm:max-w-[75%]',
                                        reply.is_internal
                                            ? 'border-dashed border-tint-amber-border bg-tint-amber text-tint-amber-foreground'
                                            : isOnViewerSide(reply)
                                              ? 'border-transparent bg-tint-green text-tint-green-foreground'
                                              : 'bg-card text-card-foreground',
                                    ]"
                                >
                                    <div
                                        class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
                                    >
                                        <span class="text-sm font-semibold">{{
                                            isOwn(reply)
                                                ? 'You'
                                                : reply.author.name
                                        }}</span>
                                        <span class="opacity-70">{{
                                            isCustomer(reply)
                                                ? 'Customer'
                                                : 'Support'
                                        }}</span>
                                        <span
                                            v-if="reply.is_internal"
                                            class="inline-flex items-center gap-1 rounded-full border border-tint-amber-border px-2 py-0.5 font-medium"
                                        >
                                            <Lock
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            Internal note
                                        </span>
                                    </div>
                                    <p
                                        class="text-sm break-words whitespace-pre-line"
                                    >
                                        {{ reply.body }}
                                    </p>
                                    <AttachmentList
                                        v-if="reply.attachments.length > 0"
                                        class="mt-3"
                                        :attachments="reply.attachments"
                                    />
                                    <time
                                        :datetime="
                                            reply.created_at ?? undefined
                                        "
                                        :title="
                                            formatDateTime(reply.created_at)
                                        "
                                        class="mt-2 block text-xs opacity-70"
                                        >{{
                                            formatRelative(reply.created_at)
                                        }}</time
                                    >
                                </div>
                            </li>
                        </template>
                    </ol>

                    <div
                        v-if="showPending && pending"
                        class="flex flex-row-reverse items-end gap-2 opacity-70"
                        data-pending-reply
                    >
                        <div
                            :class="[
                                'max-w-[85%] min-w-0 rounded-2xl border px-4 py-3 shadow-xs sm:max-w-[75%]',
                                pending.isInternal
                                    ? 'border-dashed border-tint-amber-border bg-tint-amber text-tint-amber-foreground'
                                    : 'border-transparent bg-tint-green text-tint-green-foreground',
                            ]"
                        >
                            <p class="text-sm break-words whitespace-pre-line">
                                {{ pending.body }}
                            </p>
                            <p class="mt-2 text-xs opacity-70">Sending…</p>
                        </div>
                    </div>

                    <div
                        v-if="visibleTypers.length > 0"
                        class="flex flex-col gap-2"
                        aria-live="polite"
                    >
                        <TypingIndicator
                            v-for="typer in visibleTypers"
                            :key="typer.key"
                            :typer="typer"
                        />
                    </div>
                </div>
            </div>

            <NewMessagesPill
                v-if="unseen > 0"
                :count="unseen"
                class="absolute bottom-3 left-1/2 z-10 -translate-x-1/2"
                @jump="jumpToLatest"
            />
        </div>

        <p
            v-if="isClosed"
            class="shrink-0 border-t bg-muted px-4 py-3 text-sm text-muted-foreground"
        >
            This ticket is closed and no longer accepts replies.
        </p>
        <ReplyComposer
            v-else-if="can.reply"
            :ticket="ticket"
            :can="can"
            :attachment-rules="attachmentRules"
            @typing="typing.notify"
            @typing-stopped="typing.stop"
            @sending="onSending"
            @settled="onSettled"
        />
    </div>
</template>
