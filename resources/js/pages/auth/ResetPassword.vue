<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClientValidation } from '@/composables/useClientValidation';
import { maxLength, minLength, required, sameAs } from '@/lib/validation';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Reset password',
        description: 'Please enter your new password below',
    },
});

const props = defineProps<{
    token: string;
    email: string;
}>();

const page = usePage();

const { onBefore, onInput, onFocusOut } = useClientValidation(() => ({
    password: [
        required('Password'),
        minLength('Password', page.props.passwordRules.min),
        maxLength('Password', page.props.passwordRules.max),
    ],
    password_confirmation: [
        required('Password confirmation'),
        sameAs('password', 'The passwords do not match.'),
    ],
}));
</script>

<template>
    <Head title="Reset password" />

    <Form
        v-bind="update.form()"
        ref="form"
        novalidate
        :on-before="onBefore"
        @input="onInput"
        @focusout="onFocusOut"
        :transform="
            (data) => ({ ...data, token: props.token, email: props.email })
        "
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    :model-value="props.email"
                    readonly
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    v-focus
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Reset password
            </Button>
        </div>
    </Form>
</template>
