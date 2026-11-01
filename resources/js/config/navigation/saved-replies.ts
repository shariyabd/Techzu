import { MessageSquareText } from '@lucide/vue';
import type { NavItem } from '@/config/navigation';
import { index } from '@/routes/saved-replies';

const items: NavItem[] = [
    {
        title: 'Saved replies',
        href: index.url(),
        icon: MessageSquareText,
        order: 25,
        group: 'support',
        visible: (page) => page.auth.can.useSavedReplies,
    },
];

export default items;
