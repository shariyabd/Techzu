import { CalendarDays, ShieldCheck, Tags, Users } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index as categoriesIndex } from '@/routes/admin/categories';
import { index as holidaysIndex } from '@/routes/admin/holidays';

const items: NavItem[] = [
    {
        title: 'Categories',
        href: categoriesIndex.url(),
        icon: Tags,
        order: 70,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageCategories,
    },
    {
        title: 'Holidays',
        href: holidaysIndex.url(),
        icon: CalendarDays,
        order: 71,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageHolidays,
    },
    {
        title: 'Users',
        href: '/admin/users',
        icon: Users,
        order: 72,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageUsers,
    },
    {
        title: 'Roles & permissions',
        href: '/admin/roles',
        icon: ShieldCheck,
        order: 73,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageUsers,
    },
];

export default items;
