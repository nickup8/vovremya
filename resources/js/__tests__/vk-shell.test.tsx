import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { getVkLaunchParams } from '@/vk-app/lib/vkBridge';
import { vkPlatform } from '@/vk-app/lib/vkPlatform';

vi.mock('@vkontakte/vk-bridge', () => ({
    default: {
        send: vi.fn().mockResolvedValue({ result: true }),
    },
}));

function setSearch(search: string) {
    const url = new URL('http://localhost' + search);
    Object.defineProperty(window, 'location', {
        value: { ...window.location, search: url.search },
        writable: true,
    });
}

describe('getVkLaunchParams', () => {
    const original = window.location;

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: original, writable: true });
    });

    it('returns VK params without leading ?', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&vk_ts=999&sign=abc');
        const result = getVkLaunchParams();

        expect(result).toBe('vk_app_id=123&vk_user_id=456&vk_ts=999&sign=abc');
    });

    it('excludes non-VK params like irsi_link', () => {
        setSearch('?irsi_link=SECRET&vk_app_id=123&vk_user_id=456&sign=abc');
        const result = getVkLaunchParams();

        expect(result).not.toContain('irsi_link');
        expect(result).not.toContain('SECRET');
        expect(result).toContain('vk_app_id=123');
        expect(result).toContain('sign=abc');
    });

    it('returns null for empty search', () => {
        setSearch('');
        expect(getVkLaunchParams()).toBeNull();
    });

    it('returns null when sign is missing', () => {
        setSearch('?vk_app_id=123&vk_user_id=456');
        expect(getVkLaunchParams()).toBeNull();
    });
});

describe('vkPlatform', () => {
    it('has appName VK', () => {
        expect(vkPlatform.appName).toBe('VK');
    });

    it('backButton methods are callable without throwing', () => {
        expect(() => vkPlatform.backButton.show()).not.toThrow();
        expect(() => vkPlatform.backButton.hide()).not.toThrow();
        expect(() => vkPlatform.backButton.onClick(() => {})).not.toThrow();
        expect(() => vkPlatform.backButton.offClick(() => {})).not.toThrow();
    });

    it('haptic methods are callable without throwing', () => {
        expect(() => vkPlatform.haptic.impact('light')).not.toThrow();
        expect(() => vkPlatform.haptic.impact('medium')).not.toThrow();
        expect(() => vkPlatform.haptic.impact('heavy')).not.toThrow();
        expect(() => vkPlatform.haptic.impact('rigid')).not.toThrow();
        expect(() => vkPlatform.haptic.impact('soft')).not.toThrow();
        expect(() => vkPlatform.haptic.notify('success')).not.toThrow();
        expect(() => vkPlatform.haptic.notify('error')).not.toThrow();
        expect(() => vkPlatform.haptic.notify('warning')).not.toThrow();
    });

    it('openLink does not throw', () => {
        const spy = vi.spyOn(window, 'open').mockReturnValue(null);
        expect(() => vkPlatform.openLink('https://example.com')).not.toThrow();
        expect(spy).toHaveBeenCalledWith('https://example.com', '_blank', 'noopener,noreferrer');
        spy.mockRestore();
    });
});
