<script setup lang="ts">
import { Lock, MessageSquare } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import AttachmentList from '@/components/tickets/AttachmentList.vue';
import ReplyComposer from '@/components/tickets/ReplyComposer.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
    can: TicketCan;
    attachmentRules: AttachmentRules;
}>();

const isClosed = computed(() => props.ticket.status.value === 'closed');
const isCustomer = (reply: ReplyItem): boolean =>
    reply.author.id === props.ticket.customer?.id;
</script>

<template>
    <div class="flex flex-col gap-4">
        <EmptyState
            v-if="replies.length === 0"
            :icon="MessageSquare"
            title="No replies yet"
            :description="
                can.reply ? 'Start the conversation below.' : undefined
            "
        />
        <ol v-else class="flex flex-col gap-3" aria-label="Conversation">
            <li
                v-for="reply in replies"
                :key="reply.id"
                :data-internal="reply.is_internal ? 'true' : undefined"
                :class="[
                    'flex gap-3 rounded-lg border p-3 sm:p-4',
                    reply.is_internal
                        ? 'border-dashed border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/40'
                        : isCustomer(reply)
                          ? 'bg-muted/40'
                          : 'bg-card',
                ]"
            >
                <Avatar class="size-8 shrink-0">
                    <AvatarFallback class="text-xs">{{
                        getInitials(reply.author.name)
                    }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <div
                        class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
                    >
                        <span class="font-medium">{{ reply.author.name }}</span>
                        <span
                            v-if="isCustomer(reply)"
                            class="text-xs text-muted-foreground"
                            >Customer</span
                        >
                        <time
                            :datetime="reply.created_at ?? undefined"
                            :title="formatDateTime(reply.created_at)"
                            class="text-xs text-muted-foreground"
                            >{{ formatRelative(reply.created_at) }}</time
                        >
                        <span
                            v-if="reply.is_internal"
                            class="inline-flex items-center gap-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-800 dark:text-amber-100"
                        >
                            <Lock class="size-3" aria-hidden="true" />
                            Internal note · not visible to the customer
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
                </div>
            </li>
        </ol>

        <p
            v-if="isClosed"
            class="rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground"
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
