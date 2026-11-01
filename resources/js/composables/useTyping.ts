import type { MaybeRefOrGetter, Ref, ShallowRef } from 'vue';
import { computed, onScopeDispose, ref, toValue, watch } from 'vue';
import type { PresenceMember, PresenceRole } from '@/composables/usePresence';
import {
    acquirePrivateChannel,
    releasePrivateChannel,
} from '@/composables/useRealtime';
import { isRealtimeChannelSubscribed, realtime } from '@/lib/echo';
import type {
    RealtimePresenceChannel,
    RealtimePrivateChannel,
    WhisperMetadata,
} from '@/lib/echo';

export type TypingMode = 'public' | 'internal';

export type Typer = {
    key: string;
    userId: number;
    name: string;
    role: PresenceRole;
    mode: TypingMode;
};

type TypingSelf = { id: number; name: string; role: PresenceRole };

type TypingOptions = {
    presence: ShallowRef<RealtimePresenceChannel | null>;
    members: Ref<PresenceMember[]>;
    staffChannel: MaybeRefOrGetter<string | null>;
    self: MaybeRefOrGetter<TypingSelf>;
};

const TYPING_EVENT = 'typing';
const TYPING_STOPPED_EVENT = 'typing-stopped';
const SEND_THROTTLE_MS = 2000;
const DISPLAY_TTL_MS = 4000;
const MAX_NAME_LENGTH = 120;

type WhisperHandler = (payload: unknown, metadata?: WhisperMetadata) => void;

type TypingPayload = { id: number; name: string; role: PresenceRole };

function toUserId(value: unknown): number | null {
    const id = Number(value);

    return Number.isInteger(id) && id > 0 ? id : null;
}

function parsePayload(
    payload: unknown,
    metadata: WhisperMetadata | undefined,
): TypingPayload | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const candidate = payload as Record<string, unknown>;
    const id = toUserId(metadata?.user_id) ?? toUserId(candidate.id);

    if (
        id === null ||
        typeof candidate.name !== 'string' ||
        candidate.name.trim() === '' ||
        (candidate.role !== 'customer' && candidate.role !== 'staff')
    ) {
        return null;
    }

    return {
        id,
        name: candidate.name.slice(0, MAX_NAME_LENGTH),
        role: candidate.role,
    };
}

export function useTyping(options: TypingOptions) {
    const active = ref<Typer[]>([]);
    const expiry = new Map<string, ReturnType<typeof setTimeout>>();
    const lastSent: Record<TypingMode, number> = { public: 0, internal: 0 };

    const typers = computed(() => active.value);

    function remove(key: string): void {
        clearTimeout(expiry.get(key));
        expiry.delete(key);
        active.value = active.value.filter((typer) => typer.key !== key);
    }

    function upsert(typer: Typer): void {
        clearTimeout(expiry.get(typer.key));
        expiry.set(
            typer.key,
            setTimeout(() => remove(typer.key), DISPLAY_TTL_MS),
        );

        if (active.value.some((current) => current.key === typer.key)) {
            return;
        }

        active.value = [...active.value, typer];
    }

    function publicTyper(metadata: WhisperMetadata | undefined): Typer | null {
        const userId = toUserId(metadata?.user_id);

        if (userId === null || userId === toValue(options.self).id) {
            return null;
        }

        const member = options.members.value.find(
            (current) => current.id === userId,
        );

        return member
            ? {
                  key: `public:${member.id}`,
                  userId: member.id,
                  name: member.name,
                  role: member.role,
                  mode: 'public',
              }
            : null;
    }

    function internalTyper(
        payload: unknown,
        metadata: WhisperMetadata | undefined,
    ): Typer | null {
        const parsed = parsePayload(payload, metadata);

        if (!parsed || parsed.id === toValue(options.self).id) {
            return null;
        }

        return {
            key: `internal:${parsed.id}`,
            userId: parsed.id,
            name: parsed.name,
            role: 'staff',
            mode: 'internal',
        };
    }

    const onPublicTyping: WhisperHandler = (_payload, metadata) => {
        const typer = publicTyper(metadata);

        if (typer) {
            upsert(typer);
        }
    };

    const onPublicStopped: WhisperHandler = (_payload, metadata) => {
        const typer = publicTyper(metadata);

        if (typer) {
            remove(typer.key);
        }
    };

    const onInternalTyping: WhisperHandler = (payload, metadata) => {
        const typer = internalTyper(payload, metadata);

        if (typer) {
            upsert(typer);
        }
    };

    const onInternalStopped: WhisperHandler = (payload, metadata) => {
        const typer = internalTyper(payload, metadata);

        if (typer) {
            remove(typer.key);
        }
    };

    watch(
        options.presence,
        (channel, previous) => {
            previous
                ?.stopListeningForWhisper(TYPING_EVENT, onPublicTyping)
                .stopListeningForWhisper(TYPING_STOPPED_EVENT, onPublicStopped);
            active.value
                .filter((typer) => typer.mode === 'public')
                .forEach((typer) => remove(typer.key));
            channel
                ?.listenForWhisper(TYPING_EVENT, onPublicTyping)
                .listenForWhisper(TYPING_STOPPED_EVENT, onPublicStopped);
        },
        { immediate: true },
    );

    watch(
        () => options.members.value.map((member) => member.id),
        (ids) => {
            active.value
                .filter(
                    (typer) =>
                        typer.mode === 'public' && !ids.includes(typer.userId),
                )
                .forEach((typer) => remove(typer.key));
        },
    );

    let staffName: string | null = null;
    let staffSubscription: RealtimePrivateChannel | null = null;

    const releaseStaff = (): void => {
        if (staffName === null) {
            return;
        }

        staffSubscription
            ?.stopListeningForWhisper(TYPING_EVENT, onInternalTyping)
            .stopListeningForWhisper(TYPING_STOPPED_EVENT, onInternalStopped);
        releasePrivateChannel(staffName);
        active.value
            .filter((typer) => typer.mode === 'internal')
            .forEach((typer) => remove(typer.key));
        staffName = null;
        staffSubscription = null;
    };

    if (realtime()) {
        watch(
            () => toValue(options.staffChannel),
            (name) => {
                if (name === staffName) {
                    return;
                }

                releaseStaff();

                if (name === null) {
                    return;
                }

                staffName = name;
                staffSubscription = acquirePrivateChannel(name);
                staffSubscription
                    ?.listenForWhisper(TYPING_EVENT, onInternalTyping)
                    .listenForWhisper(TYPING_STOPPED_EVENT, onInternalStopped);
            },
            { immediate: true },
        );
    }

    function whisperTarget(
        mode: TypingMode,
    ): RealtimePrivateChannel | RealtimePresenceChannel | null {
        if (mode === 'internal') {
            return staffName !== null &&
                isRealtimeChannelSubscribed(`private-${staffName}`)
                ? staffSubscription
                : null;
        }

        const presence = options.presence.value;

        return presence && isRealtimeChannelSubscribed(presence.name)
            ? presence
            : null;
    }

    function payload(): TypingPayload {
        const self = toValue(options.self);

        return { id: self.id, name: self.name, role: self.role };
    }

    function notify(mode: TypingMode): void {
        const now = Date.now();

        if (now - lastSent[mode] < SEND_THROTTLE_MS) {
            return;
        }

        const target = whisperTarget(mode);

        if (!target) {
            return;
        }

        target.whisper(TYPING_EVENT, payload());
        lastSent[mode] = now;
    }

    function stop(mode: TypingMode): void {
        if (Date.now() - lastSent[mode] >= DISPLAY_TTL_MS) {
            lastSent[mode] = 0;

            return;
        }

        lastSent[mode] = 0;
        whisperTarget(mode)?.whisper(TYPING_STOPPED_EVENT, payload());
    }

    function clearAuthor(userId: number): void {
        active.value
            .filter((typer) => typer.userId === userId)
            .forEach((typer) => remove(typer.key));
    }

    onScopeDispose(() => {
        stop('public');
        stop('internal');
        releaseStaff();
        expiry.forEach((timer) => clearTimeout(timer));
        expiry.clear();
    });

    return { typers, notify, stop, clearAuthor };
}
