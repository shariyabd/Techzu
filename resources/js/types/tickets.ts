export type TicketStatusValue =
    | 'open'
    | 'in_progress'
    | 'waiting_for_customer'
    | 'resolved'
    | 'closed';
export type TicketPriorityValue = 'low' | 'medium' | 'high' | 'critical';
export type SlaStateValue =
    | 'within'
    | 'approaching'
    | 'breached'
    | 'paused'
    | 'stopped';

export type EnumValue<T extends string = string> = { value: T; label: string };
export type Option = { value: string; label: string };
export type UserSummary = { id: number; name: string };
export type CategorySummary = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
};

export type TicketCan = {
    update: boolean;
    changeStatus: boolean;
    changePriority: boolean;
    claim: boolean;
    assign: boolean;
    reply: boolean;
    addInternalNote: boolean;
    allowedStatuses: string[];
};

export type TicketSla = {
    state: EnumValue<SlaStateValue>;
    due_at: string | null;
    warning_at: string | null;
    paused_at: string | null;
};

export type TicketDetail = {
    number: string;
    subject: string;
    description: string;
    status: EnumValue<TicketStatusValue>;
    priority: EnumValue<TicketPriorityValue>;
    category: CategorySummary | null;
    customer: UserSummary | null;
    assigned_agent: UserSummary | null;
    sla: TicketSla;
    escalation_level: number;
    created_at: string | null;
    updated_at: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    can?: TicketCan;
};

export type TicketListItem = {
    number: string;
    subject: string;
    status: EnumValue<TicketStatusValue>;
    priority: EnumValue<TicketPriorityValue>;
    category: CategorySummary | null;
    customer: UserSummary | null;
    assigned_agent: UserSummary | null;
    sla: Pick<TicketSla, 'state' | 'due_at'>;
    escalation_level: number;
    replies_count?: number;
    created_at: string | null;
    updated_at: string | null;
};

export type AttachmentItem = {
    id: number;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    is_internal: boolean;
    download_url: string;
    created_at: string | null;
};

export type ReplyItem = {
    id: number;
    body: string;
    is_internal: boolean;
    author: UserSummary;
    attachments: AttachmentItem[];
    created_at: string | null;
};

export type ActivityItem = {
    id: number;
    type: string;
    description: string;
    properties: Record<string, unknown>;
    causer: UserSummary | null;
    created_at: string | null;
};

export type AgentOption = { id: number; name: string };

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLink[];
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
};

export type TicketFormOptions = {
    statuses: Option[];
    priorities: Option[];
    categories: Pick<CategorySummary, 'id' | 'name'>[];
};
