<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Verify your email',
        description:
            'Confirm your email address to start using your support account',
    },
});

defineProps<{
    email: string;
    mailIsLogged: boolean;
}>();
</script>

<template>
    <Head title="Verify your email" />

    <div class="space-y-6">
        <p class="text-center text-sm text-muted-foreground">
            We sent a verification link to
            <span class="font-medium text-foreground">{{ email }}</span
            >. Open it to activate your account. If it hasn't arrived, we can
            send another one.
        </p>

        <div
            v-if="mailIsLogged"
            class="rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-200"
            data-test="local-mail-hint"
        >
            Running locally? Mail isn't really sent. Open
            <code class="font-mono text-xs">storage/logs/laravel.log</code>
            and copy the verification link.
        </div>

        <Form v-bind="send.form()" v-slot="{ processing }">
            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="resend-verification-button"
            >
                <Spinner v-if="processing" />
                Resend verification email
            </Button>
        </Form>

        <div class="text-center text-sm text-muted-foreground">
            <TextLink :href="logout()" as="button" data-test="logout-link">
                Log out
            </TextLink>
        </div>
    </div>
</template>
