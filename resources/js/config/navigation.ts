import type { Component } from 'vue';
import type { SharedProps } from '@/types/shared';

export type NavGroup = 'support' | 'admin';

export type NavItem = {
    title: string;
    href: string;
    icon?: Component;
    order: number;
    group?: NavGroup;
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
