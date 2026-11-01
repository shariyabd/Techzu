import type { MaybeRefOrGetter, Ref, ShallowRef } from 'vue';
import {
    computed,
    getCurrentScope,
    onScopeDispose,
    readonly,
    ref,
    shallowRef,
    toValue,
    watch,
} from 'vue';
import { realtimeChannels } from '@/composables/useRealtime';
import { realtime } from '@/lib/echo';
import type { RealtimePresenceChannel } from '@/lib/echo';

export type PresenceRole = 'customer' | 'staff';

export type PresenceMember = {
    id: number;
    name: string;
    avatar_url: string | null;
    role: PresenceRole;
};

export type PresenceStatus = { active: boolean; label: string };

const ACTIVE_WINDOW_MS = 5 * 60 * 1000;
const CLOCK_TICK_MS = 30 * 1000;
const MINUTE_MS = 60 * 1000;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;

const clock = ref(Date.now());
let clockUsers = 0;
let clockTimer: ReturnType<typeof setInterval> | undefined;

export function usePresenceClock(): Readonly<Ref<number>> {
    if (typeof window !== 'undefined' && getCurrentScope()) {
        clockUsers += 1;
        clock.value = Date.now();
        clockTimer ??= setInterval(() => {
            clock.value = Date.now();
        }, CLOCK_TICK_MS);

        onScopeDispose(() => {
            clockUsers -= 1;

            if (clockUsers <= 0) {
                clearInterval(clockTimer);
                clockTimer = undefined;
                clockUsers = 0;
            }
        });
    }

    return readonly(clock);
}

export function presenceStatus(
    lastSeenAt: string | null | undefined,
    live: boolean,
    now: number,
): PresenceStatus {
    if (live) {
        return { active: true, label: 'Active now' };
    }

    const seenAt = lastSeenAt ? Date.parse(lastSeenAt) : Number.NaN;

    if (Number.isNaN(seenAt)) {
        return { active: false, label: 'Offline' };
    }

    const elapsed = Math.max(0, now - seenAt);

    if (elapsed < ACTIVE_WINDOW_MS) {
        return { active: true, label: 'Active now' };
    }

    if (elapsed < HOUR_MS) {
        return {
            active: false,
            label: `Active ${Math.floor(elapsed / MINUTE_MS)}m ago`,
        };
    }

    if (elapsed < DAY_MS) {
        return {
            active: false,
            label: `Active ${Math.floor(elapsed / HOUR_MS)}h ago`,
        };
    }

    return {
        active: false,
        label: `Active ${Math.floor(elapsed / DAY_MS)}d ago`,
    };
}

export function latestSeen(
    ...candidates: (string | number | null | undefined)[]
): string | null {
    const times = candidates
        .map((candidate) =>
            typeof candidate === 'number'
                ? candidate
                : candidate
                  ? Date.parse(candidate)
                  : Number.NaN,
        )
        .filter((time) => !Number.isNaN(time));

    return times.length > 0 ? new Date(Math.max(...times)).toISOString() : null;
}

function toMember(value: unknown): PresenceMember | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const candidate = value as Record<string, unknown>;

    if (
        typeof candidate.id !== 'number' ||
        typeof candidate.name !== 'string' ||
        (candidate.role !== 'customer' && candidate.role !== 'staff')
    ) {
        return null;
    }

    return {
        id: candidate.id,
        name: candidate.name,
        avatar_url:
            typeof candidate.avatar_url === 'string'
                ? candidate.avatar_url
                : null,
        role: candidate.role,
    };
}

export type TicketPresence = {
    channel: ShallowRef<RealtimePresenceChannel | null>;
    members: Ref<PresenceMember[]>;
    departedAt: Ref<Record<number, number>>;
    isLive: (userId: number | null | undefined) => boolean;
    hasLiveStaff: Readonly<Ref<boolean>>;
};

export function useTicketPresence(
    ticketNumber: MaybeRefOrGetter<string>,
): TicketPresence {
    const channel = shallowRef<RealtimePresenceChannel | null>(null);
    const members = ref<PresenceMember[]>([]);
    const departedAt = ref<Record<number, number>>({});
    const memberIds = computed(
        () => new Set(members.value.map((member) => member.id)),
    );
    const isLive = (userId: number | null | undefined): boolean =>
        userId !== null && userId !== undefined && memberIds.value.has(userId);
    const hasLiveStaff = computed(() =>
        members.value.some((member) => member.role === 'staff'),
    );

    const client = realtime();

    if (!client) {
        return { channel, members, departedAt, isLive, hasLiveStaff };
    }

    let joined: string | null = null;

    const leaveCurrent = (): void => {
        if (joined === null) {
            return;
        }

        client.leaveChannel(`presence-${joined}`);
        joined = null;
        channel.value = null;
        members.value = [];
    };

    const sync = (number: string): void => {
        const name = realtimeChannels.ticketPresence(number);

        if (name === joined) {
            return;
        }

        leaveCurrent();
        joined = name;

        const presence = client.join(name);

        presence
            .here((list: unknown[]) => {
                members.value = list
                    .map(toMember)
                    .filter((member): member is PresenceMember => !!member);
            })
            .joining((value: unknown) => {
                const member = toMember(value);

                if (member && !memberIds.value.has(member.id)) {
                    members.value = [...members.value, member];
                }
            })
            .leaving((value: unknown) => {
                const member = toMember(value);

                if (!member) {
                    return;
                }

                members.value = members.value.filter(
                    (current) => current.id !== member.id,
                );
                departedAt.value = {
                    ...departedAt.value,
                    [member.id]: Date.now(),
                };
            })
            .error(() => {
                members.value = [];
            });

        channel.value = presence;
    };

    watch(() => toValue(ticketNumber), sync, { immediate: true });

    onScopeDispose(leaveCurrent);

    return { channel, members, departedAt, isLive, hasLiveStaff };
}
