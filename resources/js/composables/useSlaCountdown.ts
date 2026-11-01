import type { ComputedRef, MaybeRefOrGetter, Ref } from 'vue';
import {
    computed,
    getCurrentScope,
    onScopeDispose,
    readonly,
    ref,
    toValue,
    watch,
} from 'vue';
import type { SlaClock } from '@/types/tickets';

export type SlaCountdownState = {
    seconds: number;
    running: boolean;
    resumesAt: string | null;
};

const TICK_MS = 1000;

const ticker = ref(Date.now());
let tickerUsers = 0;
let tickerTimer: ReturnType<typeof setInterval> | undefined;

export function useSlaTicker(): Readonly<Ref<number>> {
    if (typeof window !== 'undefined' && getCurrentScope()) {
        tickerUsers += 1;
        ticker.value = Date.now();
        tickerTimer ??= setInterval(() => {
            ticker.value = Date.now();
        }, TICK_MS);

        onScopeDispose(() => {
            tickerUsers -= 1;

            if (tickerUsers <= 0) {
                clearInterval(tickerTimer);
                tickerTimer = undefined;
                tickerUsers = 0;
            }
        });
    }

    return readonly(ticker);
}

const elapsedSeconds = (from: number, to: number): number =>
    Math.max(0, Math.floor((to - from) / 1000));

const instant = (iso: string | null): number =>
    iso ? Date.parse(iso) : Number.NaN;

export function slaCountdownAt(
    clock: SlaClock,
    receivedAt: number,
    now: number,
): SlaCountdownState {
    const remaining = clock.remaining_seconds;

    if (clock.running) {
        const stopsAt = instant(clock.changes_at);

        if (Number.isNaN(stopsAt) || now < stopsAt) {
            return {
                seconds: remaining - elapsedSeconds(receivedAt, now),
                running: true,
                resumesAt: null,
            };
        }

        return {
            seconds: remaining - elapsedSeconds(receivedAt, stopsAt),
            running: false,
            resumesAt: null,
        };
    }

    const resumesAt = instant(clock.resumes_at);

    if (!Number.isNaN(resumesAt) && now >= resumesAt) {
        return {
            seconds: remaining - elapsedSeconds(resumesAt, now),
            running: true,
            resumesAt: null,
        };
    }

    return { seconds: remaining, running: false, resumesAt: clock.resumes_at };
}

export function useSlaCountdown(
    clock: MaybeRefOrGetter<SlaClock | null | undefined>,
): ComputedRef<SlaCountdownState | null> {
    const now = useSlaTicker();
    const receivedAt = ref(Date.now());

    watch(
        () => toValue(clock),
        () => {
            receivedAt.value = Date.now();
        },
    );

    return computed(() => {
        const current = toValue(clock);

        return current
            ? slaCountdownAt(
                  current,
                  receivedAt.value,
                  Math.max(now.value, receivedAt.value),
              )
            : null;
    });
}
