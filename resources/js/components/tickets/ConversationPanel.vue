<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ReplyItem, TicketCan, TicketDetail } from '@/types/tickets';

defineProps<{ ticket: TicketDetail; replies: ReplyItem[]; can: TicketCan }>();
</script>

<template>
    <Card data-stub="conversation-panel">
        <CardHeader>
            <CardTitle class="text-base">Conversation</CardTitle>
        </CardHeader>
        <CardContent class="space-y-3">
            <p
                v-if="replies.length === 0"
                class="text-sm text-muted-foreground"
            >
                No replies yet.
            </p>
            <div
                v-for="reply in replies"
                :key="reply.id"
                class="rounded-md border p-3"
            >
                <p class="text-xs text-muted-foreground">
                    {{ reply.author.name }}
                </p>
                <p class="text-sm whitespace-pre-line">{{ reply.body }}</p>
            </div>
        </CardContent>
    </Card>
</template>
