<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ActivityItem } from '@/types/tickets';

defineProps<{ activities: ActivityItem[] }>();

const formatTime = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '';
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Activity</CardTitle>
        </CardHeader>
        <CardContent>
            <p
                v-if="activities.length === 0"
                class="text-sm text-muted-foreground"
            >
                No activity yet.
            </p>
            <ol v-else class="space-y-3">
                <li
                    v-for="activity in activities"
                    :key="activity.id"
                    class="border-l-2 border-muted pl-3"
                    :data-activity="activity.type"
                >
                    <p class="text-sm">{{ activity.description }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatTime(activity.created_at) }} ·
                        {{ activity.causer?.name ?? 'System' }}
                    </p>
                </li>
            </ol>
        </CardContent>
    </Card>
</template>
