import { effectScope, ref, shallowRef } from 'vue';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { PresenceMember } from '@/composables/usePresence';
import { useTyping } from '@/composables/useTyping';
import type {
    RealtimePresenceChannel,
    RealtimePrivateChannel,
} from '@/lib/echo';

type WhisperListener = (payload: unknown, metadata?: object) => void;

type FakeChannel = {
    name: string;
    whisper: ReturnType<typeof vi.fn>;
    listeners: Map<string, WhisperListener>;
    receive: (event: string, payload: unknown, metadata?: object) => void;
    listenForWhisper: (event: string, listener: WhisperListener) => FakeChannel;
    stopListeningForWhisper: (event: string) => FakeChannel;
};

const realtimeState = vi.hoisted(() => ({
    subscribed: new Set<string>(),
    staff: null as unknown,
}));

vi.mock('@/lib/echo', () => ({
    realtime: () => ({}),
    isRealtimeChannelSubscribed: (name: string) =>
        realtimeState.subscribed.has(name),
}));

vi.mock('@/composables/useRealtime', () => ({
    acquirePrivateChannel: () => realtimeState.staff,
    releasePrivateChannel: () => undefined,
}));

function fakeChannel(name: string): FakeChannel {
    const channel: FakeChannel = {
        name,
        whisper: vi.fn(),
        listeners: new Map(),
        receive: (event, payload, metadata) =>
            channel.listeners.get(event)?.(payload, metadata),
        listenForWhisper: (event, listener) => {
            channel.listeners.set(event, listener);

            return channel;
        },
        stopListeningForWhisper: (event) => {
            channel.listeners.delete(event);

            return channel;
        },
    };

    return channel;
}

const STAFF_CHANNEL = 'tickets.TKT-1.staff';

let presence: FakeChannel;
let staff: FakeChannel;
let scope: ReturnType<typeof effectScope>;

function startTyping() {
    scope = effectScope();

    return scope.run(() =>
        useTyping({
            presence: shallowRef(
                presence as unknown as RealtimePresenceChannel,
            ),
            members: ref<PresenceMember[]>([]),
            staffChannel: STAFF_CHANNEL,
            self: { id: 7, name: 'Alex Morgan', role: 'staff' },
        }),
    )!;
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(Date.parse('2026-11-01T12:00:00Z'));
    presence = fakeChannel('presence-tickets.TKT-1');
    staff = fakeChannel(`private-${STAFF_CHANNEL}`);
    realtimeState.staff = staff as unknown as RealtimePrivateChannel;
    realtimeState.subscribed = new Set([presence.name, staff.name]);
});

afterEach(() => {
    scope.stop();
    vi.useRealTimers();
});

describe('useTyping', () => {
    it('sends internal-note typing only to the staff channel, never to the presence channel the customer is on', () => {
        const typing = startTyping();

        typing.notify('internal');
        typing.stop('internal');

        expect(staff.whisper.mock.calls.map(([event]) => event)).toEqual([
            'typing',
            'typing-stopped',
        ]);
        expect(presence.whisper).not.toHaveBeenCalled();
    });

    it('drops internal-note typing instead of falling back to the presence channel when the staff channel is not subscribed', () => {
        realtimeState.subscribed.delete(staff.name);
        const typing = startTyping();

        typing.notify('internal');

        expect(presence.whisper).not.toHaveBeenCalled();
        expect(staff.whisper).not.toHaveBeenCalled();
    });

    it('sends public typing only to the presence channel', () => {
        const typing = startTyping();

        typing.notify('public');

        expect(presence.whisper).toHaveBeenCalledWith('typing', {
            id: 7,
            name: 'Alex Morgan',
            role: 'staff',
        });
        expect(staff.whisper).not.toHaveBeenCalled();
    });

    it('throttles typing whispers to one every two seconds per mode', () => {
        const typing = startTyping();

        typing.notify('public');
        vi.advanceTimersByTime(1999);
        typing.notify('public');
        typing.notify('internal');

        expect(presence.whisper).toHaveBeenCalledTimes(1);
        expect(staff.whisper).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(1);
        typing.notify('public');

        expect(presence.whisper).toHaveBeenCalledTimes(2);
    });

    it('tells apart staff internal-note typers who share a name, and ignores only the viewer by id', () => {
        const typing = startTyping();

        staff.receive('typing', { id: 11, name: 'Alex Morgan', role: 'staff' });
        staff.receive('typing', { id: 12, name: 'Alex Morgan', role: 'staff' });
        staff.receive('typing', { id: 7, name: 'Someone Else', role: 'staff' });

        expect(typing.typers.value.map((typer) => typer.userId)).toEqual([
            11, 12,
        ]);

        staff.receive('typing-stopped', {
            id: 11,
            name: 'Alex Morgan',
            role: 'staff',
        });

        expect(typing.typers.value.map((typer) => typer.userId)).toEqual([12]);
    });

    it('clears an internal-note typer by id when their reply arrives, even after a rename', () => {
        const typing = startTyping();

        staff.receive('typing', { id: 11, name: 'Old Name', role: 'staff' });
        typing.clearAuthor(11);

        expect(typing.typers.value).toEqual([]);
    });
});
