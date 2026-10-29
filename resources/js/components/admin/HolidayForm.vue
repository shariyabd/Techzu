<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store, update } from '@/routes/admin/holidays';
import type { HolidayItem } from '@/types/admin';

const props = defineProps<{ holiday?: HolidayItem }>();

const form = useForm({
    date: props.holiday?.date ?? '',
    name: props.holiday?.name ?? '',
});

function submit(): void {
    if (props.holiday) {
        form.patch(update.url(props.holiday.id));
    } else {
        form.post(store.url());
    }
}
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <div class="grid gap-5 sm:grid-cols-[12rem_minmax(0,1fr)]">
            <div class="grid gap-2">
                <Label for="date">Date</Label>
                <Input id="date" v-model="form.date" type="date" required />
                <InputError :message="form.errors.date" />
            </div>
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required maxlength="100" />
                <InputError :message="form.errors.name" />
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            New SLA deadlines skip this day. Deadlines already calculated for
            existing tickets don't change.
        </p>
        <div class="flex flex-wrap gap-2">
            <SubmitButton :processing="form.processing">{{
                holiday ? 'Save changes' : 'Add holiday'
            }}</SubmitButton>
            <Button variant="ghost" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
        </div>
    </form>
</template>
