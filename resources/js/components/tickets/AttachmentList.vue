<script setup lang="ts">
import { Download, Lock, Paperclip } from '@lucide/vue';
import { formatBytes } from '@/lib/format';
import type { AttachmentItem } from '@/types/tickets';

defineProps<{ attachments: AttachmentItem[] }>();
</script>

<template>
    <ul
        v-if="attachments.length > 0"
        class="flex flex-wrap gap-2"
        aria-label="Attachments"
    >
        <li
            v-for="attachment in attachments"
            :key="attachment.id"
            class="max-w-full"
        >
            <a
                :href="attachment.download_url"
                class="group inline-flex max-w-full items-center gap-2 rounded-full border bg-surface px-3 py-1.5 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Lock
                    v-if="attachment.is_internal"
                    class="size-4 shrink-0 text-tint-amber-foreground"
                    aria-label="Internal"
                />
                <Paperclip
                    v-else
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="truncate">{{ attachment.original_name }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{
                    formatBytes(attachment.size_bytes)
                }}</span>
                <Download
                    class="size-4 shrink-0 text-muted-foreground group-hover:text-foreground"
                    aria-hidden="true"
                />
                <span class="sr-only">Download</span>
            </a>
        </li>
    </ul>
</template>
