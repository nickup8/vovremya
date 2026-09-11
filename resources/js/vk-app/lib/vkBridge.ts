import bridge from '@vkontakte/vk-bridge';

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
    if (!search) return null;

    const raw = new URLSearchParams(search);

    if (!raw.has('sign')) return null;

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
    if (!hash) return null;

    const token = hash.slice(1);
    if (!token || !token.startsWith(LINK_TOKEN_PREFIX)) return null;

    return token;
}

export async function requestVkPhoneNumber() {
    return bridge.send('VKWebAppGetPhoneNumber');
}
