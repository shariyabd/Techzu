import { Headset } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index } from '@/routes/agents';

const items: NavItem[] = [
    {
        title: 'Agents',
        href: index.url(),
        icon: Headset,
        order: 25,
        group: 'support',
        railKey: 'agents',
        visible: (page) => page.auth.can.viewAgents,
    },
];

export default items;
