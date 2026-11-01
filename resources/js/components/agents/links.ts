import type {
    AgentDirectoryFilters,
    AgentStats,
    AgentTab,
    DirectoryAgent,
} from '@/types/agents';

export type AgentStatKey = keyof AgentStats;

export type AgentTabTone = 'default' | 'warning' | 'danger' | 'success';

export type AgentTabDefinition = {
    key: AgentTab;
    stat: AgentStatKey;
    label: string;
    tone: AgentTabTone;
};

export const DEFAULT_AGENT_TAB: AgentTab = 'active';

export function agentTabs(resolvedWindowDays: number): AgentTabDefinition[] {
    return [
        { key: 'active', stat: 'active', label: 'Active', tone: 'default' },
        {
            key: 'unresponded',
            stat: 'unresponded',
            label: 'Unresponded',
            tone: 'warning',
        },
        {
            key: 'awaiting',
            stat: 'awaiting_reply',
            label: 'Awaiting reply',
            tone: 'warning',
        },
        {
            key: 'breached',
            stat: 'breached',
            label: 'SLA breached',
            tone: 'danger',
        },
        {
            key: 'resolved',
            stat: 'resolved_recent',
            label: `Resolved (${resolvedWindowDays} d)`,
            tone: 'success',
        },
        {
            key: 'all',
            stat: 'total_assigned',
            label: 'All assigned',
            tone: 'default',
        },
    ];
}

export function agentListQuery(
    filters: AgentDirectoryFilters,
    listPages = 1,
): Record<string, string> {
    const entries: [string, string][] = [
        ['q', (filters.q ?? '').trim()],
        ['role', filters.role === 'agent' ? '' : filters.role],
        ['availability', filters.availability ?? ''],
        [
            'category_id',
            filters.category_id === null ? '' : String(filters.category_id),
        ],
        ['sort', filters.sort === 'name' ? '' : filters.sort],
        ['list_pages', listPages > 1 ? String(listPages) : ''],
    ];

    return Object.fromEntries(entries.filter(([, value]) => value !== ''));
}

export function agentQueueTone(stats: AgentStats): 'danger' | 'warning' | null {
    if (stats.breached > 0) {
        return 'danger';
    }

    return stats.unresponded > 0 ? 'warning' : null;
}

export function agentQueueSummary(agent: DirectoryAgent): string {
    const { active, total_assigned, unresponded, breached } = agent.stats;
    const parts = [`${active} active of ${total_assigned} assigned`];

    if (unresponded > 0) {
        parts.push(`${unresponded} unresponded`);
    }

    if (breached > 0) {
        parts.push(`${breached} breached`);
    }

    return parts.join(' · ');
}
