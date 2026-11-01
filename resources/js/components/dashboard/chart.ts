import type { InertiaLinkProps } from '@inertiajs/vue3';

export type ChartHref = NonNullable<InertiaLinkProps['href']>;

export type ChartSegment = {
    key: string;
    label: string;
    value: number;
    href?: ChartHref;
    strokeClass: string;
    fillClass: string;
};

export function percentOf(value: number, total: number): number {
    return total === 0 ? 0 : Math.round((value / total) * 100);
}

export function niceCeiling(value: number): number {
    if (value <= 4) {
        return 4;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 5, 10].find(
        (candidate) => candidate * magnitude >= value,
    );

    return (step ?? 10) * magnitude;
}
