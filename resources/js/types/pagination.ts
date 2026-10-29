export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};
export type PaginationLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};
export type PaginationMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    links: PaginationLink[];
    path: string;
    per_page: number;
    to: number | null;
    total: number;
};
export type Paginated<T> = {
    data: T[];
    links: PaginationLinks;
    meta: PaginationMeta;
};
