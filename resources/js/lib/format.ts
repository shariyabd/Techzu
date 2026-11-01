const dateTimeFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const relativeFormat = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});

const relativeUnits: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

export function formatDateTime(iso: string | null | undefined): string {
    return iso ? dateTimeFormat.format(new Date(iso)) : '—';
}

export function formatDate(iso: string | null | undefined): string {
    return iso ? dateFormat.format(new Date(iso)) : '—';
}

const businessFormats = new Map<string, Intl.DateTimeFormat>();

function businessFormat(
    timeZone: string,
    locale: string | undefined,
): Intl.DateTimeFormat {
    const key = `${locale ?? ''}|${timeZone}`;
    let format = businessFormats.get(key);

    if (!format) {
        const options: Intl.DateTimeFormatOptions = {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            timeZoneName: 'short',
        };

        try {
            format = new Intl.DateTimeFormat(locale, { ...options, timeZone });
        } catch {
            format = new Intl.DateTimeFormat(locale, options);
        }

        businessFormats.set(key, format);
    }

    return format;
}

export function formatBusinessDateTime(
    iso: string | null | undefined,
    timeZone: string,
    locale?: string,
): string {
    return iso ? businessFormat(timeZone, locale).format(new Date(iso)) : '—';
}

export function formatRelative(
    iso: string | null | undefined,
    now: number = Date.now(),
): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    for (const [unit, size] of relativeUnits) {
        if (Math.abs(seconds) >= size) {
            return relativeFormat.format(Math.round(seconds / size), unit);
        }
    }

    return relativeFormat.format(seconds, 'second');
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const pad = (value: number): string => String(value).padStart(2, '0');

export function formatBusinessDuration(
    seconds: number,
    withSeconds = false,
): string {
    const total = Math.abs(Math.trunc(seconds));
    const days = Math.floor(total / 86_400);
    const hours = Math.floor((total % 86_400) / 3_600);
    const minutes = Math.floor((total % 3_600) / 60);
    const rest = total % 60;

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return withSeconds
            ? `${hours}h ${pad(minutes)}m ${pad(rest)}s`
            : `${hours}h ${pad(minutes)}m`;
    }

    if (withSeconds) {
        return minutes > 0 ? `${minutes}m ${pad(rest)}s` : `${rest}s`;
    }

    if (minutes === 0 && rest > 0) {
        return '<1m';
    }

    return `${minutes}m`;
}

export function formatSlaRemaining(
    seconds: number,
    withSeconds = false,
): string {
    const duration = formatBusinessDuration(seconds, withSeconds);

    return seconds < 0 ? `Overdue by ${duration}` : `${duration} left`;
}
