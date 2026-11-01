<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { agentQueueSummary, agentQueueTone } from '@/components/agents/links';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { presenceStatus } from '@/composables/usePresence';
import type { DirectoryAgent } from '@/types/agents';

const props = defineProps<{
    agent: DirectoryAgent;
    href: NonNullable<InertiaLinkProps['href']>;
    selected: boolean;
    now: number;
    showRole: boolean;
}>();

const presence = computed(() =>
    presenceStatus(props.agent.last_seen_at, false, props.now),
);
const tone = computed(() => agentQueueTone(props.agent.stats));
const summary = computed(() => agentQueueSummary(props.agent));
</script>

<template>
    <Link
        :href="href"
        :only="['agent', 'tab', 'tickets']"
        preserve-scroll
        preserve-state
        :aria-current="selected ? 'page' : undefined"
        :data-agent="agent.id"
        :class="[
            'flex min-w-0 items-center gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
            selected
                ? 'bg-primary/10 text-foreground ring-1 ring-primary/20'
                : 'hover:bg-muted',
        ]"
    >
        <span class="relative inline-flex shrink-0">
            <Avatar class="size-9">
                <AvatarImage
                    v-if="agent.avatar_url"
                    :src="agent.avatar_url"
                    :alt="agent.name"
                />
                <AvatarFallback class="text-xs">{{
                    getInitials(agent.name)
                }}</AvatarFallback>
            </Avatar>
            <span
                v-if="presence.active"
                class="absolute right-0 bottom-0 size-2.5 rounded-full bg-emerald-500 ring-2 ring-background"
                data-presence="active"
                aria-hidden="true"
            />
        </span>
        <span class="flex min-w-0 flex-1 flex-col">
            <span class="truncate font-medium">{{ agent.name }}</span>
            <span
                class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
            >
                <span
                    :class="[
                        'truncate',
                        presence.active
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : '',
                    ]"
                    >{{ presence.label }}</span
                >
                <span
                    v-if="!agent.is_available"
                    class="shrink-0 rounded bg-muted px-1.5 py-px text-[11px] leading-4"
                    >On leave</span
                >
                <span
                    v-if="showRole && agent.role === 'admin'"
                    class="shrink-0 rounded bg-muted px-1.5 py-px text-[11px] leading-4"
                    >Admin</span
                >
            </span>
        </span>
        <span
            class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground tabular-nums"
            :title="summary"
        >
            <span
                v-if="tone"
                :class="[
                    'size-2 rounded-full',
                    tone === 'danger' ? 'bg-red-500' : 'bg-amber-500',
                ]"
                :data-queue-tone="tone"
                aria-hidden="true"
            />
            <span aria-hidden="true"
                ><span class="font-semibold text-foreground">{{
                    agent.stats.active
                }}</span>
                / {{ agent.stats.total_assigned }}</span
            >
            <span class="sr-only">{{ summary }}</span>
        </span>
    </Link>
</template>
