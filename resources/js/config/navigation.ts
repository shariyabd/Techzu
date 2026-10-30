import { FileUp, LayoutGrid, Settings, Ticket } from '@lucide/vue';
import type { Component } from 'vue';
import type { SharedProps } from '@/types/shared';

export type NavGroup = 'support' | 'admin';

export type RailKey = 'tickets' | 'dashboard' | 'imports' | 'admin';

export type NavItem = {
    title: string;
    href: string;
    icon?: Component;
    order: number;
    group?: NavGroup;
    railKey?: RailKey;
    visible: (page: SharedProps) => boolean;
};

const modules = import.meta.glob<{ default: NavItem[] }>('./navigation/*.ts', {
    eager: true,
});

export const navItems: NavItem[] = Object.values(modules)
    .flatMap((module) => module.default)
    .sort((a, b) => a.order - b.order || a.title.localeCompare(b.title));

export function navigationFor(props: SharedProps): NavItem[] {
    return navItems.filter((item) => item.visible(props));
}

const groupLabels: Record<NavGroup, string> = {
    support: 'Support',
    admin: 'Administration',
};

export type NavSection = { group: NavGroup; label: string; items: NavItem[] };

export function groupedNavigationFor(props: SharedProps): NavSection[] {
    const visible = navigationFor(props);

    return (Object.keys(groupLabels) as NavGroup[])
        .map((group) => ({
            group,
            label: groupLabels[group],
            items: visible.filter(
                (item) => (item.group ?? 'support') === group,
            ),
        }))
        .filter((section) => section.items.length > 0);
}

const railDefinitions: { key: RailKey; label: string; icon: Component }[] = [
    { key: 'tickets', label: 'Tickets', icon: Ticket },
    { key: 'dashboard', label: 'Dashboard', icon: LayoutGrid },
    { key: 'imports', label: 'Imports', icon: FileUp },
    { key: 'admin', label: 'Admin', icon: Settings },
];

export type RailEntry = {
    key: RailKey;
    label: string;
    icon: Component;
    href: string;
    hrefs: string[];
};

export function railFor(props: SharedProps): RailEntry[] {
    const visible = navigationFor(props);

    return railDefinitions.flatMap((definition) => {
        const items = visible.filter((item) => item.railKey === definition.key);

        if (items.length === 0) {
            return [];
        }

        return [
            {
                key: definition.key,
                label: definition.label,
                icon: definition.icon,
                href: items[0].href,
                hrefs: items.map((item) => item.href),
            },
        ];
    });
}

export function canSearchTickets(props: SharedProps): boolean {
    return navigationFor(props).some((item) => item.railKey === 'tickets');
}
