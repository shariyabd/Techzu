import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export type RealtimeClient = Echo<'reverb'>;
export type RealtimePrivateChannel = ReturnType<RealtimeClient['private']>;
export type RealtimePresenceChannel = ReturnType<RealtimeClient['join']>;
export type WhisperMetadata = { user_id?: string | number };

let client: RealtimeClient | null | undefined;

function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return {
            'X-XSRF-TOKEN': decodeURIComponent(
                cookie.slice('XSRF-TOKEN='.length),
            ),
        };
    }

    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

function createClient(): RealtimeClient | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (typeof window === 'undefined' || !key) {
        return null;
    }

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 443);

    try {
        return new Echo({
            broadcaster: 'reverb',
            Pusher,
            key,
            wsHost:
                import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            withoutInterceptors: true,
            channelAuthorization: {
                endpoint: '/broadcasting/auth',
                transport: 'ajax',
                headersProvider: csrfHeaders,
            },
        });
    } catch {
        return null;
    }
}

export function realtime(): RealtimeClient | null {
    if (client === undefined) {
        client = createClient();
    }

    return client;
}

export function connectedRealtime(): RealtimeClient | null {
    return client ?? null;
}

export function disconnectRealtime(): void {
    client?.disconnect();
    client = undefined;
}

export function isRealtimeChannelSubscribed(name: string): boolean {
    return client?.connector.pusher.channel(name)?.subscribed === true;
}
