import { Ticket } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index } from '@/routes/tickets';

const items: NavItem[] = [
    {
        title: 'Tickets',
        href: index.url(),
        icon: Ticket,
        order: 20,
        group: 'support',
        railKey: 'tickets',
        visible: () => true,
    },
];

export default items;
