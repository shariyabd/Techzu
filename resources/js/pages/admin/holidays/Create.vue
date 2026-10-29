<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import HolidayForm from '@/components/admin/HolidayForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { create, index } from '@/routes/admin/holidays';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Holidays', href: index() },
            { title: 'New holiday', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Add holiday" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Add holiday" />
        <Card
            ><CardContent><HolidayForm /></CardContent
        ></Card>
    </div>
</template>
