<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CategoryForm from '@/components/admin/CategoryForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { edit, index } from '@/routes/admin/categories';
import type { BreadcrumbItem } from '@/types';
import type { AdminCategory } from '@/types/admin';

defineProps<{ category: AdminCategory }>();

defineOptions({
    layout: (pageProps: { category: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Categories', href: index() },
            {
                title: pageProps.category.name,
                href: edit(pageProps.category.id),
            },
        ] satisfies BreadcrumbItem[],
    }),
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
