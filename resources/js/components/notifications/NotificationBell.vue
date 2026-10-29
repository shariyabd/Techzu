<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/notifications';

const page = usePage();
const unread = computed(() => page.props.notifications.unread_count);
const badge = computed(() =>
    unread.value > 99 ? '99+' : String(unread.value),
);
const label = computed(() =>
    unread.value === 0
        ? 'Notifications'
        : `Notifications, ${unread.value} unread`,
);
</script>

<template>
    <Button variant="ghost" size="icon" class="relative" as-child>
        <Link
            :href="index()"
            :aria-label="label"
            data-testid="notification-bell"
        >
            <Bell aria-hidden="true" />
            <span
                v-if="unread > 0"
                class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-destructive px-1 text-center text-[10px] leading-4 font-semibold text-white"
                aria-hidden="true"
                >{{ badge }}</span
            >
        </Link>
    </Button>
</template>
