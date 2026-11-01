<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M9 4h14a6 6 0 0 1 6 6v7a6 6 0 0 1-6 6h-9l-5.5 4.5c-.6.5-1.5.1-1.5-.7V23a6 6 0 0 1-6-6v-7a6 6 0 0 1 6-6Zm.7 9.9 1.9-1.9 2.8 2.8 6-6 1.9 1.9-7.9 7.9-4.7-4.7Z"
        />
    </svg>
</template>
