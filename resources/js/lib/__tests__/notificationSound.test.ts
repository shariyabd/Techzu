import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    notificationSoundUrl,
    playNotificationSound,
    resetNotificationSound,
} from '@/lib/notificationSound';

const play = vi.fn<() => Promise<void>>();
const sources: string[] = [];

class FakeAudio {
    currentTime = 0;

    constructor(source: string) {
        sources.push(source);
    }

    play(): Promise<void> {
        return play();
    }
}

describe('playNotificationSound', () => {
    beforeEach(() => {
        resetNotificationSound();
        play.mockReset().mockResolvedValue(undefined);
        sources.length = 0;
        vi.stubGlobal('Audio', FakeAudio);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('chimes once for a burst of notifications and again after a pause', () => {
        playNotificationSound(10_000);
        playNotificationSound(10_400);
        playNotificationSound(10_900);
        playNotificationSound(11_200);

        expect(play).toHaveBeenCalledTimes(2);
        expect(sources).toEqual([notificationSoundUrl]);
    });

    it('stays silent without an error when the browser blocks autoplay', async () => {
        play.mockRejectedValue(new DOMException('blocked', 'NotAllowedError'));

        expect(() => playNotificationSound(10_000)).not.toThrow();
        await Promise.resolve();
        expect(play).toHaveBeenCalledOnce();
    });
});
