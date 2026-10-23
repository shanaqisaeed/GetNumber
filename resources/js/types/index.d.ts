export interface User {
    id: number;
    name: string;
    username: string;
    role: 'admin' | 'user';
    status: 'active' | 'suspended' | 'inactive';
    is_admin?: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash?: FlashMessages;
};

