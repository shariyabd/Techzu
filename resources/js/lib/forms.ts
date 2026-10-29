export function errorsFor(
    errors: Partial<Record<string, string>>,
    prefix: string,
): string[] {
    return Object.entries(errors)
        .filter(
            (entry): entry is [string, string] =>
                entry[0].startsWith(prefix) && typeof entry[1] === 'string',
        )
        .map(([, message]) => message);
}
