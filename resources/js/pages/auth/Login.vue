<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/Web/Auth/LoginController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClientValidation } from '@/composables/useClientValidation';
import { email, MAX_EMAIL_LENGTH, maxLength, required } from '@/lib/validation';
import { register } from '@/routes';
import { request } from '@/routes/password';
import type { DemoAccount } from '@/types/auth';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});

defineProps<{
    demoAccounts: DemoAccount[];
}>();

const page = usePage();

const emailValue = ref('');
const passwordValue = ref('');
const copiedValue = ref<string | null>(null);

function fillWith(account: DemoAccount, clearErrors: () => void): void {
    emailValue.value = account.email;
    passwordValue.value = account.password;
    clearErrors();
}

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copiedValue.value = value;
        setTimeout(() => {
            if (copiedValue.value === value) {
                copiedValue.value = null;
            }
        }, 2000);
    } catch {
        copiedValue.value = null;
    }
}

const { onBefore, onInput, onFocusOut } = useClientValidation(() => ({
    email: [
        required('Email address'),
        email(),
        maxLength('Email address', MAX_EMAIL_LENGTH),
    ],
    password: [
        required('Password'),
        maxLength('Password', page.props.passwordRules.max),
    ],
}));
</script>

<template>
    <Head title="Log in" />

    <Form
        v-bind="store.form()"
        ref="form"
        novalidate
        :on-before="onBefore"
        @input="onInput"
        @focusout="onFocusOut"
        :reset-on-success="['password']"
        v-slot="{ errors, processing, clearErrors }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    v-model="emailValue"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink :href="request()" class="text-sm" :tabindex="5"
                        >Forgot password?</TextLink
                    >
                </div>
                <PasswordInput
                    id="password"
                    v-model="passwordValue"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox
                        id="remember"
                        name="remember"
                        value="1"
                        :tabindex="3"
                    />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Don't have an account?
            <TextLink :href="register()" :tabindex="6">Sign up</TextLink>
        </div>
        <section
            v-if="demoAccounts.length"
            aria-labelledby="demo-accounts-heading"
            class="rounded-lg border border-dashed bg-muted/40 p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <h2
                    id="demo-accounts-heading"
                    class="text-xs font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase"
                >
                    Demo accounts
                </h2>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 font-mono text-xs text-muted-foreground hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Copy the demo password ${demoAccounts[0].password}`"
                    @click="copy(demoAccounts[0].password)"
                >
                    <span class="font-sans">Password:</span>
                    {{ demoAccounts[0].password }}
                    <Check
                        v-if="copiedValue === demoAccounts[0].password"
                        class="size-3.5 text-emerald-600"
                    />
                    <Copy v-else class="size-3.5" />
                </button>
            </div>

            <ul class="mt-2 divide-y">
                <li
                    v-for="account in demoAccounts"
                    :key="account.role"
                    class="flex items-center gap-2 py-2 first:pt-1 last:pb-0"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ account.label }}</p>
                        <button
                            type="button"
                            class="inline-flex max-w-full items-center gap-1.5 rounded text-xs text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="`Copy ${account.email}`"
                            @click="copy(account.email)"
                        >
                            <span class="truncate">{{ account.email }}</span>
                            <Check
                                v-if="copiedValue === account.email"
                                class="size-3.5 shrink-0 text-emerald-600"
                            />
                            <Copy v-else class="size-3.5 shrink-0" />
                        </button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :data-test="`demo-login-${account.role}`"
                        @click="fillWith(account, clearErrors)"
                    >
                        Use
                    </Button>
                </li>
            </ul>
        </section>
    </Form>
</template>
