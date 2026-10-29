<script setup lang="ts">
import type { AttachmentItem } from '@/types/tickets';

defineProps<{ attachments: AttachmentItem[] }>();
</script>

<template>
    <ul
        v-if="attachments.length > 0"
        class="space-y-1 text-sm"
        data-stub="attachment-list"
    >
        <li v-for="attachment in attachments" :key="attachment.id">
            {{ attachment.original_name }}
        </li>
    </ul>
</template>
