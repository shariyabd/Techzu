<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AdminUser,
    CategoryOption,
    RoleOption,
    RoleValue,
} from '@/types/users';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    user?: AdminUser;
    roles: RoleOption[];
    categories: CategoryOption[];
    isSelf?: boolean;
}>();

const form = useForm<{
    name: string;
    email: string;
    password: string;
    role: RoleValue;
    category_ids: number[];
    is_available: boolean;
}>({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    role: props.user?.role ?? 'customer',
    category_ids: props.user?.categories?.map((category) => category.id) ?? [],
    is_available: props.user?.is_available ?? true,
});

const onRoleChange = (value: unknown): void => {
    form.role = String(value) as RoleValue;
};

const toggleCategory = (id: number, checked: boolean): void => {
    form.category_ids = checked
        ? [...form.category_ids, id]
        : form.category_ids.filter((categoryId) => categoryId !== id);
};

const submit = (): void => {
    if (props.user) {
        form.patch(`/admin/users/${props.user.id}`, {
            onSuccess: () => form.reset('password'),
        });

        return;
    }

    form.post('/admin/users');
};
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required maxlength="255" />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input
                id="email"
                v-model="form.email"
                type="email"
                required
                maxlength="255"
            />
            <InputError :message="form.errors.email" />
        </div>

        <div class="grid gap-2">
            <Label for="password">{{
                user ? 'New password (leave blank to keep)' : 'Password'
            }}</Label>
            <Input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :required="!user"
            />
            <InputError :message="form.errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="role">Role</Label>
            <Select
                :model-value="form.role"
                :disabled="isSelf"
                @update:model-value="onRoleChange"
            >
                <SelectTrigger id="role"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p v-if="isSelf" class="text-xs text-muted-foreground">
                You cannot change your own role.
            </p>
            <InputError :message="form.errors.role" />
        </div>

        <fieldset
            v-if="form.role === 'agent'"
            class="flex flex-col gap-3 rounded-lg border p-4"
        >
            <legend class="px-1 text-sm font-medium">Agent profile</legend>

            <Label for="is_available" class="flex items-center gap-2">
                <Checkbox
                    id="is_available"
                    :model-value="form.is_available"
                    @update:model-value="
                        (checked) => (form.is_available = checked === true)
                    "
                />
                <span>Available for automatic assignment</span>
            </Label>
            <InputError :message="form.errors.is_available" />

            <div class="grid gap-2">
                <span class="text-sm font-medium">Categories</span>
                <p
                    v-if="categories.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    No categories exist yet.
                </p>
                <Label
                    v-for="category in categories"
                    :key="category.id"
                    :for="`category-${category.id}`"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox
                        :id="`category-${category.id}`"
                        :model-value="form.category_ids.includes(category.id)"
                        @update:model-value="
                            (checked) =>
                                toggleCategory(category.id, checked === true)
                        "
                    />
                    <span>{{ category.name }}</span>
                    <span
                        v-if="!category.is_active"
                        class="text-xs text-muted-foreground"
                        >(inactive)</span
                    >
                </Label>
                <InputError :message="form.errors.category_ids" />
            </div>
        </fieldset>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{
                user ? 'Save changes' : 'Create user'
            }}</Button>
            <Button variant="ghost" as-child>
                <Link href="/admin/users">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
