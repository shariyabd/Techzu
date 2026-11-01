<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import AvatarField from '@/components/profile/AvatarField.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, update } from '@/routes/profile';
import {
    destroy as destroyAvatar,
    update as updateAvatar,
} from '@/routes/profile/avatar';
import { update as updatePassword } from '@/routes/profile/password';
import type { AvatarRules, ProfileData } from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Profile settings', href: edit() }],
    },
});

const props = defineProps<{
    profile: ProfileData;
    avatarRules: AvatarRules;
}>();

const page = usePage();

const details = useForm({ name: props.profile.name });

const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const avatarBusy = ref(false);
const avatarError = ref<string | undefined>();

function saveDetails(): void {
    details.patch(update.url(), { preserveScroll: true });
}

function savePassword(): void {
    password.put(updatePassword.url(), {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
}

const avatarCallbacks = {
    preserveScroll: true,
    onStart: () => {
        avatarBusy.value = true;
        avatarError.value = undefined;
    },
    onFinish: () => {
        avatarBusy.value = false;
    },
    onError: (errors: Record<string, string>) => {
        avatarError.value = errors.avatar;
    },
};

function uploadAvatar(file: File): void {
    router.post(
        updateAvatar.url(),
        { avatar: file },
        { ...avatarCallbacks, forceFormData: true },
    );
}

function removeAvatar(): void {
    router.delete(destroyAvatar.url(), avatarCallbacks);
}
</script>

<template>
    <Head title="Profile settings" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Profile settings"
            description="Your name, profile image and password."
        />

        <Card>
            <CardHeader>
                <CardTitle>Profile image</CardTitle>
                <CardDescription
                    >Shown next to your name across the help
                    desk.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <AvatarField
                    :name="profile.name"
                    :src="profile.avatar_url"
                    :rules="avatarRules"
                    :error="avatarError"
                    :processing="avatarBusy"
                    @select="uploadAvatar"
                    @remove="removeAvatar"
                />
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Profile</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="flex flex-col gap-5" @submit.prevent="saveDetails">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="details.name"
                            required
                            maxlength="255"
                            autocomplete="name"
                        />
                        <InputError :message="details.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            :model-value="profile.email"
                            readonly
                            aria-describedby="email-hint"
                        />
                        <p
                            id="email-hint"
                            class="text-xs text-muted-foreground"
                        >
                            Ask an administrator to change your email address.
                        </p>
                    </div>
                    <div>
                        <SubmitButton
                            :processing="details.processing"
                            :disabled="!details.isDirty"
                            >Save profile</SubmitButton
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Password</CardTitle>
                <CardDescription
                    >Changing it signs you out everywhere else.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form
                    class="flex flex-col gap-5"
                    @submit.prevent="savePassword"
                >
                    <div class="grid gap-2">
                        <Label for="current_password">Current password</Label>
                        <PasswordInput
                            id="current_password"
                            v-model="password.current_password"
                            required
                            autocomplete="current-password"
                        />
                        <InputError
                            :message="password.errors.current_password"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password">New password</Label>
                        <PasswordInput
                            id="password"
                            v-model="password.password"
                            required
                            autocomplete="new-password"
                            :minlength="page.props.passwordRules.min"
                            :maxlength="page.props.passwordRules.max"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ page.props.passwordRules.min }} to
                            {{ page.props.passwordRules.max }} characters.
                        </p>
                        <InputError :message="password.errors.password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password_confirmation"
                            >Confirm new password</Label
                        >
                        <PasswordInput
                            id="password_confirmation"
                            v-model="password.password_confirmation"
                            required
                            autocomplete="new-password"
                        />
                        <InputError
                            :message="password.errors.password_confirmation"
                        />
                    </div>
                    <div>
                        <SubmitButton :processing="password.processing"
                            >Change password</SubmitButton
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
