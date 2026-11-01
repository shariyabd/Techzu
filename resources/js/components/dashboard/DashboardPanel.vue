<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    titleId?: string;
}>();
</script>

<template>
    <section
        :aria-labelledby="titleId"
        class="flex min-w-0 flex-col gap-4 rounded-xl border bg-card p-4 text-card-foreground shadow-xs md:p-5"
    >
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 :id="titleId" class="text-base font-semibold">
                    {{ title }}
                </h2>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <slot name="actions" />
        </header>
        <div class="flex min-h-0 flex-1 flex-col">
            <slot />
        </div>
    </section>
</template>
