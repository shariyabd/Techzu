<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserForm from '@/components/admin/UserForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { create, index } from '@/routes/admin/users';
import type { CategoryOption, RoleOption } from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: 'New user', href: create() },
        ],
    },
});

defineProps<{
    roles: RoleOption[];
    categories: CategoryOption[];
}>();
</script>

<template>
    <Head title="New user" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="New user" />
        <Card>
            <CardContent>
                <UserForm :roles="roles" :categories="categories" />
            </CardContent>
        </Card>
    </div>
</template>
