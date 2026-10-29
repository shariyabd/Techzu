import type { TicketPriorityValue } from '@/types/tickets';

export type DashboardTotals = {
    open: number;
    unassigned: number;
    critical: number;
    breached: number;
    approaching: number;
    resolved: number;
};
export type PriorityCount = { priority: TicketPriorityValue; count: number };
export type AgentLoad = {
    agent_id: number;
    name: string;
    active: number;
    breached: number;
};
export type DashboardStats = {
    totals: DashboardTotals;
    by_priority: PriorityCount[];
    by_agent: AgentLoad[];
};
