import type { SlaWindow } from '@/types/admin';

export const END_OF_DAY = '24:00';

const MINUTES_PER_DAY = 24 * 60;
const LAST_START_MINUTE = MINUTES_PER_DAY - 1;
const DEFAULT_WINDOW: SlaWindow = { start: '09:00', end: '18:00' };
const NEW_WINDOW_MINUTES = 60;
const TIME_PATTERN = /^(([01]\d|2[0-3]):[0-5]\d|24:00)$/;

export function isWindowTime(value: string): boolean {
    return TIME_PATTERN.test(value);
}

export function toMinutes(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return (hours ?? 0) * 60 + (minutes ?? 0);
}

export function toTime(total: number): string {
    const clamped = Math.min(Math.max(total, 0), MINUTES_PER_DAY);

    if (clamped === MINUTES_PER_DAY) {
        return END_OF_DAY;
    }

    return `${String(Math.floor(clamped / 60)).padStart(2, '0')}:${String(clamped % 60).padStart(2, '0')}`;
}

export function windowError(window: SlaWindow): string | null {
    if (!isWindowTime(window.start) || !isWindowTime(window.end)) {
        return 'Use HH:MM times between 00:00 and 24:00.';
    }

    if (window.start === END_OF_DAY) {
        return '24:00 can only end a window. Start it at 23:59 or earlier.';
    }

    if (window.start >= window.end) {
        return 'The window must end after it starts.';
    }

    return null;
}

function occupied(windows: SlaWindow[]): { start: number; end: number }[] {
    return windows
        .filter((window) => windowError(window) === null)
        .map((window) => ({
            start: toMinutes(window.start),
            end: toMinutes(window.end),
        }))
        .sort((a, b) => a.start - b.start);
}

function windowFrom(start: number, limit: number): SlaWindow {
    return {
        start: toTime(start),
        end: toTime(Math.min(start + NEW_WINDOW_MINUTES, limit)),
    };
}

export function nextWindow(windows: SlaWindow[]): SlaWindow | null {
    const ranges = occupied(windows);

    if (ranges.length === 0) {
        return { ...DEFAULT_WINDOW };
    }

    const latestEnd = Math.max(...ranges.map((range) => range.end));

    if (latestEnd <= LAST_START_MINUTE) {
        return windowFrom(latestEnd, MINUTES_PER_DAY);
    }

    let cursor = 0;

    for (const range of ranges) {
        if (range.start > cursor) {
            return windowFrom(cursor, range.start);
        }

        cursor = Math.max(cursor, range.end);
    }

    return null;
}

export function endBeforeMidnight(start: string): string {
    return toTime(
        Math.min(toMinutes(start) + NEW_WINDOW_MINUTES, LAST_START_MINUTE),
    );
}
