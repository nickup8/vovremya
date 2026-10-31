import bridge from '@vkontakte/vk-bridge';
import type { VKBridgeSubscribeHandler } from '@vkontakte/vk-bridge';

export async function initVkApp(): Promise<void> {
    try {
        await bridge.send('VKWebAppInit');
    } catch {
        // VKWebAppInit failed — non-critical, app can still function
    }
}

const VK_PARAM_PREFIX = /^vk_/;

/**
 * Извлекает signed VK credential envelope из query string.
 * Возвращает только параметры с префиксом `vk_` + `sign`.
 * Custom params (irsi_link и т.п.) исключены.
 */
export function getVkLaunchParams(): string | null {
    const search = window.location.search;

    if (!search) {
return null;
}

    const raw = new URLSearchParams(search);

    if (!raw.has('sign')) {
return null;
}

    const vk = new URLSearchParams();

    for (const [key, value] of raw) {
        if (VK_PARAM_PREFIX.test(key) || key === 'sign') {
            vk.append(key, value);
        }
    }

    const result = vk.toString();

    return result || null;
}

const LINK_TOKEN_PREFIX = 'link_vk_';

export function getVkLinkToken(): string | null {
    const hash = window.location.hash;

    if (!hash) {
return null;
}

    const token = hash.slice(1);

    if (!token || !token.startsWith(LINK_TOKEN_PREFIX)) {
return null;
}

    return token;
}

/**
 * Извлекает link-токен из payload VKWebAppLocationChanged.
 * location может быть path с hash, голым hash или полным URL —
 * токен всегда в сегменте с префиксом link_vk_.
 */
export function parseVkLinkTokenFromLocation(location: string): string | null {
    if (!location) {
return null;
}

    let loc = location.trim();

    if (!loc) {
return null;
}

    try {
        if (loc.includes('://') || loc.startsWith('//')) {
            const u = new URL(loc, window.location.origin);
            loc = u.hash.startsWith('#') ? u.hash.slice(1) : u.hash;
        } else {
            const hashIdx = loc.indexOf('#');

            if (hashIdx >= 0) {
                loc = loc.slice(hashIdx + 1);
            }
        }
    } catch {
        return null;
    }

    const queryIdx = loc.indexOf('?');

    if (queryIdx >= 0) {
        loc = loc.slice(0, queryIdx);
    }

    if (loc.startsWith('/')) {
        const segments = loc.split('/').filter(Boolean);
        loc = segments.length > 0 ? segments[segments.length - 1] : '';
    }

    if (!loc || !loc.startsWith(LINK_TOKEN_PREFIX) || loc.length <= LINK_TOKEN_PREFIX.length) {
        return null;
    }

    return loc;
}

/**
 * Подписка на входящие переходы по ссылкам при работающем приложении.
 * - hashchange — browser-навигация по hash
 * - VKWebAppLocationChanged — bridge-событие с payload { location: string }
 * - VKWebAppViewRestore — пустой payload; переиздаёт текущий hash,
 *   сам по себе не означает новую ссылку
 */
export function subscribeToVkLinkNavigation(onToken: (token: string | null) => void): () => void {
    const onHashChange = () => {
        onToken(getVkLinkToken());
    };

    const bridgeHandler: VKBridgeSubscribeHandler = (event) => {
        if (!event?.detail) {
return;
}

        const { type, data } = event.detail;

        if (type === 'VKWebAppLocationChanged') {
            const location = data && typeof data === 'object' && 'location' in data ? data.location : undefined;

            if (typeof location === 'string') {
                const token = parseVkLinkTokenFromLocation(location);

                // Посторонний location без link-токена не должен сбрасывать активный сценарий.
                if (token) {
                    onToken(token);
                }
            }

            return;
        }

        if (type === 'VKWebAppViewRestore') {
            onToken(getVkLinkToken());
        }
    };

    window.addEventListener('hashchange', onHashChange);
    bridge.subscribe(bridgeHandler);

    return () => {
        window.removeEventListener('hashchange', onHashChange);
        bridge.unsubscribe(bridgeHandler);
    };
}

export async function requestVkPhoneNumber() {
    return bridge.send('VKWebAppGetPhoneNumber');
}

export function getVkGroupId(): number | null {
    const raw = window.__VK_GROUP_ID__;

    if (raw == null) {
return null;
}

    const n = typeof raw === 'number' ? raw : Number(raw);

    if (!Number.isFinite(n) || n <= 0) {
return null;
}

    return n;
}

export async function requestVkMessagePermission(groupId: number): Promise<boolean> {
    try {
        const res = await bridge.send('VKWebAppAllowMessagesFromGroup', {
            group_id: groupId,
        });

        return res.result === true;
    } catch {
        return false;
    }
}

export interface VkUserProfile {
    id: number;
    first_name: string;
    last_name: string;
}

export async function requestVkUserInfo(): Promise<VkUserProfile | null> {
    try {
        const res = await bridge.send('VKWebAppGetUserInfo');

        return { id: res.id, first_name: res.first_name ?? '', last_name: res.last_name ?? '' };
    } catch {
        return null;
    }
}
