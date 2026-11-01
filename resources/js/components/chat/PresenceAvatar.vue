<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    name: string;
    src?: string | null;
    active?: boolean;
    class?: HTMLAttributes['class'];
    fallbackClass?: HTMLAttributes['class'];
    dotClass?: HTMLAttributes['class'];
}>();
</script>

<template>
    <span class="relative inline-flex shrink-0">
        <UserAvatar
            :name="name"
            :src="src"
            :class="props.class"
            :fallback-class="fallbackClass"
        />
        <span
            v-if="active"
            :class="
                cn(
                    'absolute right-0 bottom-0 size-2.5 rounded-full bg-emerald-500 ring-2 ring-surface',
                    dotClass,
                )
            "
            data-presence="active"
            aria-hidden="true"
        />
    </span>
</template>
