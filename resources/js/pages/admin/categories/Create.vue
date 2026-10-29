<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CategoryForm from '@/components/admin/CategoryForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { create, index } from '@/routes/admin/categories';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Categories', href: index() },
            { title: 'New category', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New category" />
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="New category" />
        <Card
            ><CardContent><CategoryForm /></CardContent
        ></Card>
    </div>
</template>
