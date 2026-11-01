<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SlaRecalculateOption from '@/components/admin/SlaRecalculateOption.vue';
import SlaWeekEditor from '@/components/admin/SlaWeekEditor.vue';
import TableCard from '@/components/admin/TableCard.vue';
import SubmitButton from '@/components/SubmitButton.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { update } from '@/routes/admin/sla';
import type { SlaPriority, SlaSettings, SlaWeek } from '@/types/admin';

const props = defineProps<{ settings: SlaSettings; timezones: string[] }>();

const priorities: { value: SlaPriority; label: string }[] = [
    { value: 'critical', label: 'Critical' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
];

function cloneWeek(week: SlaWeek): SlaWeek {
    return Object.fromEntries(
        Object.entries(week).map(([day, windows]) => [
            day,
            windows.map((range) => ({ ...range })),
        ]),
    ) as SlaWeek;
}

const form = useForm({
    resolution_minutes: { ...props.settings.resolution_minutes },
    warning_threshold_percent: props.settings.warning_threshold_percent,
    timezone: props.settings.timezone,
    week: cloneWeek(props.settings.week),
    recalculate_active_tickets: false,
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const timezoneOptions = computed(() =>
    props.timezones.includes(form.timezone)
        ? props.timezones
        : [form.timezone, ...props.timezones],
);

function hoursOf(priority: SlaPriority): number {
    return Math.floor(form.resolution_minutes[priority] / 60);
}

function minutesOf(priority: SlaPriority): number {
    return form.resolution_minutes[priority] % 60;
}

function toWhole(value: string | number): number {
    const parsed = Math.floor(Number(value));

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function setHours(priority: SlaPriority, value: string | number): void {
    form.resolution_minutes[priority] =
        toWhole(value) * 60 + minutesOf(priority);
}

function setMinutes(priority: SlaPriority, value: string | number): void {
    form.resolution_minutes[priority] =
        hoursOf(priority) * 60 + Math.min(toWhole(value), 59);
}

function formatDuration(total: number): string {
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return `${minutes}m`;
    }

    return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
}

function warningAfter(priority: SlaPriority): string {
    const threshold = Number(form.warning_threshold_percent) || 0;

    return formatDuration(
        Math.floor((form.resolution_minutes[priority] * threshold) / 100),
    );
}

function submit(): void {
    form.put(update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.recalculate_active_tickets = false;
        },
    });
}
</script>

<template>
    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <Card>
            <CardHeader>
                <CardTitle>Resolution targets</CardTitle>
                <CardDescription
                    >Counted in business time only: opening hours, excluding
                    closed days and holidays.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-5">
                <TableCard>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Priority</TableHead>
                                <TableHead>Hours</TableHead>
                                <TableHead>Minutes</TableHead>
                                <TableHead class="hidden sm:table-cell"
                                    >Warning after</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="priority in priorities"
                                :key="priority.value"
                            >
                                <TableCell class="font-medium">{{
                                    priority.label
                                }}</TableCell>
                                <TableCell>
                                    <Input
                                        :id="`target-${priority.value}-hours`"
                                        type="number"
                                        min="0"
                                        max="720"
                                        class="w-20 sm:w-24"
                                        :aria-label="`${priority.label} target hours`"
                                        :model-value="hoursOf(priority.value)"
                                        @update:model-value="
                                            (value) =>
                                                setHours(priority.value, value)
                                        "
                                    />
                                </TableCell>
                                <TableCell>
                                    <Input
                                        :id="`target-${priority.value}-minutes`"
                                        type="number"
                                        min="0"
                                        max="59"
                                        class="w-20 sm:w-24"
                                        :aria-label="`${priority.label} target minutes`"
                                        :model-value="minutesOf(priority.value)"
                                        @update:model-value="
                                            (value) =>
                                                setMinutes(
                                                    priority.value,
                                                    value,
                                                )
                                        "
                                    />
                                    <InputError
                                        :message="
                                            errors[
                                                `resolution_minutes.${priority.value}`
                                            ]
                                        "
                                    />
                                </TableCell>
                                <TableCell
                                    class="hidden text-muted-foreground tabular-nums sm:table-cell"
                                    >{{
                                        warningAfter(priority.value)
                                    }}</TableCell
                                >
                            </TableRow>
                        </TableBody>
                    </Table>
                </TableCard>
                <InputError :message="errors.resolution_minutes" />
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="warning_threshold_percent"
                            >Warning threshold (%)</Label
                        >
                        <Input
                            id="warning_threshold_percent"
                            v-model.number="form.warning_threshold_percent"
                            type="number"
                            min="1"
                            max="99"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            A ticket is flagged as approaching its deadline once
                            this share of its resolution time has passed.
                        </p>
                        <InputError
                            :message="form.errors.warning_threshold_percent"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="timezone">Business timezone</Label>
                        <NativeSelect id="timezone" v-model="form.timezone">
                            <NativeSelectOption
                                v-for="zone in timezoneOptions"
                                :key="zone"
                                :value="zone"
                                >{{ zone }}</NativeSelectOption
                            >
                        </NativeSelect>
                        <p class="text-xs text-muted-foreground">
                            Opening hours below are read in this timezone.
                        </p>
                        <InputError :message="form.errors.timezone" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Business hours</CardTitle>
                <CardDescription
                    >Add one or more opening windows per day, for example to
                    leave out a lunch break. A day without windows is
                    closed.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <SlaWeekEditor v-model="form.week" :errors="errors" />
            </CardContent>
        </Card>

        <SlaRecalculateOption v-model="form.recalculate_active_tickets" />

        <div class="flex flex-wrap gap-2">
            <SubmitButton :processing="form.processing"
                >Save settings</SubmitButton
            >
        </div>
    </form>
</template>
