<script setup lang="ts">
import { Paperclip, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import type { AttachmentRules } from '@/types/tickets';

const files = defineModel<File[]>({ required: true });
const {
    rules,
    id = 'attachments',
    errors = [],
} = defineProps<{ rules: AttachmentRules; id?: string; errors?: string[] }>();

const input = ref<HTMLInputElement | null>(null);
const rejected = ref<string[]>([]);
const previews = ref(new Map<File, string>());

const PREVIEWABLE_TYPES = ['image/png', 'image/jpeg', 'image/gif'];
const accept = computed(() =>
    rules.extensions.map((extension) => `.${extension}`).join(','),
);
const hint = computed(
    () =>
        `Up to ${rules.max_files} files, ${formatBytes(rules.max_kb * 1024)} each. Allowed: ${rules.extensions.join(', ')}.`,
);

function rejectionFor(file: File): string | null {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (!rules.extensions.includes(extension)) {
        return `${file.name}: this file type is not allowed.`;
    }

    if (file.size > rules.max_kb * 1024) {
        return `${file.name}: larger than ${formatBytes(rules.max_kb * 1024)}.`;
    }

    return null;
}

function add(event: Event): void {
    const target = event.target as HTMLInputElement;
    const messages: string[] = [];
    const accepted: File[] = [];

    for (const file of Array.from(target.files ?? [])) {
        const message = rejectionFor(file);

        if (message !== null) {
            messages.push(message);
        } else if (files.value.length + accepted.length >= rules.max_files) {
            messages.push(
                `${file.name}: you can attach up to ${rules.max_files} files.`,
            );
        } else {
            accepted.push(file);
        }
    }

    rejected.value = messages;
    files.value = [...files.value, ...accepted];
    target.value = '';
}

function previewFor(file: File): string | null {
    return previews.value.get(file) ?? null;
}

watch(
    files,
    (current) => {
        for (const [file, url] of previews.value) {
            if (!current.includes(file)) {
                URL.revokeObjectURL(url);
                previews.value.delete(file);
            }
        }

        for (const file of current) {
            if (
                PREVIEWABLE_TYPES.includes(file.type) &&
                !previews.value.has(file)
            ) {
                previews.value.set(file, URL.createObjectURL(file));
            }
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    for (const url of previews.value.values()) {
        URL.revokeObjectURL(url);
    }
});

function remove(index: number): void {
    files.value = files.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="grid gap-2">
        <input
            :id="id"
            ref="input"
            type="file"
            multiple
            :accept="accept"
            class="sr-only"
            :aria-describedby="`${id}-hint`"
            @change="add"
        />
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="files.length >= rules.max_files"
                @click="input?.click()"
            >
                <Paperclip aria-hidden="true" />
                Attach files
            </Button>
            <p :id="`${id}-hint`" class="min-w-0 text-xs text-muted-foreground">
                {{ hint }}
            </p>
        </div>
        <ul v-if="files.length > 0" class="grid gap-1">
            <li
                v-for="(file, index) in files"
                :key="`${file.name}-${index}`"
                class="flex items-center gap-2 rounded-full border bg-muted px-3 py-1 text-sm"
            >
                <img
                    v-if="previewFor(file)"
                    :src="previewFor(file) ?? undefined"
                    :alt="file.name"
                    class="size-8 shrink-0 rounded object-cover"
                />
                <span class="min-w-0 flex-1 truncate">{{ file.name }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{
                    formatBytes(file.size)
                }}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    :aria-label="`Remove ${file.name}`"
                    @click="remove(index)"
                >
                    <X aria-hidden="true" />
                </Button>
            </li>
        </ul>
        <InputError
            v-for="message in rejected"
            :key="`rejected-${message}`"
            :message="message"
        />
        <InputError
            v-for="message in errors"
            :key="message"
            :message="message"
        />
    </div>
</template>
