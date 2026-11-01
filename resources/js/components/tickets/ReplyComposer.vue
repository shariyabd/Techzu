<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Lock, SendHorizontal } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import SavedReplyPicker from '@/components/saved-replies/SavedReplyPicker.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { TypingMode } from '@/composables/useTyping';
import { errorsFor } from '@/lib/forms';
import { needsPublicConfirmation, resolveReplyMode } from '@/lib/replyMode';
import type { ReplyMode } from '@/lib/replyMode';
import { store } from '@/routes/tickets/replies';
import type { SavedReplyContext } from '@/types/saved-replies';
import type { AttachmentRules, TicketCan, TicketDetail } from '@/types/tickets';

export type ReplyDraft = { body: string; isInternal: boolean };

const props = defineProps<{
    ticket: TicketDetail;
    can: TicketCan;
    attachmentRules: AttachmentRules;
}>();

const emit = defineEmits<{
    typing: [mode: TypingMode];
    typingStopped: [mode: TypingMode];
    sending: [draft: ReplyDraft];
    settled: [];
}>();

const form = useForm<{
    body: string;
    is_internal: boolean;
    attachments: File[];
}>({
    body: '',
    is_internal: false,
    attachments: [],
});

const attachmentErrors = computed(() => errorsFor(form.errors, 'attachments'));

const page = usePage();
const textarea = ref<InstanceType<typeof Textarea> | null>(null);
const caret = ref<{ start: number; end: number } | null>(null);

const savedReplyContext = computed<SavedReplyContext>(() => ({
    customerName: props.ticket.customer?.name ?? '',
    ticketNumber: props.ticket.number,
    ticketSubject: props.ticket.subject,
    agentName: page.props.auth.user.name,
}));

const textareaElement = (): HTMLTextAreaElement | null =>
    (textarea.value?.$el ?? null) as HTMLTextAreaElement | null;

function rememberCaret(): void {
    const element = textareaElement();

    if (element !== null) {
        caret.value = {
            start: element.selectionStart,
            end: element.selectionEnd,
        };
    }
}

function insertSavedReply(text: string): void {
    const body = form.body;
    let position: number;

    if (caret.value === null) {
        const trimmed = body.replace(/\s+$/, '');
        const prefix = trimmed === '' ? '' : `${trimmed}\n\n`;
        form.body = `${prefix}${text}`;
        position = form.body.length;
    } else {
        const start = Math.min(caret.value.start, body.length);
        const end = Math.min(Math.max(caret.value.end, start), body.length);
        form.body = `${body.slice(0, start)}${text}${body.slice(end)}`;
        position = start + text.length;
    }

    caret.value = { start: position, end: position };
    onInput();

    void nextTick(() => {
        const element = textareaElement();
        element?.focus();
        element?.setSelectionRange(position, position);
    });
}

const replyMode = computed<ReplyMode>(() =>
    resolveReplyMode(props.can.addInternalNote, form.is_internal),
);
const mode = computed<TypingMode>(() =>
    replyMode.value === 'public' ? 'public' : 'internal',
);
const confirmingPublic = ref(false);

function selectInternal(next: boolean): void {
    if (needsPublicConfirmation(form.is_internal, next, form.body)) {
        confirmingPublic.value = true;

        return;
    }

    form.is_internal = next;
}

function confirmPublic(): void {
    confirmingPublic.value = false;
    form.is_internal = false;
}

watch(mode, (_next, previous) => emit('typingStopped', previous));

function onInput(): void {
    if (form.body.trim() === '') {
        emit('typingStopped', mode.value);

        return;
    }

    emit('typing', mode.value);
}

function submit(): void {
    if (
        form.processing ||
        form.body.trim() === '' ||
        replyMode.value === 'unavailable'
    ) {
        return;
    }

    const draft = {
        body: form.body,
        is_internal: mode.value === 'internal',
        attachments: [...form.attachments],
    };

    emit('typingStopped', mode.value);
    emit('sending', { body: draft.body, isInternal: draft.is_internal });

    form.transform(() => draft).post(store.url(props.ticket.number), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.clearErrors();
        },
        onError: () => {
            if (form.body === '') {
                form.body = draft.body;
            }

            if (form.attachments.length === 0) {
                form.attachments = draft.attachments;
            }
        },
        onFinish: () => emit('settled'),
    });

    form.body = '';
    form.attachments = [];
    caret.value = null;
}

function onBlur(): void {
    rememberCaret();
    emit('typingStopped', mode.value);
}

function submitFromKeyboard(event: KeyboardEvent): void {
    if (event.metaKey || event.ctrlKey) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <form
        :class="[
            'flex shrink-0 flex-col gap-3 border-t bg-card p-3 sm:p-4',
            mode === 'internal' ? 'border-t-2 border-t-tint-amber-border' : '',
        ]"
        :data-reply-mode="replyMode"
        @submit.prevent="submit"
    >
        <div class="grid gap-2">
            <div
                v-if="mode === 'internal'"
                role="status"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-tint-amber-border bg-tint-amber px-3 py-1.5 text-xs font-medium text-tint-amber-foreground"
            >
                <span class="flex items-center gap-1.5">
                    <Lock class="size-3.5" aria-hidden="true" />
                    {{
                        replyMode === 'unavailable'
                            ? 'You can no longer add internal notes on this ticket'
                            : 'Internal note — only staff can see this'
                    }}
                </span>
                <Button
                    v-if="replyMode === 'unavailable'"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="ml-auto h-7"
                    @click="selectInternal(false)"
                    >Write a public reply instead</Button
                >
            </div>
            <Label for="reply-body" class="sr-only">{{
                mode === 'internal' ? 'Internal note' : 'Reply'
            }}</Label>
            <Textarea
                id="reply-body"
                ref="textarea"
                v-model="form.body"
                rows="2"
                maxlength="20000"
                :placeholder="
                    mode === 'internal'
                        ? 'Visible to staff only'
                        : 'Write a reply'
                "
                :class="[
                    'max-h-48 min-h-16 resize-none',
                    mode === 'internal'
                        ? 'border-tint-amber-border bg-tint-amber text-tint-amber-foreground'
                        : '',
                ]"
                aria-keyshortcuts="Control+Enter Meta+Enter"
                @input="onInput"
                @keydown.enter="submitFromKeyboard"
                @blur="onBlur"
            />
            <InputError :message="form.errors.body" />
        </div>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div
                class="flex min-w-0 flex-1 basis-full flex-wrap items-start gap-2 sm:basis-0"
                data-slot="composer-tools"
            >
                <AttachmentPicker
                    id="reply-attachments"
                    v-model="form.attachments"
                    :rules="attachmentRules"
                    :errors="attachmentErrors"
                    class="min-w-0 flex-1"
                />
                <SavedReplyPicker
                    v-if="page.props.auth.can.useSavedReplies"
                    :context="savedReplyContext"
                    :category-id="ticket.category?.id ?? null"
                    @insert="insertSavedReply"
                />
            </div>
            <div
                class="flex w-full shrink-0 items-center justify-end gap-3 sm:w-auto"
            >
                <div
                    v-if="can.addInternalNote"
                    class="mr-auto flex items-center gap-2 sm:mr-0"
                >
                    <Switch
                        id="reply-internal"
                        :model-value="form.is_internal"
                        @update:model-value="selectInternal"
                    />
                    <Label
                        for="reply-internal"
                        class="flex items-center gap-1 text-sm font-normal"
                    >
                        <Lock class="size-3.5" aria-hidden="true" />
                        Internal note
                    </Label>
                </div>
                <SubmitButton
                    :processing="form.processing"
                    :disabled="
                        form.body.trim() === '' || replyMode === 'unavailable'
                    "
                >
                    <SendHorizontal
                        v-if="!form.processing"
                        aria-hidden="true"
                    />
                    {{
                        mode === 'internal' ? 'Add internal note' : 'Send reply'
                    }}
                </SubmitButton>
            </div>
        </div>
        <AlertDialog v-model:open="confirmingPublic">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle
                        >Make this a public reply?</AlertDialogTitle
                    >
                    <AlertDialogDescription
                        >The text you wrote as an internal note will be sent to
                        the customer when you press Send
                        reply.</AlertDialogDescription
                    >
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Keep as internal note</AlertDialogCancel>
                    <AlertDialogAction @click="confirmPublic"
                        >Make public</AlertDialogAction
                    >
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </form>
</template>
