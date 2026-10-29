<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import CategoryForm from '@/components/admin/CategoryForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { edit, index } from '@/routes/admin/categories';
import type { BreadcrumbItem } from '@/types';
import type { AdminCategory } from '@/types/admin';

const props = defineProps<{ category: AdminCategory }>();

watchEffect(() => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Categories', href: index() },
        { title: props.category.name, href: edit(props.category.id) },
    ];
    setLayoutProps({ breadcrumbs });
});
</script>

<template>
    <Head :title="`Edit ${category.name}`" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="`Edit ${category.name}`" />
        <Card
            ><CardContent><CategoryForm :category="category" /></CardContent
        ></Card>
    </div>
</template>
