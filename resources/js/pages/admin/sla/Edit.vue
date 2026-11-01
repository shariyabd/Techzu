<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SlaSettingsForm from '@/components/admin/SlaSettingsForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { edit } from '@/routes/admin/sla';
import type { SlaSettings } from '@/types/admin';

defineProps<{ settings: SlaSettings; timezones: string[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'SLA & business hours', href: edit() }],
    },
});
</script>

<template>
    <Head title="SLA & business hours" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="SLA & business hours"
            :description="
                settings.customized
                    ? 'Resolution targets and opening hours used for SLA deadlines.'
                    : 'Resolution targets and opening hours used for SLA deadlines. These are the built-in defaults until you save.'
            "
        />
        <SlaSettingsForm :settings="settings" :timezones="timezones" />
    </div>
</template>
