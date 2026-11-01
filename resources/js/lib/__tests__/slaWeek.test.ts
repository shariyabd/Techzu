import { describe, expect, it } from 'vite-plus/test';
import { nextWindow, windowError } from '@/lib/slaWeek';
import type { SlaWindow } from '@/types/admin';

describe('nextWindow', () => {
    it.each<[string, SlaWindow[], SlaWindow | null]>([
        ['an empty day', [], { start: '09:00', end: '18:00' }],
        [
            'a day with room after the last window',
            [{ start: '09:00', end: '12:00' }],
            { start: '12:00', end: '13:00' },
        ],
        [
            'a window ending late in the evening',
            [{ start: '18:00', end: '23:30' }],
            { start: '23:30', end: '24:00' },
        ],
        [
            'a window ending at 23:59',
            [{ start: '09:00', end: '23:59' }],
            { start: '23:59', end: '24:00' },
        ],
        [
            'a window running until midnight with a free morning',
            [{ start: '13:00', end: '24:00' }],
            { start: '00:00', end: '01:00' },
        ],
        [
            'a short morning gap before a midnight window',
            [{ start: '00:30', end: '24:00' }],
            { start: '00:00', end: '00:30' },
        ],
        ['a fully open day', [{ start: '00:00', end: '24:00' }], null],
        [
            'a day filled by adjacent windows',
            [
                { start: '12:00', end: '24:00' },
                { start: '00:00', end: '12:00' },
            ],
            null,
        ],
    ])(
        'never proposes a zero-length or inverted window for %s',
        (_, windows, expected) => {
            const proposed = nextWindow(windows);

            expect(proposed).toEqual(expected);

            if (proposed) {
                expect(windowError(proposed)).toBeNull();
            }
        },
    );
});

describe('windowError', () => {
    it.each<[SlaWindow, string | null]>([
        [{ start: '09:00', end: '18:00' }, null],
        [{ start: '23:00', end: '24:00' }, null],
        [
            { start: '23:59', end: '23:59' },
            'The window must end after it starts.',
        ],
        [
            { start: '18:00', end: '09:00' },
            'The window must end after it starts.',
        ],
        [
            { start: '24:00', end: '24:00' },
            '24:00 can only end a window. Start it at 23:59 or earlier.',
        ],
        [
            { start: '', end: '18:00' },
            'Use HH:MM times between 00:00 and 24:00.',
        ],
    ])('mirrors the server rule for %o', (window, expected) => {
        expect(windowError(window)).toBe(expected);
    });
});
