<script setup lang="ts">
import { Download, Lock, Paperclip } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatBytes } from '@/lib/format';
import type { AttachmentItem } from '@/types/tickets';

const props = defineProps<{ attachments: AttachmentItem[] }>();

const images = computed(() =>
    props.attachments.filter((attachment) => !!attachment.preview_url),
);
const files = computed(() =>
    props.attachments.filter((attachment) => !attachment.preview_url),
);

const previewing = ref<AttachmentItem | null>(null);
const previewOpen = computed({
    get: () => previewing.value !== null,
    set: (open: boolean) => {
        if (!open) {
            previewing.value = null;
        }
    },
});
</script>

<template>
    <div v-if="attachments.length > 0" class="flex flex-col gap-2">
        <ul
            v-if="images.length > 0"
            class="flex flex-wrap gap-2"
            aria-label="Image attachments"
        >
            <li v-for="image in images" :key="image.id">
                <button
                    type="button"
                    class="group relative block overflow-hidden rounded-lg border bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Preview ${image.original_name}`"
                    @click="previewing = image"
                >
                    <img
                        :src="image.preview_url ?? undefined"
                        :alt="image.original_name"
                        loading="lazy"
                        class="h-28 w-40 object-cover transition-transform group-hover:scale-105"
                    />
                    <span
                        v-if="image.is_internal"
                        class="absolute top-1 left-1 rounded-full bg-tint-amber p-1 text-tint-amber-foreground"
                    >
                        <Lock class="size-3" aria-label="Internal" />
                    </span>
                </button>
            </li>
        </ul>

        <ul
            v-if="files.length > 0"
            class="flex flex-wrap gap-2"
            aria-label="Attachments"
        >
            <li
                v-for="attachment in files"
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

        <Dialog v-model:open="previewOpen">
            <DialogContent class="sm:max-w-3xl">
                <template v-if="previewing">
                    <DialogTitle class="truncate pr-6">{{
                        previewing.original_name
                    }}</DialogTitle>
                    <DialogDescription>{{
                        formatBytes(previewing.size_bytes)
                    }}</DialogDescription>
                    <img
                        :src="previewing.preview_url ?? undefined"
                        :alt="previewing.original_name"
                        class="max-h-[70svh] w-full rounded-md bg-muted object-contain"
                    />
                    <Button as-child variant="outline" class="self-end">
                        <a :href="previewing.download_url">
                            <Download aria-hidden="true" />
                            Download
                        </a>
                    </Button>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
