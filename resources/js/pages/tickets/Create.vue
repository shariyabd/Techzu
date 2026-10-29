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
import type { TicketFormOptions } from '@/types/tickets';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Tickets', href: '/tickets' },
            { title: 'New ticket', href: '/tickets/create' },
        ],
    },
});

const props = defineProps<{
    options: TicketFormOptions;
    canCreateOnBehalf: boolean;
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

const attachmentErrors = computed<string[]>(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('attachments'))
        .map(([, message]) => message as string),
);

const onFiles = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    form.attachments = input.files ? Array.from(input.files) : [];
};

const submit = (): void => {
    form.transform((data) => {
        if (props.canCreateOnBehalf) {
            return data;
        }
        const rest: Partial<typeof data> = { ...data };
        delete rest.customer_email;
        return rest;
    }).post('/tickets', { forceFormData: true });
};
</script>

<template>
    <Head title="New ticket" />

    <div class="p-4">
        <Card class="max-w-2xl">
            <CardHeader>
                <CardTitle>New ticket</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div v-if="canCreateOnBehalf" class="grid gap-2">
                        <Label for="customer_email">Customer email</Label>
                        <Input
                            id="customer_email"
                            v-model="form.customer_email"
                            type="email"
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
                            <Select
                                :model-value="form.priority"
                                @update:model-value="
                                    (v) => (form.priority = String(v ?? ''))
                                "
                            >
                                <SelectTrigger id="priority"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in options.priorities"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.priority" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="category">Category</Label>
                            <Select
                                :model-value="form.category_id"
                                @update:model-value="
                                    (v) => (form.category_id = String(v ?? ''))
                                "
                            >
                                <SelectTrigger id="category"
                                    ><SelectValue
                                        placeholder="Choose a category"
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="category in options.categories"
                                        :key="category.id"
                                        :value="String(category.id)"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.category_id" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="attachments">Attachments</Label>
                        <Input
                            id="attachments"
                            type="file"
                            multiple
                            @change="onFiles"
                        />
                        <InputError
                            v-for="(message, index) in attachmentErrors"
                            :key="index"
                            :message="message"
                        />
                    </div>
                    <Button type="submit" :disabled="form.processing"
                        >Create ticket</Button
                    >
                </form>
            </CardContent>
        </Card>
    </div>
</template>
