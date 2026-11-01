import { describe, expect, it } from 'vite-plus/test';

const sources = import.meta.glob<string>(
    ['@/**/*.vue', '!@/components/ui/**'],
    { query: '?raw', import: 'default', eager: true },
);

function staleToggleBindings(source: string): string[] {
    const tags =
        source.match(/<(Checkbox|Switch)\b[\s\S]*?(?:\/>|<\/\1>)/g) ?? [];

    return tags.filter((tag) =>
        /(?:^|\s)(?::checked|v-model:checked|@update:checked|v-on:update:checked)\s*=/.test(
            tag,
        ),
    );
}

describe('Checkbox and Switch bindings', () => {
    it('bind state through modelValue so stored values render checked', () => {
        const offenders = Object.entries(sources).flatMap(([file, source]) =>
            staleToggleBindings(source).map(
                (tag) => `${file}: ${tag.replace(/\s+/g, ' ')}`,
            ),
        );

        expect(Object.keys(sources).length).toBeGreaterThan(50);
        expect(offenders).toEqual([]);
    });
});
