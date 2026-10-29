export type NotificationItem = {
    id: string;
    type: string;
    ticket_number: string | null;
    title: string;
    body: string;
    url: string | null;
    read_at: string | null;
    created_at: string | null;
};
