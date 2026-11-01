<script setup lang="ts">
import { savedReplyPlaceholders } from '@/lib/savedReplies';

const { interactive = false } = defineProps<{ interactive?: boolean }>();

const emit = defineEmits<{ pick: [token: string] }>();

const braced = (token: string): string => ['{', token, '}'].join('');
</script>

<template>
    <ul class="grid gap-1.5 text-sm sm:grid-cols-2">
        <li
            v-for="placeholder in savedReplyPlaceholders"
            :key="placeholder.token"
            class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5"
        >
            <button
                v-if="interactive"
                type="button"
                class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-xs hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-label="`Insert ${braced(placeholder.token)}: ${placeholder.description}`"
                @click="emit('pick', braced(placeholder.token))"
            >
                {{ braced(placeholder.token) }}
            </button>
            <code
                v-else
                class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-xs"
                >{{ braced(placeholder.token) }}</code
            >
            <span class="text-xs text-muted-foreground">{{
                placeholder.description
            }}</span>
        </li>
    </ul>
</template>
