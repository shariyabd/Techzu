<script setup lang="ts">
import PresenceAvatar from '@/components/chat/PresenceAvatar.vue';
import type { PresenceStatus } from '@/composables/usePresence';

defineProps<{
    name: string;
    avatarUrl?: string | null;
    status: PresenceStatus;
    statusLabel: string;
    context?: string;
}>();
</script>

<template>
    <header
        class="flex shrink-0 items-center gap-3 border-b bg-surface px-4 py-3"
    >
        <PresenceAvatar
            :name="name"
            :src="avatarUrl"
            :active="status.active"
            class="size-10"
            dot-class="size-3"
        />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold">{{ name }}</p>
            <p
                class="flex items-center gap-1.5 truncate text-xs"
                :class="
                    status.active
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : 'text-muted-foreground'
                "
                aria-live="polite"
            >
                {{ statusLabel }}
            </p>
        </div>
        <p
            v-if="context"
            class="hidden shrink-0 font-mono text-xs text-muted-foreground sm:block"
        >
            {{ context }}
        </p>
    </header>
</template>
