<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserForm from '@/components/admin/UserForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Card, CardContent } from '@/components/ui/card';
import { edit, index } from '@/routes/admin/users';
import type { BreadcrumbItem } from '@/types';
import type { AdminUser, CategoryOption, RoleOption } from '@/types/users';

defineProps<{
    user: AdminUser;
    roles: RoleOption[];
    categories: CategoryOption[];
    isSelf: boolean;
}>();

defineOptions({
    layout: (pageProps: { user: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: pageProps.user.name, href: edit(pageProps.user.id) },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="`Edit ${user.name}`" :description="user.email" />
        <Card>
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
