<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileSpreadsheet, UploadCloud } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { formatBytes } from '@/lib/format';
import { create, index, store } from '@/routes/admin/imports';
import type { ImportColumns } from '@/types/imports';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Imports', href: index() },
            { title: 'New import', href: create() },
        ],
    },
});

const props = defineProps<{ maxUploadKb: number; columns: ImportColumns }>();

const form = useForm<{ file: File | null }>({ file: null });
const dragging = ref(false);

const maxSize = computed(() => formatBytes(props.maxUploadKb * 1024));
const headerExample = computed(() =>
    [...props.columns.required, ...props.columns.optional].join(','),
);

function pick(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function drop(event: DragEvent): void {
    dragging.value = false;
    form.file = event.dataTransfer?.files[0] ?? null;
}

function submit(): void {
    form.post(store.url(), { forceFormData: true });
}
</script>

<template>
    <Head title="New ticket import" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Import tickets from CSV"
            :description="`One ticket per row. Files up to ${maxSize}.`"
        />

        <Card>
            <CardContent>
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <label
                        for="file"
                        :class="[
                            'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-10 text-center transition-colors focus-within:ring-2 focus-within:ring-ring',
                            dragging
                                ? 'border-primary bg-primary/5'
                                : 'border-input hover:bg-muted/50',
                        ]"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="drop"
                    >
                        <UploadCloud
                            class="size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span
                            v-if="form.file"
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <FileSpreadsheet
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ form.file.name }} ·
                            {{ formatBytes(form.file.size) }}
                        </span>
                        <span v-else class="text-sm"
                            ><span class="font-medium">Choose a CSV file</span>
                            or drag it here</span
                        >
                        <input
                            id="file"
                            type="file"
                            accept=".csv,text/csv,text/plain"
                            class="sr-only"
                            @change="pick"
                        />
                    </label>
                    <InputError :message="form.errors.file" />
                    <Progress
                        v-if="form.progress"
                        :model-value="form.progress.percentage ?? 0"
                        aria-label="Upload progress"
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <SubmitButton
                            :processing="form.processing"
                            :disabled="form.file === null"
                            >Start import</SubmitButton
                        >
                        <Button variant="ghost" as-child
                            ><Link :href="index()">Cancel</Link></Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle class="text-base"
                    >File format</CardTitle
                ></CardHeader
            >
            <CardContent class="flex flex-col gap-3 text-sm">
                <p>
                    The first row is a header. Columns may appear in any order.
                </p>
                <p class="flex flex-wrap items-center gap-1">
                    <span class="font-medium">Required:</span>
                    <code
                        v-for="column in columns.required"
                        :key="column"
                        class="rounded bg-muted px-1.5 py-0.5 text-xs"
                        >{{ column }}</code
                    >
                </p>
                <p class="flex flex-wrap items-center gap-1">
                    <span class="font-medium">Optional:</span>
                    <code
                        v-for="column in columns.optional"
                        :key="column"
                        class="rounded bg-muted px-1.5 py-0.5 text-xs"
                        >{{ column }}</code
                    >
                </p>
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>
                        <code>priority</code>: low, medium, high or critical.
                    </li>
                    <li>
                        <code>category</code>: the slug of an active category.
                    </li>
                    <li>
                        <code>customer_email</code>: an existing customer
                        account.
                    </li>
                    <li>
                        <code>status</code>: open (default), in_progress,
                        waiting_for_customer, resolved or closed.
                    </li>
                    <li>
                        <code>created_at</code>: a past date such as
                        <code>2026-10-29 17:00</code>, read in business time.
                        The SLA is measured from it.
                    </li>
                    <li>
                        <code>external_reference</code>: a row whose reference
                        was already imported is reported as a duplicate.
                    </li>
                </ul>
                <pre class="overflow-x-auto rounded bg-muted p-2 text-xs">{{
                    headerExample
                }}</pre>
            </CardContent>
        </Card>
    </div>
</template>
