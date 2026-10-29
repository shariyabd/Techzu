<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
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
    <form class="flex flex-col gap-4 border-t pt-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Label for="reply-body">{{
                    form.is_internal ? 'Internal note' : 'Reply'
                }}</Label>
                <div v-if="can.addInternalNote" class="flex items-center gap-2">
                    <Switch id="reply-internal" v-model="form.is_internal" />
                    <Label
                        for="reply-internal"
                        class="flex items-center gap-1 text-sm font-normal"
                    >
                        <Lock class="size-3.5" aria-hidden="true" />
                        Internal note (staff only)
                    </Label>
                </div>
            </div>
            <Textarea
                id="reply-body"
                v-model="form.body"
                rows="5"
                required
                maxlength="20000"
                :placeholder="
                    form.is_internal ? 'Visible to staff only' : 'Write a reply'
                "
                :class="
                    form.is_internal
                        ? 'border-amber-400 bg-amber-50/50 dark:border-amber-600 dark:bg-amber-950/30'
                        : ''
                "
            />
            <InputError :message="form.errors.body" />
        </div>
        <AttachmentPicker
            id="reply-attachments"
            v-model="form.attachments"
            :rules="attachmentRules"
            :errors="attachmentErrors"
        />
        <div class="flex justify-end">
            <SubmitButton
                :processing="form.processing"
                :disabled="form.body.trim() === ''"
            >
                {{ form.is_internal ? 'Add internal note' : 'Send reply' }}
            </SubmitButton>
        </div>
    </form>
</template>
