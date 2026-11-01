import { router } from '@inertiajs/vue3';
import type { MaybeRefOrGetter } from 'vue';
import { onScopeDispose, toValue, watch } from 'vue';
import { connectedRealtime, realtime } from '@/lib/echo';
import type { RealtimePrivateChannel } from '@/lib/echo';

export type RealtimeKind =
    | 'created'
    | 'updated'
    | 'replied'
    | 'internal_note'
    | 'status_changed'
    | 'priority_changed'
    | 'assigned'
    | 'unassigned'
    | 'sla_approaching'
    | 'sla_breached'
    | 'import_finished'
    | 'notification';

export type RealtimePayload = {
    ticket: string | null;
    kind: RealtimeKind;
    at: string;
};

export type RealtimeEvent =
    | 'ticket.changed'
    | 'ticket.internal-note'
    | 'queue.changed'
    | 'notification.received';

export type RealtimeHandler = (payload: RealtimePayload) => void;

type ReloadOptions = Parameters<typeof router.reload>[0];

export const realtimeChannels = {
    ticket: (number: string): string => `tickets.${number}`,
    ticketStaff: (number: string): string => `tickets.${number}.staff`,
    ticketPresence: (number: string): string => `presence.tickets.${number}`,
    queue: (): string => 'tickets.queue',
    user: (id: number): string => `App.Models.User.${id}`,
};

const subscribers = new Map<string, number>();

export function acquirePrivateChannel(
    channel: string,
): RealtimePrivateChannel | null {
    const client = realtime();

    if (!client) {
        return null;
    }

    subscribers.set(channel, (subscribers.get(channel) ?? 0) + 1);

    return client.private(channel);
}

export function releasePrivateChannel(channel: string): void {
    if (!subscribers.has(channel)) {
        return;
    }

    const remaining = (subscribers.get(channel) ?? 1) - 1;

    if (remaining > 0) {
        subscribers.set(channel, remaining);

        return;
    }

    subscribers.delete(channel);
    connectedRealtime()?.leaveChannel(`private-${channel}`);
}

function join(
    channel: string,
    events: readonly RealtimeEvent[],
    handler: RealtimeHandler,
): void {
    const subscription = acquirePrivateChannel(channel);
    events.forEach((event) => subscription?.listen(`.${event}`, handler));
}

function leave(
    channel: string,
    events: readonly RealtimeEvent[],
    handler: RealtimeHandler,
): void {
    if (!subscribers.has(channel)) {
        return;
    }

    const subscription = connectedRealtime()?.private(channel);
    events.forEach((event) =>
        subscription?.stopListening(`.${event}`, handler),
    );
    releasePrivateChannel(channel);
}

export function useRealtime(
    channels: MaybeRefOrGetter<string[]>,
    events: readonly RealtimeEvent[],
    handler: RealtimeHandler,
): void {
    if (!realtime()) {
        return;
    }

    let joined: string[] = [];

    const sync = (next: string[]): void => {
        const wanted = [...new Set(next)];
        joined
            .filter((channel) => !wanted.includes(channel))
            .forEach((channel) => leave(channel, events, handler));
        wanted
            .filter((channel) => !joined.includes(channel))
            .forEach((channel) => join(channel, events, handler));
        joined = wanted;
    };

    watch(() => toValue(channels), sync, { immediate: true });

    onScopeDispose(() => sync([]));
}

export function useRealtimeReload(
    channels: MaybeRefOrGetter<string[]>,
    events: readonly RealtimeEvent[],
    options: () => ReloadOptions,
    wait = 400,
): void {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight = false;
    let pending = false;
    let disposed = false;

    const schedule = (): void => {
        if (disposed || timer !== undefined) {
            return;
        }

        timer = setTimeout(run, wait);
    };

    const settle = (): void => {
        inFlight = false;

        if (pending) {
            pending = false;
            schedule();
        }
    };

    function run(): void {
        timer = undefined;

        if (disposed) {
            return;
        }

        if (inFlight) {
            pending = true;

            return;
        }

        inFlight = true;
        pending = false;
        const visit = options() ?? {};

        router.reload({
            ...visit,
            onFinish: (event) => {
                visit.onFinish?.(event);
                settle();
            },
        });
    }

    useRealtime(channels, events, () => {
        if (inFlight) {
            pending = true;

            return;
        }

        schedule();
    });

    onScopeDispose(() => {
        disposed = true;
        clearTimeout(timer);
        timer = undefined;
    });
}
