<script setup lang="ts">
import {
    AlarmClock,
    ArrowRightLeft,
    CalendarClock,
    CirclePlus,
    FileUp,
    Flag,
    Hand,
    History,
    Lock,
    MessageSquare,
    Pause,
    Pencil,
    Play,
    ShieldAlert,
    Tag,
    UserCheck,
    UserMinus,
} from '@lucide/vue';
import type { Component } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { ActivityItem } from '@/types/tickets';

defineProps<{
    activities: ActivityItem[];
    hasMore: boolean;
    loading: boolean;
}>();

const emit = defineEmits<{ loadMore: [] }>();

const icons: Record<string, Component> = {
    created: CirclePlus,
    updated: Pencil,
    assigned: UserCheck,
    claimed: Hand,
    unassigned: UserMinus,
    status_changed: ArrowRightLeft,
    priority_changed: Flag,
    category_changed: Tag,
    replied: MessageSquare,
    internal_note: Lock,
    sla_paused: Pause,
    sla_resumed: Play,
    sla_recalculated: CalendarClock,
    sla_approaching: AlarmClock,
    escalated: ShieldAlert,
    imported: FileUp,
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <EmptyState
            v-if="activities.length === 0"
            :icon="History"
            title="No activity yet"
        />
        <ol
            v-else
            class="relative flex flex-col gap-4 border-l pl-6"
            aria-label="Activity history"
        >
            <li
                v-for="activity in activities"
                :key="activity.id"
                class="relative"
                :data-activity="activity.type"
            >
                <span
                    class="absolute top-0 -left-[2.1rem] flex size-6 items-center justify-center rounded-full border bg-background"
                >
                    <component
                        :is="icons[activity.type] ?? History"
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                </span>
                <p class="text-sm break-words">{{ activity.description }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ activity.causer?.name ?? 'System' }} ·
                    <time
                        :datetime="activity.created_at ?? undefined"
                        :title="formatDateTime(activity.created_at)"
                        >{{ formatRelative(activity.created_at) }}</time
                    >
                </p>
            </li>
        </ol>
        <ol
            v-if="loading"
            class="flex flex-col gap-4 border-l pl-6"
            aria-label="Loading older activity"
            aria-busy="true"
        >
            <li v-for="row in 3" :key="row" class="flex flex-col gap-1.5">
                <Skeleton class="h-4 w-4/5" />
                <Skeleton class="h-3 w-1/3" />
            </li>
        </ol>
        <Button
            v-else-if="hasMore"
            variant="outline"
            size="sm"
            class="self-center"
            :disabled="loading"
            @click="emit('loadMore')"
        >
            Load older activity
        </Button>
    </div>
</template>
