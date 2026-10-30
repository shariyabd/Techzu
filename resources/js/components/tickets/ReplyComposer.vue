<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock, SendHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { errorsFor } from '@/lib/forms';
import { store } from '@/routes/tickets/replies';
import type { AttachmentRules, TicketCan, TicketDetail } from '@/types/tickets';

const props = defineProps<{
    ticket: TicketDetail;
    can: TicketCan;
    attachmentRules: AttachmentRules;
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

function submit(): void {
    form.transform((data) => ({
        ...data,
        is_internal: props.can.addInternalNote ? data.is_internal : false,
    })).post(store.url(props.ticket.number), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form
        class="flex flex-col gap-3 border-t bg-card p-3 sm:p-4"
        @submit.prevent="submit"
    >
        <div class="grid gap-2">
            <Label for="reply-body" class="sr-only">{{
                form.is_internal ? 'Internal note' : 'Reply'
            }}</Label>
            <Textarea
                id="reply-body"
                v-model="form.body"
                rows="3"
                required
                maxlength="20000"
                :placeholder="
                    form.is_internal ? 'Visible to staff only' : 'Write a reply'
                "
                :class="
                    form.is_internal
                        ? 'border-tint-amber-border bg-tint-amber text-tint-amber-foreground'
                        : ''
                "
            />
            <InputError :message="form.errors.body" />
        </div>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <AttachmentPicker
                id="reply-attachments"
                v-model="form.attachments"
                :rules="attachmentRules"
                :errors="attachmentErrors"
                class="min-w-0 flex-1 basis-full sm:basis-0"
            />
            <div
                class="flex w-full shrink-0 items-center justify-end gap-3 sm:w-auto"
            >
                <div
                    v-if="can.addInternalNote"
                    class="mr-auto flex items-center gap-2 sm:mr-0"
                >
                    <Switch id="reply-internal" v-model="form.is_internal" />
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
                    :disabled="form.body.trim() === ''"
                >
                    <SendHorizontal
                        v-if="!form.processing"
                        aria-hidden="true"
                    />
                    {{ form.is_internal ? 'Add internal note' : 'Send reply' }}
                </SubmitButton>
            </div>
        </div>
    </form>
</template>
