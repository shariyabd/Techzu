import type { EnumValue, UserSummary } from '@/types/tickets';

export type ImportStatusValue =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed';
export type TicketImportItem = {
    id: number;
    original_name: string;
    status: EnumValue<ImportStatusValue>;
    is_finished: boolean;
    total_rows: number;
    processed_rows: number;
    succeeded_rows: number;
    failed_rows: number;
    progress_percent: number;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
    user?: UserSummary | null;
};
export type TicketImportErrorItem = {
    id: number;
    row_number: number;
    errors: Record<string, string[]>;
    raw: Record<string, string | null>;
    created_at: string | null;
};
export type ImportColumns = { required: string[]; optional: string[] };
