import { getVkLaunchParams } from './vkBridge';
import { createMiniappApi } from '../../shared/api/client';

function authHeaders(): Record<string, string> | null {
    const params = getVkLaunchParams();
    return params !== null ? { Authorization: `Bearer ${params}` } : null;
}

export const api = createMiniappApi(authHeaders);

export async function linkVkClient(token: string, phoneNumber: string, sign: string): Promise<{ ok: true }> {
    const headers = authHeaders();
    if (!headers) throw new Error('no_vk_auth');

    const res = await fetch('/api/miniapp/link', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token, phone_number: phoneNumber, sign }),
    });

    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? 'link_failed');
    }

    return res.json();
}
