<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    END_OF_DAY,
    endBeforeMidnight,
    nextWindow,
    windowError,
} from '@/lib/slaWeek';
import type { SlaWeek, SlaWeekday, SlaWindow } from '@/types/admin';

const MAX_WINDOWS_PER_DAY = 6;

defineProps<{ errors: Record<string, string | undefined> }>();

const week = defineModel<SlaWeek>({ required: true });

const days: { key: SlaWeekday; label: string }[] = [
    { key: 'sunday', label: 'Sunday' },
    { key: 'monday', label: 'Monday' },
    { key: 'tuesday', label: 'Tuesday' },
    { key: 'wednesday', label: 'Wednesday' },
    { key: 'thursday', label: 'Thursday' },
    { key: 'friday', label: 'Friday' },
    { key: 'saturday', label: 'Saturday' },
];

function canAddWindow(day: SlaWeekday): boolean {
    return (
        week.value[day].length < MAX_WINDOWS_PER_DAY &&
        nextWindow(week.value[day]) !== null
    );
}

function setDay(day: SlaWeekday, windows: SlaWindow[]): void {
    week.value = { ...week.value, [day]: windows };
}

function addWindow(day: SlaWeekday): void {
    const window = nextWindow(week.value[day]);

    if (window === null || week.value[day].length >= MAX_WINDOWS_PER_DAY) {
        return;
    }

    setDay(day, [...week.value[day], window]);
}

function removeWindow(day: SlaWeekday, index: number): void {
    setDay(
        day,
        week.value[day].filter((_, position) => position !== index),
    );
}

function setEndOfDay(
    day: SlaWeekday,
    index: number,
    range: SlaWindow,
    untilMidnight: boolean,
): void {
    updateWindow(
        day,
        index,
        'end',
        untilMidnight ? END_OF_DAY : endBeforeMidnight(range.start),
    );
}

function updateWindow(
    day: SlaWeekday,
    index: number,
    edge: keyof SlaWindow,
    value: string | number,
): void {
    setDay(
        day,
        week.value[day].map((entry, position) =>
            position === index ? { ...entry, [edge]: String(value) } : entry,
        ),
    );
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <ul class="divide-y rounded-xl border">
            <li
                v-for="day in days"
                :key="day.key"
                class="flex flex-col gap-3 p-3 sm:flex-row sm:items-start"
            >
                <div class="flex items-center justify-between sm:w-32 sm:pt-2">
                    <span class="text-sm font-medium">{{ day.label }}</span>
                    <span
                        v-if="week[day.key].length === 0"
                        class="text-xs text-muted-foreground sm:hidden"
                        >Closed</span
                    >
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <p
                        v-if="week[day.key].length === 0"
                        class="hidden text-sm text-muted-foreground sm:block sm:pt-2"
                    >
                        Closed
                    </p>
                    <div
                        v-for="(range, index) in week[day.key]"
                        :key="index"
                        class="flex flex-col gap-1"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Input
                                :id="`week-${day.key}-${index}-start`"
                                type="time"
                                step="60"
                                class="w-32"
                                :aria-label="`${day.label} window ${index + 1} opens`"
                                :model-value="range.start"
                                @update:model-value="
                                    (value) =>
                                        updateWindow(
                                            day.key,
                                            index,
                                            'start',
                                            value,
                                        )
                                "
                            />
                            <span
                                class="text-sm text-muted-foreground"
                                aria-hidden="true"
                                >to</span
                            >
                            <span
                                v-if="range.end === END_OF_DAY"
                                class="flex h-9 w-32 items-center rounded-md border border-dashed px-3 text-sm text-muted-foreground"
                                >Midnight</span
                            >
                            <Input
                                v-else
                                :id="`week-${day.key}-${index}-end`"
                                type="time"
                                step="60"
                                class="w-32"
                                :aria-label="`${day.label} window ${index + 1} closes`"
                                :model-value="range.end"
                                @update:model-value="
                                    (value) =>
                                        updateWindow(
                                            day.key,
                                            index,
                                            'end',
                                            value,
                                        )
                                "
                            />
                            <div class="flex items-center gap-1.5">
                                <Checkbox
                                    :id="`week-${day.key}-${index}-midnight`"
                                    :model-value="range.end === END_OF_DAY"
                                    @update:model-value="
                                        (value) =>
                                            setEndOfDay(
                                                day.key,
                                                index,
                                                range,
                                                value === true,
                                            )
                                    "
                                />
                                <Label
                                    :for="`week-${day.key}-${index}-midnight`"
                                    class="text-xs font-normal text-muted-foreground"
                                    >Until midnight</Label
                                >
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove ${day.label} window ${index + 1}`"
                                @click="removeWindow(day.key, index)"
                                ><Trash2 aria-hidden="true"
                            /></Button>
                        </div>
                        <InputError
                            :message="
                                windowError(range) ??
                                errors[`week.${day.key}.${index}.start`] ??
                                errors[`week.${day.key}.${index}.end`] ??
                                undefined
                            "
                        />
                    </div>
                    <InputError :message="errors[`week.${day.key}`]" />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    :disabled="!canAddWindow(day.key)"
                    @click="addWindow(day.key)"
                    ><Plus aria-hidden="true" />Add hours</Button
                >
            </li>
        </ul>
        <InputError :message="errors.week" />
    </div>
</template>
