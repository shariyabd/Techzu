export type SavedReplyScope = 'mine' | 'team';

export type SavedReply = {
    id: number;
    title: string;
    body: string;
    scope: SavedReplyScope;
    category: { id: number; name: string } | null;
    category_id: number | null;
    can: { update: boolean; delete: boolean };
    created_at: string | null;
    updated_at: string | null;
};

export type SavedReplyFilters = {
    q: string | null;
    scope: SavedReplyScope | null;
};

export type SavedReplyCategoryOption = { id: number; name: string };

export type SavedReplyContext = {
    customerName: string;
    ticketNumber: string;
    ticketSubject: string;
    agentName: string;
};
