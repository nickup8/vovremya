import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { getVkGroupId, getVkLaunchParams, getVkLinkToken, requestVkMessagePermission } from '@/vk-app/lib/vkBridge';
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

describe('getVkGroupId', () => {
    afterEach(() => {
        delete (window as Record<string, unknown>).__VK_GROUP_ID__;
    });

    it('returns number from number', () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 241438764;
        expect(getVkGroupId()).toBe(241438764);
    });

    it('returns number from numeric string', () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = '241438764';
        expect(getVkGroupId()).toBe(241438764);
    });

    it('returns null for undefined', () => {
        expect(getVkGroupId()).toBeNull();
    });

    it('returns null for 0', () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 0;
        expect(getVkGroupId()).toBeNull();
    });

    it('returns null for negative', () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = -1;
        expect(getVkGroupId()).toBeNull();
    });

    it('returns null for invalid string', () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 'abc';
        expect(getVkGroupId()).toBeNull();
    });
});

describe('requestVkMessagePermission', () => {
    it('returns true when bridge returns { result: true }', async () => {
        mockSend.mockResolvedValueOnce({ result: true });
        const result = await requestVkMessagePermission(241438764);
        expect(result).toBe(true);
        expect(mockSend).toHaveBeenCalledWith('VKWebAppAllowMessagesFromGroup', { group_id: 241438764 });
    });

    it('returns false when bridge rejects', async () => {
        mockSend.mockRejectedValueOnce(new Error('user denied'));
        const result = await requestVkMessagePermission(241438764);
        expect(result).toBe(false);
    });
});

describe('App routing', () => {
    const original = window.location;

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: original, writable: true });
    });

    it('shows PDN consent screen when link token present', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        render(<App />);

        expect(screen.getByText('Согласие на обработку данных')).toBeInTheDocument();
        expect(screen.queryByText('Пока нет записей')).not.toBeInTheDocument();
    });

    it('shows AppShell when no link token', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');
        render(<App />);

        expect(screen.queryByText('Согласие на обработку данных')).not.toBeInTheDocument();
        expect(screen.getByText('Записи')).toBeInTheDocument();
    });

    it('clicking "Принимаю" calls consent endpoint then phone then link', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        // Phone request
        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: true,
        });

        const mockFetch = vi.fn()
            // Consent endpoint
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            // Link endpoint
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(replaceSpy).toHaveBeenCalled();
        });

        // Consent endpoint called first
        expect(mockFetch.mock.calls[0][0]).toBe('/api/miniapp/vk-consent');
        expect(mockFetch.mock.calls[0][1].method).toBe('POST');

        // Link endpoint called second
        expect(mockFetch.mock.calls[1][0]).toBe('/api/miniapp/link');

        // Phone number bridge call made
        expect(mockSend).toHaveBeenCalledWith('VKWebAppGetPhoneNumber');

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        expect(screen.queryByText('Согласие на обработку данных')).not.toBeInTheDocument();
        vi.unstubAllGlobals();
    });

    it('consent failure does not call VKWebAppGetPhoneNumber', async () => {
        mockSend.mockClear();
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: false,
            json: () => Promise.resolve({ error: 'consent_failed' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(screen.getByText('Не удалось подтвердить согласие. Попробуйте ещё раз')).toBeInTheDocument();
        });

        // Phone request NOT called
        expect(mockSend).not.toHaveBeenCalledWith('VKWebAppGetPhoneNumber');
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

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        const body = JSON.parse(mockFetch.mock.calls[1][1].body);
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

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: false,
                json: () => Promise.resolve({ error: 'invalid_phone_sign' }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(screen.getByText('Не удалось подтвердить номер телефона')).toBeInTheDocument();
        });
        vi.unstubAllGlobals();
    });

    it('shows permission phase after link when group_id configured', async () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 241438764;
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');

        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: true,
        });

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(screen.getByText('Разрешить уведомления')).toBeInTheDocument();
        });
        expect(screen.getByText('Позже')).toBeInTheDocument();
        expect(screen.queryByText('Записи')).not.toBeInTheDocument();

        delete (window as Record<string, unknown>).__VK_GROUP_ID__;
        vi.unstubAllGlobals();
    });

    it('permission "Разрешить" calls VKWebAppAllowMessagesFromGroup and proceeds', async () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 241438764;
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        mockSend
            .mockResolvedValueOnce({
                phone_number: '79001234567',
                sign: 'phone_sign',
                is_verified: true,
            })
            .mockResolvedValueOnce({ result: true });

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(screen.getByText('Разрешить уведомления')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Разрешить уведомления'));

        await waitFor(() => {
            expect(mockSend).toHaveBeenCalledWith('VKWebAppAllowMessagesFromGroup', { group_id: 241438764 });
        });

        await waitFor(() => {
            expect(replaceSpy).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        delete (window as Record<string, unknown>).__VK_GROUP_ID__;
        vi.unstubAllGlobals();
    });

    it('permission rejection still proceeds to AppShell', async () => {
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 241438764;
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        mockSend
            .mockResolvedValueOnce({
                phone_number: '79001234567',
                sign: 'phone_sign',
                is_verified: true,
            })
            .mockRejectedValueOnce(new Error('user denied'));

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(screen.getByText('Разрешить уведомления')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Разрешить уведомления'));

        await waitFor(() => {
            expect(replaceSpy).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        delete (window as Record<string, unknown>).__VK_GROUP_ID__;
        vi.unstubAllGlobals();
    });

    it('permission "Позже" skips bridge call and proceeds', async () => {
        mockSend.mockClear();
        (window as Record<string, unknown>).__VK_GROUP_ID__ = 241438764;
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: true,
        });

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(screen.getByText('Позже')).toBeInTheDocument();
        });

        // Only phone request was sent, not AllowMessagesFromGroup
        const callsBeforeLater = mockSend.mock.calls.filter(
            ([method]: [string]) => method === 'VKWebAppAllowMessagesFromGroup',
        );
        expect(callsBeforeLater).toHaveLength(0);

        fireEvent.click(screen.getByText('Позже'));

        await waitFor(() => {
            expect(replaceSpy).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        // Still no permission call
        const callsAfterLater = mockSend.mock.calls.filter(
            ([method]: [string]) => method === 'VKWebAppAllowMessagesFromGroup',
        );
        expect(callsAfterLater).toHaveLength(0);

        delete (window as Record<string, unknown>).__VK_GROUP_ID__;
        vi.unstubAllGlobals();
    });

    it('skips permission phase when group_id not configured', async () => {
        delete (window as Record<string, unknown>).__VK_GROUP_ID__;
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        mockSend.mockResolvedValueOnce({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: true,
        });

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);
        fireEvent.click(screen.getByText('Принимаю'));

        await waitFor(() => {
            expect(replaceSpy).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        expect(screen.queryByText('Разрешить уведомления')).not.toBeInTheDocument();

        vi.unstubAllGlobals();
    });

    it('old "Подтвердите номер телефона" step no longer exists', () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_test_token');
        render(<App />);

        expect(screen.queryByText('Подтвердите номер телефона')).not.toBeInTheDocument();
        expect(screen.queryByText('Подтвердить номер')).not.toBeInTheDocument();
    });
});
