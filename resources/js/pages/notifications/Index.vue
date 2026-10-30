<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BellOff, CheckCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import { index, read, readAll } from '@/routes/notifications';
import type { NotificationItem } from '@/types/notifications';
import type { Paginated } from '@/types/pagination';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Notifications', href: index() }] },
});

const props = defineProps<{
    items: Paginated<NotificationItem>;
    unreadOnly: boolean;
}>();

const busy = ref<string | null>(null);
const hasUnread = computed(() =>
    props.items.data.some((item) => item.read_at === null),
);

const tabs = computed(() => [
    { label: 'All', href: index(), active: !props.unreadOnly },
    {
        label: 'Unread',
        href: index({ query: { unread: 1 } }),
        active: props.unreadOnly,
    },
]);

function markRead(item: NotificationItem, then?: () => void): void {
    router.post(
        read.url(item.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = item.id;
            },
            onFinish: () => {
                busy.value = null;
            },
            onSuccess: () => then?.(),
        },
    );
}

function open(item: NotificationItem): void {
    const go = (): void => {
        if (item.url !== null) {
            router.visit(item.url);
        }
    };

    if (item.read_at === null) {
        markRead(item, go);
    } else {
        go();
    }
}

function markAll(): void {
    router.post(
        readAll.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = 'all';
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Notifications" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Notifications"
            description="Updates about tickets you are involved in."
        >
            <template #actions>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!hasUnread || busy !== null"
                    @click="markAll"
                >
                    <Spinner v-if="busy === 'all'" />
                    <CheckCheck v-else aria-hidden="true" />
                    Mark all read
                </Button>
            </template>
        </PageHeader>

        <nav
            aria-label="Filter notifications"
            class="inline-flex w-fit rounded-lg border bg-card p-0.5 shadow-xs"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.label"
                :href="tab.href"
                preserve-state
                :aria-current="tab.active ? 'page' : undefined"
                :class="[
                    'rounded-md px-3 py-1.5 text-sm font-medium',
                    tab.active
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                >{{ tab.label }}</Link
            >
        </nav>

        <EmptyState
            v-if="items.data.length === 0"
            :icon="BellOff"
            :title="
                unreadOnly ? 'You are all caught up' : 'No notifications yet'
            "
            :description="
                unreadOnly
                    ? 'There are no unread notifications.'
                    : 'You will be notified about assignments, replies and SLA warnings.'
            "
        />

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="item in items.data"
                :key="item.id"
                :class="[
                    'flex items-start gap-3 rounded-xl border px-4 py-3 shadow-xs',
                    item.read_at === null
                        ? 'bg-tint-green text-tint-green-foreground'
                        : 'bg-card',
                ]"
            >
                <span
                    :class="[
                        'mt-2 size-2 shrink-0 rounded-full',
                        item.read_at === null ? 'bg-primary' : 'bg-transparent',
                    ]"
                    aria-hidden="true"
                />
                <button
                    type="button"
                    class="min-w-0 flex-1 rounded-sm text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="open(item)"
                >
                    <span
                        :class="[
                            'block text-sm break-words',
                            item.read_at === null ? 'font-semibold' : '',
                        ]"
                        >{{ item.title }}</span
                    >
                    <span
                        class="block text-sm break-words text-muted-foreground"
                        >{{ item.body }}</span
                    >
                    <time
                        :datetime="item.created_at ?? undefined"
                        :title="formatDateTime(item.created_at)"
                        class="block pt-0.5 text-xs text-muted-foreground"
                        >{{ formatRelative(item.created_at) }}</time
                    >
                </button>
                <Button
                    v-if="item.read_at === null"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    :disabled="busy !== null"
                    @click="markRead(item)"
                >
                    <Spinner v-if="busy === item.id" />
                    Mark read
                </Button>
            </li>
        </ul>

        <ResourcePagination
            :links="items.links"
            :meta="items.meta"
            noun="notifications"
        />
    </div>
</template>
