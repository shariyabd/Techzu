export type User = {
    id: number;
    name: string;
    email: string;
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
