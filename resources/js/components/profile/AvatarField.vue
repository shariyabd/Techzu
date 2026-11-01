<script setup lang="ts">
import { ImageUp, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import UserAvatar from '@/components/UserAvatar.vue';
import type { AvatarRules } from '@/types/users';

const props = defineProps<{
    name: string;
    src: string | null;
    rules: AvatarRules;
    pending?: File | null;
    error?: string;
    processing?: boolean;
}>();

const emit = defineEmits<{
    select: [file: File];
    remove: [];
}>();

const input = useTemplateRef<HTMLInputElement>('input');
const localError = ref<string | undefined>();
const previewUrl = ref<string | null>(null);

const accept = computed(() =>
    props.rules.extensions.map((extension) => `.${extension}`).join(','),
);
const hint = computed(
    () =>
        `${props.rules.extensions.map((extension) => extension.toUpperCase()).join(', ')} up to ${Math.round(props.rules.max_kb / 1024)} MB, at least ${props.rules.min_dimension}×${props.rules.min_dimension} px.`,
);
const shown = computed(() => previewUrl.value ?? props.src);

function revokePreview(): void {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }

    previewUrl.value = null;
}

watch(
    () => props.pending,
    (file) => {
        revokePreview();

        if (file) {
            previewUrl.value = URL.createObjectURL(file);
        }
    },
);

onBeforeUnmount(revokePreview);

function validate(file: File): string | undefined {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (!props.rules.extensions.includes(extension)) {
        return `Choose a ${props.rules.extensions.join(', ')} image.`;
    }

    if (file.size > props.rules.max_kb * 1024) {
        return `The image may not be larger than ${Math.round(props.rules.max_kb / 1024)} MB.`;
    }

    return undefined;
}

function onChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = '';

    if (!file) {
        return;
    }

    localError.value = validate(file);

    if (localError.value === undefined) {
        emit('select', file);
    }
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-center gap-4">
            <UserAvatar
                :name="name"
                :src="shown"
                class="size-20 text-lg"
                fallback-class="text-2xl"
            />
            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="processing"
                        @click="input?.click()"
                    >
                        <Spinner v-if="processing" />
                        <ImageUp v-else aria-hidden="true" />
                        {{ shown ? 'Replace image' : 'Upload image' }}
                    </Button>
                    <Button
                        v-if="shown"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="processing"
                        @click="emit('remove')"
                    >
                        <Trash2 aria-hidden="true" />
                        Remove
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">{{ hint }}</p>
            </div>
        </div>
        <input
            ref="input"
            type="file"
            class="sr-only"
            :accept="accept"
            aria-label="Profile image"
            tabindex="-1"
            @change="onChange"
        />
        <InputError :message="localError ?? error" />
    </div>
</template>
