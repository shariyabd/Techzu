import { describe, expect, it } from 'vite-plus/test';
import { renderSavedReply, unknownPlaceholders } from '@/lib/savedReplies';
import type { SavedReplyContext } from '@/types/saved-replies';

const context: SavedReplyContext = {
    customerName: '  Jamie   Rivera ',
    ticketNumber: 'TKT-000042',
    ticketSubject: 'Cannot sign in',
    agentName: 'Alex Morgan',
};

describe('renderSavedReply', () => {
    it('fills every known placeholder from the ticket and agent', () => {
        expect(
            renderSavedReply(
                'Hi {customer_first_name} ({customer_name}), re {ticket_number} "{ticket_subject}". {agent_first_name} / {agent_name}',
                context,
            ),
        ).toBe(
            'Hi Jamie (  Jamie   Rivera ), re TKT-000042 "Cannot sign in". Alex / Alex Morgan',
        );
    });

    it('leaves unknown placeholders untouched so the agent can spot them', () => {
        expect(renderSavedReply('Hello {customer_nmae}', context)).toBe(
            'Hello {customer_nmae}',
        );
    });

    it('never expands placeholders or replacement patterns that come from customer-supplied values', () => {
        expect(
            renderSavedReply('Re: {ticket_subject} for {customer_name}', {
                ...context,
                customerName: "$& $' Co",
                ticketSubject: '{agent_name}',
            }),
        ).toBe("Re: {agent_name} for $& $' Co");
    });
});

describe('unknownPlaceholders', () => {
    it('lists each unknown placeholder once and ignores known ones', () => {
        expect(
            unknownPlaceholders(
                '{customer_name} {order_id} {order_id} {ticket_number} {Agent_Name}',
            ),
        ).toEqual(['{order_id}', '{Agent_Name}']);
    });
});
