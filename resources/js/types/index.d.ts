export interface User {
    id: number;
    name: string;
    username: string;
    email?: string | null;
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
        unread_messages_count?: number;
        wallet_balance?: string;
    };
    flash?: FlashMessages;
    locale?: string;
    dir?: 'rtl' | 'ltr';
    translations?: Record<string, any>;
};

declare module 'vue' {
    interface ComponentCustomProperties {
        __: (key: string, replace?: Record<string, any>) => string;
        $t: (key: string, replace?: Record<string, any>) => string;
    }
}

