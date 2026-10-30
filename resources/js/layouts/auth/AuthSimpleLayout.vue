<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const name = usePage().props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-4 md:p-10"
    >
        <div class="flex w-full max-w-sm flex-col gap-6">
            <Link
                :href="home()"
                class="flex items-center gap-2 self-center rounded-md font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                <span>{{ name }}</span>
            </Link>
            <div
                class="flex flex-col gap-6 rounded-xl border bg-card p-6 text-card-foreground shadow-sm sm:p-8"
            >
                <div class="flex flex-col gap-1 text-center">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
