import type { TicketPriorityValue, TicketStatusValue } from '@/types/tickets';

export type DashboardTotals = {
    open: number;
    unassigned: number;
    critical: number;
    breached: number;
    approaching: number;
    resolved: number;
};
export type SlaHealth = {
    within: number;
    approaching: number;
    breached: number;
    paused: number;
};
export type StatusCount = { status: TicketStatusValue; count: number };
export type PriorityCount = { priority: TicketPriorityValue; count: number };
export type AgentLoad = {
    agent_id: number;
    name: string;
    active: number;
    breached: number;
};
export type TrendPoint = { date: string; created: number; resolved: number };
export type DashboardStats = {
    totals: DashboardTotals;
    sla_health: SlaHealth;
    by_status: StatusCount[];
    by_priority: PriorityCount[];
    by_agent: AgentLoad[];
    trend: TrendPoint[];
};
