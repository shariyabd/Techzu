import type { Component } from 'vue';
import type { SharedProps } from '@/types/shared';

export type NavItem = {
    title: string;
    href: string;
    icon?: Component;
    order: number;
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
