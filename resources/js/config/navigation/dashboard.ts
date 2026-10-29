import { LayoutGrid } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { dashboard } from '@/routes';

const items: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard.url(),
        icon: LayoutGrid,
        order: 10,
        group: 'support',
        visible: (page) => page.auth.can.viewDashboard,
    },
];

export default items;
