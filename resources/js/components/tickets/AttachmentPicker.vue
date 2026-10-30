<script setup lang="ts">
import { Paperclip, X } from '@lucide/vue';
import { computed, ref } from 'vue';
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
const accept = computed(() =>
    rules.extensions.map((extension) => `.${extension}`).join(','),
);
const hint = computed(
    () =>
        `Up to ${rules.max_files} files, ${formatBytes(rules.max_kb * 1024)} each. Allowed: ${rules.extensions.join(', ')}.`,
);

function add(event: Event): void {
    const target = event.target as HTMLInputElement;
    files.value = [...files.value, ...Array.from(target.files ?? [])];
    target.value = '';
}

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
            v-for="message in errors"
            :key="message"
            :message="message"
        />
    </div>
</template>
