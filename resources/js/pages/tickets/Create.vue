<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import AttachmentPicker from '@/components/tickets/AttachmentPicker.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { errorsFor } from '@/lib/forms';
import { create, index, store } from '@/routes/tickets';
import type { AttachmentRules, TicketFormOptions } from '@/types/tickets';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Tickets', href: index() },
            { title: 'New ticket', href: create() },
        ],
    },
});

const props = defineProps<{
    options: TicketFormOptions;
    canCreateOnBehalf: boolean;
    attachmentRules: AttachmentRules;
}>();

const form = useForm<{
    subject: string;
    description: string;
    priority: string;
    category_id: string;
    customer_email: string;
    attachments: File[];
}>({
    subject: '',
    description: '',
    priority: 'medium',
    category_id: '',
    customer_email: '',
    attachments: [],
});

const attachmentErrors = computed(() => errorsFor(form.errors, 'attachments'));

const submit = (): void => {
    form.transform((data) => {
        if (props.canCreateOnBehalf) {
            return data;
        }
        const rest: Partial<typeof data> = { ...data };
        delete rest.customer_email;
        return rest;
    }).post(store.url(), { forceFormData: true });
};
</script>

<template>
    <Head title="New ticket" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="New ticket"
            :description="
                canCreateOnBehalf
                    ? 'Open a ticket for a customer.'
                    : 'Tell us what you need help with. We reply by email and here.'
            "
        />

        <Card>
            <CardContent>
                <form class="flex flex-col gap-5" @submit.prevent="submit">
                    <div v-if="canCreateOnBehalf" class="grid gap-2">
                        <Label for="customer_email">Customer email</Label>
                        <Input
                            id="customer_email"
                            v-model="form.customer_email"
                            type="email"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.customer_email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="subject">Subject</Label>
                        <Input
                            id="subject"
                            v-model="form.subject"
                            required
                            maxlength="255"
                        />
                        <InputError :message="form.errors.subject" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <Textarea
                            id="description"
                            v-model="form.description"
                            rows="6"
                            required
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="priority">Priority</Label>
                            <NativeSelect id="priority" v-model="form.priority">
                                <NativeSelectOption
                                    v-for="option in options.priorities"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ option.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                            <InputError :message="form.errors.priority" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category">Category</Label>
                            <NativeSelect
                                id="category"
                                v-model="form.category_id"
                                required
                            >
                                <NativeSelectOption value="" disabled
                                    >Choose a category</NativeSelectOption
                                >
                                <NativeSelectOption
                                    v-for="category in options.categories"
                                    :key="category.id"
                                    :value="String(category.id)"
                                    >{{ category.name }}</NativeSelectOption
                                >
                            </NativeSelect>
                            <InputError :message="form.errors.category_id" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="attachments">Attachments</Label>
                        <AttachmentPicker
                            id="attachments"
                            v-model="form.attachments"
                            :rules="attachmentRules"
                            :errors="attachmentErrors"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <SubmitButton :processing="form.processing"
                            >Create ticket</SubmitButton
                        >
                        <Button variant="ghost" as-child>
                            <Link :href="index()">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
