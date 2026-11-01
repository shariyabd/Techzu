export const notificationSoundUrl = '/sounds/new-notification.mp3';

const minimumGapMs = 1000;

let audio: HTMLAudioElement | null = null;
let lastPlayedAt = Number.NEGATIVE_INFINITY;

function player(): HTMLAudioElement | null {
    if (typeof Audio === 'undefined') {
        return null;
    }

    audio ??= new Audio(notificationSoundUrl);

    return audio;
}

export function playNotificationSound(now: number = Date.now()): void {
    if (now - lastPlayedAt < minimumGapMs) {
        return;
    }

    const sound = player();

    if (!sound) {
        return;
    }

    lastPlayedAt = now;
    sound.currentTime = 0;
    sound.play()?.catch(() => undefined);
}

export function resetNotificationSound(): void {
    audio = null;
    lastPlayedAt = Number.NEGATIVE_INFINITY;
}
