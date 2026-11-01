import { describe, expect, it } from 'vite-plus/test';
import { isNewerNotification } from '@/lib/notificationAnnouncer';

const notification = (createdAt: string) => ({
    id: createdAt,
    title: 'New reply on TKT-2026-000001',
    body: 'Thanks, we are on it.',
    url: '/tickets/TKT-2026-000001',
    created_at: createdAt,
});

describe('isNewerNotification', () => {
    it.each([
        ['nothing is unread', null, null, false],
        [
            'the first unread arrives',
            notification('2026-11-01T10:00:00Z'),
            null,
            true,
        ],
        [
            'a newer one arrives',
            notification('2026-11-01T10:05:00Z'),
            '2026-11-01T10:00:00Z',
            true,
        ],
        [
            'the same one is reloaded',
            notification('2026-11-01T10:00:00Z'),
            '2026-11-01T10:00:00Z',
            false,
        ],
        [
            'reading surfaces an older unread',
            notification('2026-11-01T09:00:00Z'),
            '2026-11-01T10:00:00Z',
            false,
        ],
    ])(
        'announces only when %s is newer',
        (_label, candidate, lastAnnouncedAt, expected) => {
            expect(isNewerNotification(candidate, lastAnnouncedAt)).toBe(
                expected,
            );
        },
    );
});
