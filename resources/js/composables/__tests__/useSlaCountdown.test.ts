import { describe, expect, it } from 'vite-plus/test';
import { slaCountdownAt } from '@/composables/useSlaCountdown';
import type { SlaClock } from '@/types/tickets';

const receivedAt = Date.parse('2026-10-29T11:30:00Z');
const after = (seconds: number): number => receivedAt + seconds * 1000;
const iso = (seconds: number): string => new Date(after(seconds)).toISOString();

const clock = (overrides: Partial<SlaClock>): SlaClock => ({
    remaining_seconds: 5400,
    running: true,
    changes_at: iso(1800),
    resumes_at: null,
    ...overrides,
});

describe('slaCountdownAt', () => {
    it.each([
        ['ticks down while running', clock({}), 90, 5310, true, null],
        [
            'stops at the end of the business window',
            clock({}),
            7200,
            3600,
            false,
            null,
        ],
        [
            'keeps running without a known transition',
            clock({ changes_at: null }),
            600,
            4800,
            true,
            null,
        ],
        [
            'crosses zero into overdue while running',
            clock({ remaining_seconds: 2 }),
            5,
            -3,
            true,
            null,
        ],
        [
            'stays frozen outside business hours until it resumes',
            clock({
                running: false,
                changes_at: iso(3600),
                resumes_at: iso(3600),
            }),
            1200,
            5400,
            false,
            iso(3600),
        ],
        [
            'starts ticking again once business hours resume',
            clock({
                running: false,
                changes_at: iso(3600),
                resumes_at: iso(3600),
            }),
            3660,
            5340,
            true,
            null,
        ],
        [
            'stays frozen while paused for the customer',
            clock({ running: false, changes_at: null, resumes_at: null }),
            86_400,
            5400,
            false,
            null,
        ],
    ])('%s', (_name, input, elapsed, seconds, running, resumesAt) => {
        expect(slaCountdownAt(input, receivedAt, after(elapsed))).toEqual({
            seconds,
            running,
            resumesAt,
        });
    });
});
