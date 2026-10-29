import type { NavItem } from '@/config/navigation';
import { ShieldCheck, Users } from '@lucide/vue';

const items: NavItem[] = [
    {
        title: 'Users',
        href: '/admin/users',
        icon: Users,
        order: 72,
        visible: (page) => page.auth.can.manageUsers,
    },
    {
        title: 'Roles & permissions',
        href: '/admin/roles',
        icon: ShieldCheck,
        order: 73,
        visible: (page) => page.auth.can.manageUsers,
    },
];

export default items;
