import type { EarlierRequest, Appointment, Profile } from './types';
import { UnauthorizedError } from './types';

export type AuthHeadersProvider =
    () => Record<string, string> | null;

export type MiniappApi = ReturnType<typeof createMiniappApi>;

export function createMiniappApi(getAuthHeaders: AuthHeadersProvider) {
    const BASE = '/api/miniapp';

    async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
        const authHeaders = getAuthHeaders();
        if (authHeaders === null) {
            return Promise.reject('no_init_data');
        }

        const headers: Record<string, string> = {
            ...authHeaders,
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

    return {
        getAppointments(): Promise<Appointment[]> {
            return request<Appointment[]>('/appointments');
        },

        getHistory(): Promise<Appointment[]> {
            return request<Appointment[]>('/appointments/history');
        },

        getProfile(): Promise<Profile> {
            return request<Profile>('/profile');
        },

        cancelAppointment(id: string): Promise<{ ok: true } | { error: string; deadline_hours?: number }> {
            return request(`/appointments/${id}/cancel`, { method: 'POST', body: '{}' });
        },

        saveEarlierRequest(
            appointmentId: string,
            data: { date_from: string; date_to: string; time_from: string; time_to: string },
        ): Promise<{ ok: true; earlier_request: EarlierRequest } | { error: string }> {
            return request(`/appointments/${appointmentId}/earlier-request`, {
                method: 'PUT',
                body: JSON.stringify(data),
            });
        },

        cancelEarlierRequest(appointmentId: string): Promise<{ ok: true }> {
            return request(`/appointments/${appointmentId}/earlier-request`, { method: 'DELETE' });
        },
    };
}
