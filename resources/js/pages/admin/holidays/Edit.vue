<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import HolidayForm from '@/components/admin/HolidayForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { edit, index } from '@/routes/admin/holidays';
import type { BreadcrumbItem } from '@/types';
import type { HolidayItem } from '@/types/admin';

const props = defineProps<{ holiday: HolidayItem }>();

watchEffect(() => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Holidays', href: index() },
        { title: props.holiday.name, href: edit(props.holiday.id) },
    ];
    setLayoutProps({ breadcrumbs });
});
</script>

<template>
    <Head :title="`Edit ${holiday.name}`" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="`Edit ${holiday.name}`" />
        <Card
            ><CardContent><HolidayForm :holiday="holiday" /></CardContent
        ></Card>
    </div>
</template>
