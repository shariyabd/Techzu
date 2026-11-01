<script lang="ts" setup>
import {
    CircleAlertIcon,
    CircleCheckIcon,
    InfoIcon,
    Loader2Icon,
    TriangleAlertIcon,
    XIcon,
} from '@lucide/vue';
import { useMutationObserver } from '@vueuse/core';
import type { ToasterProps } from 'vue-sonner';
import { Toaster as Sonner } from 'vue-sonner';
import { computed, onMounted, ref } from 'vue';
import { cn } from '@/lib/utils';

import 'vue-sonner/style.css';

const props = withDefaults(defineProps<ToasterProps>(), {
    position: 'top-right',
    closeButton: true,
    richColors: false,
    expand: false,
    invert: false,
    duration: 5000,
    gap: 10,
    visibleToasts: 4,
    offset: () => ({ top: '4.25rem', right: '1.25rem' }),
    mobileOffset: () => ({
        top: '4rem',
        right: '0.75rem',
        left: '0.75rem',
        bottom: '0.75rem',
    }),
    containerAriaLabel: 'Notifications',
});

const isDark = ref(false);

function syncTheme(): void {
    isDark.value = document.documentElement.classList.contains('dark');
}

onMounted(() => {
    syncTheme();
    useMutationObserver(document.documentElement, syncTheme, {
        attributes: true,
        attributeFilter: ['class'],
    });
});

const theme = computed(() => props.theme ?? (isDark.value ? 'dark' : 'light'));

const toastOptions = computed(() => ({
    ...props.toastOptions,
    unstyled: true,
    closeButtonAriaLabel: 'Dismiss notification',
    classes: {
        toast: 'app-toast',
        icon: 'app-toast-icon',
        content: 'app-toast-content',
        title: 'app-toast-title',
        description: 'app-toast-description',
        closeButton: 'app-toast-close',
        actionButton: 'app-toast-action',
        cancelButton: 'app-toast-cancel',
        ...props.toastOptions?.classes,
    },
}));
</script>

<template>
    <Sonner
        v-bind="props"
        :class="cn('toaster app-toaster group', props.class)"
        :style="{ '--width': '380px', ...props.style }"
        :theme="theme"
        :rich-colors="false"
        :toast-options="toastOptions"
    >
        <template #success-icon>
            <CircleCheckIcon aria-hidden="true" />
        </template>
        <template #info-icon>
            <InfoIcon aria-hidden="true" />
        </template>
        <template #warning-icon>
            <TriangleAlertIcon aria-hidden="true" />
        </template>
        <template #error-icon>
            <CircleAlertIcon aria-hidden="true" />
        </template>
        <template #loading-icon>
            <Loader2Icon class="animate-spin" aria-hidden="true" />
        </template>
        <template #close-icon>
            <XIcon aria-hidden="true" />
        </template>
    </Sonner>
</template>
