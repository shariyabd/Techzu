import { describe, expect, it } from 'vite-plus/test';
import {
    formatBusinessDateTime,
    formatBusinessDuration,
    formatSlaRemaining,
} from '@/lib/format';

const normalise = (value: string): string => value.replace(/\s/g, ' ');

describe('formatBusinessDateTime', () => {
    it.each([
        ['Asia/Dhaka', 'Nov 2, 11:36 AM GMT+6'],
        ['America/New_York', 'Nov 2, 12:36 AM EST'],
        ['UTC', 'Nov 2, 5:36 AM UTC'],
    ])(
        'renders SLA times in the business timezone %s with its zone label',
        (timeZone, expected) => {
            expect(
                normalise(
                    formatBusinessDateTime(
                        '2026-11-02T05:36:00Z',
                        timeZone,
                        'en-US',
                    ),
                ),
            ).toBe(expected);
        },
    );

    it('renders a dash when there is no deadline', () => {
        expect(formatBusinessDateTime(null, 'Asia/Dhaka', 'en-US')).toBe('—');
    });

    it('still renders a time when the stored timezone is unknown to the browser', () => {
        expect(
            formatBusinessDateTime(
                '2026-11-02T05:36:00Z',
                'Mars/Olympus_Mons',
                'en-US',
            ),
        ).toMatch(/Nov \d/);
    });
});

describe('formatBusinessDuration', () => {
    it.each([
        [7500, false, '2h 05m'],
        [4815, true, '1h 20m 15s'],
        [2710, true, '45m 10s'],
        [2710, false, '45m'],
        [42, true, '42s'],
        [42, false, '<1m'],
        [0, false, '0m'],
        [273_600, false, '3d 4h'],
        [273_600, true, '3d 4h'],
        [-2405, true, '40m 05s'],
    ])(
        'formats %i business seconds (seconds: %s) as %s',
        (seconds, withSeconds, expected) => {
            expect(formatBusinessDuration(seconds, withSeconds)).toBe(expected);
        },
    );
});

describe('formatSlaRemaining', () => {
    it.each([
        [4815, true, '1h 20m 15s left'],
        [4800, false, '1h 20m left'],
        [-2405, true, 'Overdue by 40m 05s'],
        [-2400, false, 'Overdue by 40m'],
    ])(
        'describes %i business seconds as %s',
        (seconds, withSeconds, expected) => {
            expect(formatSlaRemaining(seconds, withSeconds)).toBe(expected);
        },
    );
});
