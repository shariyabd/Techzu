import { CalendarDays, ShieldCheck, Tags, Timer, Users } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index as categoriesIndex } from '@/routes/admin/categories';
import { index as holidaysIndex } from '@/routes/admin/holidays';
import { edit as slaEdit } from '@/routes/admin/sla';

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
        title: 'SLA & business hours',
        href: slaEdit.url(),
        icon: Timer,
        order: 72,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageSla,
    },
    {
        title: 'Users',
        href: '/admin/users',
        icon: Users,
        order: 73,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageUsers,
    },
    {
        title: 'Roles & permissions',
        href: '/admin/roles',
        icon: ShieldCheck,
        order: 74,
        group: 'admin',
        railKey: 'admin',
        visible: (page) => page.auth.can.manageUsers,
    },
];

export default items;
