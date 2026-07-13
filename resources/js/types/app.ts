import { AppointmentStatus } from './appointment-status';

/* ═══════════════ User (Master) ═══════════════ */

export interface User {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    telegram_id: string | null;
    telegram_chat_id: string | null;
    max_id: string | null;
    avatar_url: string | null;
    is_master: boolean;
    master_slug: string | null;
    specialty: string | null;
    address: string | null;
    tariff: string;
    expires_at: string | null;
    is_super_admin: boolean;
    is_blocked: boolean;
    telegram_notifications: boolean;
    max_notifications: boolean;
    soft_deposit: boolean;
    deposit_timeout: number | null;
    deposit_percent: number | null;
    slot_interval: number | null;
    settings: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
}

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

/* ═══════════════ Service ═══════════════ */

export interface Service {
    id: string;
    user_id: string;
    title: string;
    price: number;
    duration_minutes: number;
    created_at: string;
    updated_at: string;
}

/* ═══════════════ Appointment ═══════════════ */

export interface Appointment {
    id: string;
    master_id: string;
    client_id: string | null;
    service_id: string;
    start_time: string;
    status: AppointmentStatus;
    provider: string | null;
    reminder_24h_sent: boolean;
    reminder_final_sent: boolean;
    created_at: string;
    updated_at: string;
}

/** Appointment as returned by CalendarController (with joined data) */
export interface CalendarAppointment {
    id: string;
    client_name: string;
    client_phone: string | null;
    client_avatar_url: string | null;
    service: string;
    duration: number;
    price: number;
    time: string;
    date: string;
    status: AppointmentStatus;
}

/* ═══════════════ WorkingHour ═══════════════ */

export interface WorkingHour {
    id: string;
    user_id: string;
    day_of_week: number;
    start_time: string;
    end_time: string;
}

/* ═══════════════ BlockedTime ═══════════════ */

export interface BlockedTime {
    id: string;
    start_datetime: string;
    end_datetime: string;
    reason: string;
}

/* ═══════════════ Subscription ═══════════════ */

export interface Subscription {
    id: string;
    user_id: string;
    tariff: string;
    status: string;
    payment_id: string | null;
    expires_at: string;
    created_at: string;
    updated_at: string;
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
    email: string | null;
    tariff: string;
    tariff_name: string;
}

export interface PageProps {
    auth?: { user?: AuthUser };
    name?: string;
    appVersion?: string;
    sidebarOpen?: boolean;
    [key: string]: unknown;
}
