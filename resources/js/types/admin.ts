export type AdminCategory = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    tickets_count: number;
};
export type HolidayItem = {
    id: number;
    date: string;
    weekday: string;
    name: string;
};
export type SlaPriority = 'low' | 'medium' | 'high' | 'critical';
export type SlaWeekday =
    | 'sunday'
    | 'monday'
    | 'tuesday'
    | 'wednesday'
    | 'thursday'
    | 'friday'
    | 'saturday';
export type SlaWindow = { start: string; end: string };
export type SlaWeek = Record<SlaWeekday, SlaWindow[]>;
export type SlaSettings = {
    resolution_minutes: Record<SlaPriority, number>;
    warning_threshold_percent: number;
    timezone: string;
    week: SlaWeek;
    customized: boolean;
};
