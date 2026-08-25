/* ═══════════════ Client ═══════════════ */

export interface Client {
    id: string;
    user_id: string;
    name: string;
    phone: string | null;
    telegram_id: string | null;
    max_id: string | null;
    avatar_url: string | null;
    is_blocked: boolean;
    disable_reactivation: boolean;
    notes: string | null;
    auth_token: string | null;
    created_at: string;
    updated_at: string;

    /** Aggregated from appointments — only present in list view */
    total_bookings?: number;
    completed_bookings?: number;
    ltv?: number;
    last_visit?: string | null;
}

/* ═══════════════ Pagination ═══════════════ */

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/* ═══════════════ Auth ═══════════════ */

export interface AuthUser {
    id: string;
    name: string;
    tariff_name: string;
}

export interface PageProps {
    auth?: { user?: AuthUser };
    appVersion?: string;
    [key: string]: unknown;
}
