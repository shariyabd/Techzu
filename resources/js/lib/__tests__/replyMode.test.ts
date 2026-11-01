import { describe, expect, it } from 'vite-plus/test';
import { needsPublicConfirmation, resolveReplyMode } from '@/lib/replyMode';

describe('resolveReplyMode', () => {
    it.each([
        [true, true, 'internal'],
        [true, false, 'public'],
        [false, false, 'public'],
        [false, true, 'unavailable'],
    ] as const)(
        'never turns a selected internal note into a public reply when staff access is lost (can note: %s, internal: %s)',
        (canAddInternalNote, internalSelected, expected) => {
            expect(resolveReplyMode(canAddInternalNote, internalSelected)).toBe(
                expected,
            );
        },
    );
});

describe('needsPublicConfirmation', () => {
    it.each([
        [true, false, 'Customer must not see this', true],
        [true, false, '   ', false],
        [false, true, 'Draft', false],
        [true, true, 'Draft', false],
    ] as const)(
        'asks before an internal draft becomes public (internal: %s, next internal: %s, body: %j)',
        (internalSelected, nextInternal, body, expected) => {
            expect(
                needsPublicConfirmation(internalSelected, nextInternal, body),
            ).toBe(expected);
        },
    );
});
