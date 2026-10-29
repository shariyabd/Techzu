export type RoleValue = 'admin' | 'agent' | 'customer';

export type RoleOption = { value: RoleValue; label: string };

export type CategoryOption = { id: number; name: string; is_active: boolean };

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: RoleValue | null;
    is_available: boolean;
    last_assigned_at: string | null;
    categories?: { id: number; name: string }[];
    created_at: string | null;
};

export type UserFilters = { q: string | null; role: RoleValue | null };

export type PermissionOption = { value: string; label: string };

export type RolePermissions = {
    value: RoleValue;
    label: string;
    permissions: string[];
};
