export type ReplyMode = 'public' | 'internal' | 'unavailable';

export function resolveReplyMode(
    canAddInternalNote: boolean,
    internalSelected: boolean,
): ReplyMode {
    if (!internalSelected) {
        return 'public';
    }

    return canAddInternalNote ? 'internal' : 'unavailable';
}

export function needsPublicConfirmation(
    internalSelected: boolean,
    nextInternal: boolean,
    body: string,
): boolean {
    return internalSelected && !nextInternal && body.trim() !== '';
}
