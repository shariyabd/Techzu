<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Lock, MessageSquare } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import AttachmentList from '@/components/tickets/AttachmentList.vue';
import ReplyComposer from '@/components/tickets/ReplyComposer.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { formatDateTime, formatRelative } from '@/lib/format';
import type {
    AttachmentRules,
    ReplyItem,
    TicketCan,
    TicketDetail,
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
const viewerId = computed(() => page.props.auth.user.id);
const scroller = ref<HTMLElement | null>(null);

const isClosed = computed(() => props.ticket.status.value === 'closed');
const isCustomer = (reply: ReplyItem): boolean =>
    reply.author.id === props.ticket.customer?.id;
const isOwn = (reply: ReplyItem): boolean => reply.author.id === viewerId.value;

const lastReplyId = computed(() => props.replies.at(-1)?.id ?? null);

function scrollToEnd(): void {
    nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    });
}

onMounted(scrollToEnd);
watch(lastReplyId, scrollToEnd);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            ref="scroller"
            class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto bg-background/60 p-4"
        >
            <Button
                v-if="hasMore"
                variant="outline"
                size="sm"
                class="self-center"
                :disabled="loading"
                @click="emit('loadMore')"
            >
                Load older replies
            </Button>

            <slot name="intro" />

            <EmptyState
                v-if="replies.length === 0 && !hasMore"
                :icon="MessageSquare"
                title="No replies yet"
                :description="
                    can.reply ? 'Start the conversation below.' : undefined
                "
            />

            <ol
                v-if="replies.length > 0"
                class="flex flex-col gap-4"
                aria-label="Conversation"
            >
                <li
                    v-for="reply in replies"
                    :key="reply.id"
                    :data-internal="reply.is_internal ? 'true' : undefined"
                    :class="[
                        'flex items-end gap-2',
                        isOwn(reply) ? 'flex-row-reverse' : 'flex-row',
                    ]"
                >
                    <Avatar class="size-8 shrink-0">
                        <AvatarFallback class="text-xs">{{
                            getInitials(reply.author.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div
                        :class="[
                            'max-w-[85%] min-w-0 rounded-2xl border px-4 py-3 shadow-xs sm:max-w-[75%]',
                            reply.is_internal
                                ? 'border-dashed border-tint-amber-border bg-tint-amber text-tint-amber-foreground'
                                : isOwn(reply)
                                  ? 'border-transparent bg-tint-green text-tint-green-foreground'
                                  : 'bg-card text-card-foreground',
                        ]"
                    >
                        <div
                            class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
                        >
                            <span class="text-sm font-semibold">{{
                                reply.author.name
                            }}</span>
                            <span class="opacity-70">{{
                                isCustomer(reply) ? 'Customer' : 'Staff'
                            }}</span>
                            <span
                                v-if="reply.is_internal"
                                class="inline-flex items-center gap-1 rounded-full border border-tint-amber-border px-2 py-0.5 font-medium"
                            >
                                <Lock class="size-3" aria-hidden="true" />
                                Internal note
                            </span>
                        </div>
                        <p class="text-sm break-words whitespace-pre-line">
                            {{ reply.body }}
                        </p>
                        <AttachmentList
                            v-if="reply.attachments.length > 0"
                            class="mt-3"
                            :attachments="reply.attachments"
                        />
                        <time
                            :datetime="reply.created_at ?? undefined"
                            :title="formatDateTime(reply.created_at)"
                            class="mt-2 block text-xs opacity-70"
                            >{{ formatRelative(reply.created_at) }}</time
                        >
                    </div>
                </li>
            </ol>
        </div>

        <p
            v-if="isClosed"
            class="border-t bg-muted px-4 py-3 text-sm text-muted-foreground"
        >
            This ticket is closed and no longer accepts replies.
        </p>
        <ReplyComposer
            v-else-if="can.reply"
            :ticket="ticket"
            :can="can"
            :attachment-rules="attachmentRules"
        />
    </div>
</template>
