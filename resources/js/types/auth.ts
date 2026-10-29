export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    roles: string[];
    avatar?: string;
};

export type AuthCan = {
    viewDashboard: boolean;
    manageImports: boolean;
    manageUsers: boolean;
    manageCategories: boolean;
    manageHolidays: boolean;
    createOnBehalf: boolean;
};

export type Auth = {
    user: User;
    can: AuthCan;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
