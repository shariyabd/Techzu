import { Bell } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index } from '@/routes/notifications';

const items: NavItem[] = [
    {
        title: 'Notifications',
        href: index.url(),
        icon: Bell,
        order: 30,
        group: 'support',
        visible: () => true,
    },
];

export default items;
