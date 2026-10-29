<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { index, store, update } from '@/routes/admin/categories';
import type { AdminCategory } from '@/types/admin';

const props = defineProps<{ category?: AdminCategory }>();

const form = useForm({
    name: props.category?.name ?? '',
    slug: props.category?.slug ?? '',
    is_active: props.category?.is_active ?? true,
});

const slugTouched = ref(props.category !== undefined);

const slugify = (value: string): string =>
    value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

watch(
    () => form.name,
    (name) => {
        if (!slugTouched.value) {
            form.slug = slugify(name);
        }
    },
);

function submit(): void {
    if (props.category) {
        form.patch(update.url(props.category.id));
    } else {
        form.post(store.url());
    }
}
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required maxlength="100" />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="slug">Slug</Label>
            <Input
                id="slug"
                v-model="form.slug"
                required
                maxlength="100"
                class="font-mono"
                @input="slugTouched = true"
            />
            <p class="text-xs text-muted-foreground">
                Lowercase letters, numbers and dashes. CSV imports refer to
                categories by slug.
            </p>
            <InputError :message="form.errors.slug" />
        </div>
        <div class="flex items-start gap-3">
            <Switch id="is_active" v-model="form.is_active" />
            <div class="grid gap-1">
                <Label for="is_active">Active</Label>
                <p class="text-xs text-muted-foreground">
                    Only active categories are offered when creating tickets.
                </p>
            </div>
        </div>
        <InputError :message="form.errors.is_active" />
        <div class="flex flex-wrap gap-2">
            <SubmitButton :processing="form.processing">{{
                category ? 'Save changes' : 'Create category'
            }}</SubmitButton>
            <Button variant="ghost" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
        </div>
    </form>
</template>
