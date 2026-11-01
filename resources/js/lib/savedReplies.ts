import type { SavedReplyContext } from '@/types/saved-replies';

export type SavedReplyPlaceholder = {
    token: string;
    description: string;
    resolve: (context: SavedReplyContext) => string;
};

const firstName = (name: string): string => name.trim().split(/\s+/)[0] ?? '';

export const savedReplyPlaceholders: SavedReplyPlaceholder[] = [
    {
        token: 'customer_name',
        description: 'Customer’s full name',
        resolve: (context) => context.customerName,
    },
    {
        token: 'customer_first_name',
        description: 'Customer’s first name',
        resolve: (context) => firstName(context.customerName),
    },
    {
        token: 'ticket_number',
        description: 'Ticket number',
        resolve: (context) => context.ticketNumber,
    },
    {
        token: 'ticket_subject',
        description: 'Ticket subject',
        resolve: (context) => context.ticketSubject,
    },
    {
        token: 'agent_name',
        description: 'Your full name',
        resolve: (context) => context.agentName,
    },
    {
        token: 'agent_first_name',
        description: 'Your first name',
        resolve: (context) => firstName(context.agentName),
    },
];

const resolvers = new Map(
    savedReplyPlaceholders.map((placeholder) => [
        placeholder.token,
        placeholder.resolve,
    ]),
);

const PLACEHOLDER_PATTERN = /\{([A-Za-z0-9_]+)\}/g;

export function renderSavedReply(
    body: string,
    context: SavedReplyContext,
): string {
    return body.replace(PLACEHOLDER_PATTERN, (match, name: string) => {
        const resolve = resolvers.get(name);

        return resolve === undefined ? match : resolve(context);
    });
}

export function unknownPlaceholders(body: string): string[] {
    const unknown = new Set<string>();

    for (const [match, name] of body.matchAll(PLACEHOLDER_PATTERN)) {
        if (!resolvers.has(name)) {
            unknown.add(match);
        }
    }

    return [...unknown];
}

export const sampleSavedReplyContext: SavedReplyContext = {
    customerName: 'Jamie Rivera',
    ticketNumber: 'SAMPLE-000123',
    ticketSubject: 'Cannot sign in to my account',
    agentName: 'Alex Morgan',
};
