<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/tickets';
import type { CategorySummary, TicketCan, TicketDetail } from '@/types/tickets';

const props = defineProps<{
    ticket: TicketDetail;
    can: TicketCan;
    categories: Pick<CategorySummary, 'id' | 'name'>[];
}>();

const emit = defineEmits<{ done: [] }>();

const form = useForm({
    subject: props.ticket.subject,
    description: props.ticket.description,
    category_id: props.ticket.category ? String(props.ticket.category.id) : '',
});

const submit = (): void => {
    form.transform((data) =>
        props.can.changePriority
            ? data
            : { subject: data.subject, description: data.description },
    ).patch(update.url(props.ticket.number), {
        preserveScroll: true,
        onSuccess: () => emit('done'),
    });
};
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="edit-subject">Subject</Label>
            <Input
                id="edit-subject"
                v-model="form.subject"
                required
                maxlength="255"
            />
            <InputError :message="form.errors.subject" />
        </div>
        <div class="grid gap-2">
            <Label for="edit-description">Description</Label>
            <Textarea
                id="edit-description"
                v-model="form.description"
                rows="6"
                required
            />
            <InputError :message="form.errors.description" />
        </div>
        <div v-if="can.changePriority" class="grid gap-2">
            <Label for="edit-category">Category</Label>
            <NativeSelect id="edit-category" v-model="form.category_id">
                <NativeSelectOption
                    v-for="category in categories"
                    :key="category.id"
                    :value="String(category.id)"
                    >{{ category.name }}</NativeSelectOption
                >
            </NativeSelect>
            <InputError :message="form.errors.category_id" />
        </div>
        <div class="flex flex-wrap gap-2">
            <SubmitButton
                :processing="form.processing"
                :disabled="!form.isDirty"
                >Save changes</SubmitButton
            >
            <Button type="button" variant="ghost" @click="emit('done')"
                >Cancel</Button
            >
        </div>
    </form>
</template>
