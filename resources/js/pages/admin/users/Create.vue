<script setup lang="ts">
import UserForm from '@/components/admin/UserForm.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CategoryOption, RoleOption } from '@/types/users';
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'New user', href: '/admin/users/create' },
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

    <div class="p-4">
        <Card class="max-w-xl">
            <CardHeader>
                <CardTitle>New user</CardTitle>
            </CardHeader>
            <CardContent>
                <UserForm :roles="roles" :categories="categories" />
            </CardContent>
        </Card>
    </div>
</template>
