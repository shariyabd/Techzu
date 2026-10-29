import type { NavItem } from '@/config/navigation';
import { Ticket } from '@lucide/vue';

const items: NavItem[] = [
    {
        title: 'Tickets',
        href: '/tickets',
        icon: Ticket,
        order: 20,
        visible: () => true,
    },
];

export default items;
