export type User = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    avatar_url: string | null;
};

export type AuthCan = {
    viewDashboard: boolean;
    manageImports: boolean;
    manageUsers: boolean;
    manageCategories: boolean;
    manageHolidays: boolean;
    createOnBehalf: boolean;
    manageSla: boolean;
    viewAgents: boolean;
    useSavedReplies: boolean;
};

export type Auth = {
    user: User;
    can: AuthCan;
};
