import { describe, expect, it } from 'vite-plus/test';
import { presenceStatus } from '@/composables/usePresence';

const now = Date.parse('2026-11-01T12:00:00Z');
const ago = (ms: number): string => new Date(now - ms).toISOString();
const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

describe('presenceStatus', () => {
    it.each([
        ['on the live channel', null, true, true, 'Active now'],
        ['seen just now', ago(0), false, true, 'Active now'],
        ['seen 4m59s ago', ago(5 * MINUTE - 1000), false, true, 'Active now'],
        [
            'seen exactly 5 minutes ago',
            ago(5 * MINUTE),
            false,
            false,
            'Active 5m ago',
        ],
        [
            'seen 59 minutes ago',
            ago(59 * MINUTE),
            false,
            false,
            'Active 59m ago',
        ],
        ['seen an hour ago', ago(HOUR), false, false, 'Active 1h ago'],
        [
            'seen 23 hours ago',
            ago(23 * HOUR + 59 * MINUTE),
            false,
            false,
            'Active 23h ago',
        ],
        ['seen a day ago', ago(DAY), false, false, 'Active 1d ago'],
        ['seen 10 days ago', ago(10 * DAY), false, false, 'Active 10d ago'],
        [
            'seen in the future (clock skew)',
            ago(-HOUR),
            false,
            true,
            'Active now',
        ],
        ['never seen', null, false, false, 'Offline'],
        ['with an unreadable timestamp', 'not-a-date', false, false, 'Offline'],
    ])('labels a user %s', (_case, lastSeenAt, live, active, label) => {
        expect(presenceStatus(lastSeenAt, live, now)).toEqual({
            active,
            label,
        });
    });
});
