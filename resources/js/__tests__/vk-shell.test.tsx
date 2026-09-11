import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { getVkLaunchParams, getVkLinkToken } from '@/vk-app/lib/vkBridge';
import { vkPlatform } from '@/vk-app/lib/vkPlatform';
import { App } from '@/vk-app/App';

const mockSend = vi.fn().mockResolvedValue({ result: true });

vi.mock('@vkontakte/vk-bridge', () => ({
    default: {
        send: (...args: unknown[]) => mockSend(...args),
    },
}));

vi.mock('@/shared/api/ApiContext', () => ({
    useApi: () => ({
        getAppointments: vi.fn().mockResolvedValue([]),
        cancelAppointment: vi.fn(),
        saveEarlierRequest: vi.fn(),
        cancelEarlierRequest: vi.fn(),
    }),
}));

vi.mock('@/shared/platform/PlatformContext', () => ({
    usePlatform: () => ({
        appName: 'VK',
        backButton: { show: vi.fn(), hide: vi.fn(), onClick: vi.fn(), offClick: vi.fn() },
        haptic: { impact: vi.fn(), notify: vi.fn() },
        openLink: vi.fn(),
    }),
}));

function setSearch(search: string) {
    const url = new URL('http://localhost' + search);
    Object.defineProperty(window, 'location', {
        value: { ...window.location, search: url.search },
        writable: true,
    });
}

function setHash(hash: string) {
    Object.defineProperty(window, 'location', {
        value: { ...window.location, hash },
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

describe('getVkLinkToken', () => {
    const original = window.location;

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: original, writable: true });
    });

    it('reads #link_vk_TEST', () => {
        setHash('#link_vk_abc123');
        expect(getVkLinkToken()).toBe('link_vk_abc123');
    });

    it('returns null for empty hash', () => {
        setHash('');
        expect(getVkLinkToken()).toBeNull();
    });

    it('returns null for unrelated hash', () => {
        setHash('#some_other_value');
        expect(getVkLinkToken()).toBeNull();
    });

    it('does not mix launch query with hash', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_here');
        expect(getVkLaunchParams()).toBe('vk_app_id=123&vk_user_id=456&sign=abc');
        expect(getVkLinkToken()).toBe('link_vk_token_here');
    });
});

describe('App routing', () => {
    const original = window.location;

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: original, writable: true });
    });

    it('shows onboarding when link token present', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        render(<App />);

        expect(screen.getByText('Подтвердите номер телефона')).toBeInTheDocument();
        expect(screen.queryByText('Пока нет записей')).not.toBeInTheDocument();
    });

    it('shows AppShell when no link token', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');
        render(<App />);

        expect(screen.queryByText('Подтвердите номер телефона')).not.toBeInTheDocument();
        expect(screen.getByText('Записи')).toBeInTheDocument();
    });

    it('successful linking removes hash and shows AppShell', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: true,
        });

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ ok: true }),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Подтвердить номер'));

        await waitFor(() => {
            expect(replaceSpy).toHaveBeenCalled();
        });

        expect(replaceSpy.mock.calls[0]?.[2]).not.toContain('#');

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        expect(screen.queryByText('Подтвердите номер телефона')).not.toBeInTheDocument();
        vi.unstubAllGlobals();
    });

    it('is_verified=false still calls linkVkClient (backend verifies)', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');

        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: false,
        });

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ ok: true }),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Подтвердить номер'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalled();
        });

        const body = JSON.parse(mockFetch.mock.calls[0][1].body);
        expect(body.phone_number).toBe('79001234567');
        expect(body.sign).toBe('phone_sign');
        vi.unstubAllGlobals();
    });

    it('backend error displays user-friendly message', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');

        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'bad_sign',
            is_verified: false,
        });

        const mockFetch = vi.fn().mockResolvedValue({
            ok: false,
            json: () => Promise.resolve({ error: 'invalid_phone_sign' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Подтвердить номер'));

        await waitFor(() => {
            expect(screen.getByText('Не удалось подтвердить номер телефона')).toBeInTheDocument();
        });
        vi.unstubAllGlobals();
    });
});
