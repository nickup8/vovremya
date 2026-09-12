import { getVkLaunchParams } from './vkBridge';
import { createMiniappApi } from '../../shared/api/client';

function authHeaders(): Record<string, string> | null {
    const params = getVkLaunchParams();
    return params !== null ? { Authorization: `Bearer ${params}` } : null;
}

export const api = createMiniappApi(authHeaders);

export async function linkVkClient(
    token: string,
    phoneNumber: string,
    sign: string,
    profile?: { vk_profile_id: number; first_name: string; last_name: string },
): Promise<{ ok: true }> {
    const headers = authHeaders();
    if (!headers) throw new Error('no_vk_auth');

    const res = await fetch('/api/miniapp/link', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
            token,
            phone_number: phoneNumber,
            sign,
            ...(profile ?? {}),
        }),
    });

    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? 'link_failed');
    }

    return res.json();
}

export async function submitVkConsent(): Promise<{ ok: true }> {
    const headers = authHeaders();
    if (!headers) throw new Error('no_vk_auth');

    const res = await fetch('/api/miniapp/vk-consent', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json', Accept: 'application/json' },
    });

    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? 'consent_failed');
    }

    return res.json();
}

export async function getVkConsentStatus(token: string): Promise<{ consent_required: boolean }> {
    const headers = authHeaders();
    if (!headers) throw new Error('no_vk_auth');

    const url = new URL('/api/miniapp/vk-consent/status', window.location.origin);
    url.searchParams.set('token', token);

    const res = await fetch(url.toString(), {
        headers: { ...headers, Accept: 'application/json' },
    });

    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? 'consent_status_failed');
    }

    return res.json();
}
