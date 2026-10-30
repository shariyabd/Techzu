import type { Auth, AuthCan, User } from '@/types/auth';

export type { AuthCan };
export type AuthUser = User;

export type SharedProps = {
    name: string;
    auth: Auth;
    flash: {
        success: string | null;
        error: string | null;
    };
    notifications: {
        unread_count: number;
    };
    [key: string]: unknown;
};
