import { describe, expect, it } from 'vite-plus/test';
import { carriesFreshFlash } from '@/lib/flashToast';

describe('carriesFreshFlash', () => {
    it.each([
        ['a full visit', [], [], true],
        ['a realtime reload of other props', ['notifications'], [], false],
        [
            'a partial reload that asks for flash',
            ['tickets', 'flash'],
            [],
            true,
        ],
        ['a reload that excludes flash', [], ['flash'], false],
        ['a reload that excludes other props', [], ['tickets'], true],
    ])(
        'decides whether %s can carry a new flash message',
        (_label, only, except, expected) => {
            expect(carriesFreshFlash({ only, except })).toBe(expected);
        },
    );
});
