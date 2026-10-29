<script setup lang="ts">
import UserForm from '@/components/admin/UserForm.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types';
import type { AdminUser, CategoryOption, RoleOption } from '@/types/users';
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';

const props = defineProps<{
    user: AdminUser;
    roles: RoleOption[];
    categories: CategoryOption[];
    isSelf: boolean;
}>();

watchEffect(() => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: props.user.name, href: `/admin/users/${props.user.id}/edit` },
    ];
    setLayoutProps({ breadcrumbs });
});
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <div class="p-4">
        <Card class="max-w-xl">
            <CardHeader>
                <CardTitle>Edit user</CardTitle>
            </CardHeader>
            <CardContent>
                <UserForm
                    :user="user"
                    :roles="roles"
                    :categories="categories"
                    :is-self="isSelf"
                />
            </CardContent>
        </Card>
    </div>
</template>
