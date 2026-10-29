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
