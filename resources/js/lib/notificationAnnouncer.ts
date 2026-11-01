import type { LatestNotification } from '@/types/shared';

export function isNewerNotification(
    candidate: LatestNotification | null | undefined,
    lastAnnouncedAt: string | null,
): candidate is LatestNotification {
    if (!candidate) {
        return false;
    }

    if (lastAnnouncedAt === null) {
        return true;
    }

    return Date.parse(candidate.created_at) > Date.parse(lastAnnouncedAt);
}
