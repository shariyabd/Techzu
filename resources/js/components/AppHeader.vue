<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, Moon, Sun } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import NavUser from '@/components/NavUser.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { canSearchTickets, groupedNavigationFor } from '@/config/navigation';
import { home } from '@/routes';

const page = usePage();
const sections = computed(() => groupedNavigationFor(page.props));
const searchable = computed(() => canSearchTickets(page.props));
const { isCurrentOrParentUrl } = useCurrentUrl();

const storageKey = 'appearance';
const isDark = ref(false);
const menuOpen = ref(false);

function readStoredTheme(): string | null {
    try {
        return window.localStorage.getItem(storageKey);
    } catch {
        return null;
    }
}

function applyTheme(dark: boolean): void {
    isDark.value = dark;
    document.documentElement.classList.toggle('dark', dark);
}

function toggleTheme(): void {
    const next = !isDark.value;

    applyTheme(next);

    try {
        window.localStorage.setItem(storageKey, next ? 'dark' : 'light');
    } catch {
        return;
    }
}

onMounted(() => {
    const stored = readStoredTheme();

    if (stored === 'dark' || stored === 'light') {
        applyTheme(stored === 'dark');

        return;
    }

    isDark.value = document.documentElement.classList.contains('dark');
});
</script>

<template>
    <header
        class="sticky top-0 z-40 flex h-14 shrink-0 items-center gap-2 border-b bg-surface px-3 text-surface-foreground md:gap-4 md:px-4"
    >
        <div class="flex shrink-0 items-center gap-1 md:w-[200px]">
            <Sheet v-model:open="menuOpen">
                <SheetTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="md:hidden"
                        aria-label="Open navigation menu"
                        data-test="mobile-nav-trigger"
                    >
                        <Menu aria-hidden="true" />
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="w-72 p-0">
                    <SheetHeader class="border-b p-4 text-left">
                        <SheetTitle class="sr-only">Navigation menu</SheetTitle>
                        <SheetDescription class="sr-only"
                            >Links to the areas you can use.</SheetDescription
                        >
                        <AppLogoIcon class="size-6 fill-current" />
                    </SheetHeader>
                    <nav aria-label="Mobile" class="flex flex-col gap-4 p-3">
                        <div
                            v-for="section in sections"
                            :key="section.group"
                            class="flex flex-col gap-1"
                        >
                            <p
                                class="px-3 text-xs font-medium text-muted-foreground"
                            >
                                {{ section.label }}
                            </p>
                            <Link
                                v-for="item in section.items"
                                :key="item.href"
                                :href="item.href"
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                                :class="
                                    isCurrentOrParentUrl(item.href)
                                        ? 'bg-accent text-accent-foreground'
                                        : ''
                                "
                                @click="menuOpen = false"
                            >
                                <component
                                    :is="item.icon"
                                    v-if="item.icon"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ item.title }}
                            </Link>
                        </div>
                    </nav>
                </SheetContent>
            </Sheet>
            <Link
                :href="home()"
                class="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <AppLogo />
            </Link>
        </div>

        <div class="flex min-w-0 flex-1 justify-center">
            <GlobalSearch v-if="searchable" />
        </div>

        <div
            class="flex shrink-0 items-center gap-1 md:w-[200px] md:justify-end"
        >
            <NotificationBell />
            <Button
                variant="ghost"
                size="icon"
                :aria-label="
                    isDark ? 'Switch to light theme' : 'Switch to dark theme'
                "
                data-test="theme-toggle"
                @click="toggleTheme"
            >
                <Sun v-if="isDark" aria-hidden="true" />
                <Moon v-else aria-hidden="true" />
            </Button>
            <NavUser />
        </div>
    </header>
</template>
