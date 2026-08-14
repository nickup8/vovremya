import { getInitData } from './maxBridge';

// Типы ответов API (минимально, по полям API Resource шага 3)
export interface Appointment {
    id: string;
    service: string;
    price: number;
    status: string;
    start_at: string;
    start_at_human: string;
    master: { name: string; address: string | null; phone: string | null; master_slug: string | null } | null;
    can_cancel: boolean;
}

export interface Profile {
    name: string | null;
    phone: string | null;
}

export class UnauthorizedError extends Error {
    constructor() {
        super('unauthorized');
        this.name = 'UnauthorizedError';
    }
}

const BASE = '/api/miniapp';

/**
 * Базовый fetch-запрос к API мини-аппа.
 * Автоматически шлёт X-Max-Init-Data.
 */
async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const initData = getInitData();
    if (initData === null) {
        return Promise.reject('no_init_data');
    }

    const headers: Record<string, string> = {
        'X-Max-Init-Data': initData,
        Accept: 'application/json',
        ...(options.headers as Record<string, string> ?? {}),
    };

    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(`${BASE}${path}`, { ...options, headers });

    if (res.status === 401) {
        throw new UnauthorizedError();
    }

    if (res.status === 422) {
        return res.json() as Promise<T>;
    }

    if (!res.ok) {
        throw new Error(`API error: ${res.status}`);
    }

    return res.json() as Promise<T>;
}

/** Активные записи (Booked + будущее) */
export function getAppointments(): Promise<Appointment[]> {
    return request<Appointment[]>('/appointments');
}

/** История записей (прошлые, все статусы) */
export function getHistory(): Promise<Appointment[]> {
    return request<Appointment[]>('/appointments/history');
}

/** Профиль клиента */
export function getProfile(): Promise<Profile> {
    return request<Profile>('/profile');
}

/** Отмена записи */
export function cancelAppointment(id: string): Promise<{ ok: true } | { error: string; deadline_hours?: number }> {
    return request(`/appointments/${id}/cancel`, { method: 'POST', body: '{}' });
}
