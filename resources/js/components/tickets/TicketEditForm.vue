<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { CategorySummary, TicketCan, TicketDetail } from '@/types/tickets';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    ticket: TicketDetail;
    can: TicketCan;
    categories: Pick<CategorySummary, 'id' | 'name'>[];
}>();

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
    ).patch(`/tickets/${props.ticket.number}`, { preserveScroll: true });
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Edit ticket</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="space-y-4" @submit.prevent="submit">
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
                        rows="5"
                        required
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <div v-if="can.changePriority" class="grid gap-2">
                    <Label for="edit-category">Category</Label>
                    <Select
                        :model-value="form.category_id"
                        @update:model-value="
                            (v) => (form.category_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger id="edit-category"
                            ><SelectValue placeholder="Choose a category"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.category_id" />
                </div>
                <Button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    >Save changes</Button
                >
            </form>
        </CardContent>
    </Card>
</template>
