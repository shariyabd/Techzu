<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClientValidation } from '@/composables/useClientValidation';
import {
    email as emailRule,
    MAX_EMAIL_LENGTH,
    maxLength,
    required,
} from '@/lib/validation';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Forgot password',
        description: 'Enter your email to receive a password reset link',
    },
});

const page = usePage();
const status = computed(() => page.props.flash.success);
const linkError = computed(() => page.props.flash.error);

const { onBefore, onInput, onFocusOut } = useClientValidation(() => ({
    email: [
        required('Email address'),
        emailRule(),
        maxLength('Email address', MAX_EMAIL_LENGTH),
    ],
}));
</script>

<template>
    <Head title="Forgot password" />

    <div
        v-if="status"
        role="status"
        class="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-400"
    >
        {{ status }}
    </div>

    <div
        v-else-if="linkError"
        role="alert"
        class="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-center text-sm font-medium text-destructive"
    >
        {{ linkError }}
    </div>

    <div class="space-y-6">
        <Form
            v-bind="email.form()"
            ref="form"
            novalidate
            :on-before="onBefore"
            v-slot="{ errors, processing }"
            @input="onInput"
            @focusout="onFocusOut"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    v-focus
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    Email password reset link
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Or, return to</span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>
</template>
