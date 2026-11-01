import type { Paginated } from '@/types/pagination';

export type AgentRoleFilter = 'agent' | 'admin' | 'all';

export type AgentAvailabilityFilter = 'available' | 'unavailable';

export type AgentSort =
    | 'name'
    | '-name'
    | '-active'
    | '-unresponded'
    | '-breached';

export type AgentTab =
    | 'active'
    | 'unresponded'
    | 'awaiting'
    | 'breached'
    | 'resolved'
    | 'all';

export type AgentStats = {
    total_assigned: number;
    active: number;
    unresponded: number;
    awaiting_reply: number;
    breached: number;
    resolved_recent: number;
};

export type DirectoryAgent = {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    role: 'agent' | 'admin' | 'customer' | null;
    is_available: boolean;
    last_seen_at: string | null;
    categories?: { id: number; name: string }[];
    stats: AgentStats;
};

export type AgentDirectoryList = Paginated<DirectoryAgent> & {
    has_more: boolean;
};

export type AgentDirectorySummary = {
    agents: number;
    available: number;
    unresponded: number;
    breached: number;
};

export type AgentDirectoryFilters = {
    q: string | null;
    role: AgentRoleFilter;
    availability: AgentAvailabilityFilter | null;
    category_id: number | null;
    sort: AgentSort;
};

export type AgentOption<T extends string> = { value: T; label: string };

export type AgentDirectoryOptions = {
    roles: AgentOption<AgentRoleFilter>[];
    availability: AgentOption<AgentAvailabilityFilter>[];
    sorts: AgentOption<AgentSort>[];
    categories: { id: number; name: string; is_active: boolean }[];
};
