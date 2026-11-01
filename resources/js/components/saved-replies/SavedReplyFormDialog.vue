<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PlaceholderLegend from '@/components/saved-replies/PlaceholderLegend.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    renderSavedReply,
    sampleSavedReplyContext,
    unknownPlaceholders,
} from '@/lib/savedReplies';
import { store, update } from '@/routes/saved-replies';
import type {
    SavedReply,
    SavedReplyCategoryOption,
} from '@/types/saved-replies';

const props = defineProps<{
    reply: SavedReply | null;
    categories: SavedReplyCategoryOption[];
    canManageShared: boolean;
    defaultShared: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const TITLE_MAX = 120;
const BODY_MAX = 5000;

const form = useForm({
    title: '',
    body: '',
    category_id: '' as number | '',
    shared: false,
});

const body = ref<InstanceType<typeof Textarea> | null>(null);

const isEditing = computed(() => props.reply !== null);

const categoryOptions = computed<SavedReplyCategoryOption[]>(() => {
    const current = props.reply?.category;

    if (
        current &&
        !props.categories.some((category) => category.id === current.id)
    ) {
        return [...props.categories, current];
    }

    return props.categories;
});

const preview = computed(() =>
    renderSavedReply(form.body, sampleSavedReplyContext),
);

const unknown = computed(() => unknownPlaceholders(form.body));

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        title: props.reply?.title ?? '',
        body: props.reply?.body ?? '',
        category_id: props.reply?.category_id ?? '',
        shared: props.reply
            ? props.reply.scope === 'team'
            : props.defaultShared,
    });
    form.reset();
});

function textareaElement(): HTMLTextAreaElement | null {
    return (body.value?.$el ?? null) as HTMLTextAreaElement | null;
}

function insertToken(token: string): void {
    const element = textareaElement();
    const start = element?.selectionStart ?? form.body.length;
    const end = element?.selectionEnd ?? form.body.length;

    form.body = form.body.slice(0, start) + token + form.body.slice(end);

    void nextTick(() => {
        element?.focus();
        element?.setSelectionRange(start + token.length, start + token.length);
    });
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    form.transform((data) => ({
        title: data.title,
        body: data.body,
        category_id: data.category_id === '' ? null : data.category_id,
        ...(isEditing.value ? {} : { shared: data.shared }),
    }));

    if (props.reply) {
        form.patch(update.url(props.reply.id), options);
    } else {
        form.post(store.url(), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-3xl"
        >
            <DialogHeader>
                <DialogTitle>{{
                    isEditing ? 'Edit saved reply' : 'New saved reply'
                }}</DialogTitle>
                <DialogDescription
                    >Placeholders are filled in with the ticket’s details when
                    the reply is inserted.</DialogDescription
                >
            </DialogHeader>

            <form
                id="saved-reply-form"
                class="grid gap-5 md:grid-cols-2"
                @submit.prevent="submit"
            >
                <div class="flex min-w-0 flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="saved-reply-title">Title</Label>
                        <Input
                            id="saved-reply-title"
                            v-model="form.title"
                            required
                            :maxlength="TITLE_MAX"
                            :aria-invalid="form.errors.title ? true : undefined"
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="saved-reply-body">Reply text</Label>
                        <Textarea
                            id="saved-reply-body"
                            ref="body"
                            v-model="form.body"
                            required
                            rows="8"
                            :maxlength="BODY_MAX"
                            class="min-h-40"
                            aria-describedby="saved-reply-body-help"
                            :aria-invalid="
                                form.errors.body || unknown.length > 0
                                    ? true
                                    : undefined
                            "
                        />
                        <p
                            id="saved-reply-body-help"
                            class="flex justify-between gap-2 text-xs text-muted-foreground"
                        >
                            <span>Click a placeholder below to insert it.</span>
                            <span class="tabular-nums"
                                >{{ form.body.length }}/{{ BODY_MAX }}</span
                            >
                        </p>
                        <p
                            v-if="unknown.length > 0"
                            class="text-sm text-amber-700 dark:text-amber-400"
                            role="status"
                        >
                            Unknown placeholder{{
                                unknown.length === 1 ? '' : 's'
                            }}: {{ unknown.join(', ') }}
                        </p>
                        <InputError :message="form.errors.body" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="saved-reply-category"
                            >Suggest for category</Label
                        >
                        <NativeSelect
                            id="saved-reply-category"
                            v-model="form.category_id"
                        >
                            <NativeSelectOption value=""
                                >Any category</NativeSelectOption
                            >
                            <NativeSelectOption
                                v-for="category in categoryOptions"
                                :key="category.id"
                                :value="category.id"
                                >{{ category.name }}</NativeSelectOption
                            >
                        </NativeSelect>
                        <InputError :message="form.errors.category_id" />
                    </div>

                    <div
                        v-if="!isEditing && canManageShared"
                        class="flex items-start gap-3"
                    >
                        <Switch id="saved-reply-shared" v-model="form.shared" />
                        <div class="grid gap-1">
                            <Label for="saved-reply-shared"
                                >Share with the team</Label
                            >
                            <p class="text-xs text-muted-foreground">
                                Team replies are available to every agent.
                            </p>
                        </div>
                    </div>
                    <InputError :message="form.errors.shared" />
                </div>

                <div class="flex min-w-0 flex-col gap-4">
                    <section
                        class="grid gap-2"
                        aria-labelledby="saved-reply-preview-label"
                    >
                        <h3
                            id="saved-reply-preview-label"
                            class="text-sm font-medium"
                        >
                            Preview
                        </h3>
                        <div
                            class="min-h-40 rounded-md border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
                            aria-live="polite"
                        >
                            <span v-if="preview">{{ preview }}</span>
                            <span v-else class="text-muted-foreground"
                                >Start typing to see the reply as a customer
                                would.</span
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Preview uses sample ticket details.
                        </p>
                    </section>
                    <section class="grid gap-2" aria-label="Placeholders">
                        <h3 class="text-sm font-medium">Placeholders</h3>
                        <PlaceholderLegend interactive @pick="insertToken" />
                    </section>
                </div>
            </form>

            <DialogFooter>
                <Button variant="ghost" type="button" @click="open = false"
                    >Cancel</Button
                >
                <SubmitButton
                    form="saved-reply-form"
                    :processing="form.processing"
                    >{{
                        isEditing ? 'Save changes' : 'Create reply'
                    }}</SubmitButton
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
