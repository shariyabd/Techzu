<script setup lang="ts">
import { UserRound } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

const props = defineProps<{
    name: string;
    src?: string | null;
    class?: HTMLAttributes['class'];
    fallbackClass?: HTMLAttributes['class'];
}>();
</script>

<template>
    <Avatar :class="cn('size-8 shrink-0', props.class)">
        <AvatarImage v-if="src" :src="src" :alt="name" />
        <AvatarFallback
            :class="cn('bg-muted text-muted-foreground', props.fallbackClass)"
            :title="name"
        >
            <UserRound class="size-[60%]" aria-hidden="true" />
            <span class="sr-only">{{ name }}</span>
        </AvatarFallback>
    </Avatar>
</template>
