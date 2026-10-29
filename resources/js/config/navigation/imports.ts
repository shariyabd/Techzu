import { FileUp } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index } from '@/routes/admin/imports';

const items: NavItem[] = [
    {
        title: 'Imports',
        href: index.url(),
        icon: FileUp,
        order: 80,
        group: 'admin',
        visible: (page) => page.auth.can.manageImports,
    },
];

export default items;
