import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { parseVkLinkTokenFromLocation } from '@/vk-app/lib/vkBridge';
import { App } from '@/vk-app/App';

const mockSend = vi.fn().mockResolvedValue({ result: true });
const bridgeListeners = new Set<(event: unknown) => void>();

vi.mock('@vkontakte/vk-bridge', () => ({
    default: {
        send: (...args: unknown[]) => mockSend(...args),
        subscribe: (fn: (event: unknown) => void) => {
            bridgeListeners.add(fn);
        },
        unsubscribe: (fn: (event: unknown) => void) => {
            bridgeListeners.delete(fn);
        },
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

function emitBridge(type: string, data: Record<string, unknown> = {}) {
    act(() => {
        for (const fn of bridgeListeners) {
            fn({ detail: { type, data } });
        }
    });
}

/** Эмулирует навигацию VK: bridge-событие + актуальный hash (replaceState в проде). */
function emitLocationChanged(location: string) {
    const token = location.split('#').pop() ?? location;

    if (token.startsWith('link_vk_')) {
        setHash('#' + token);
    }

    emitBridge('VKWebAppLocationChanged', { location });
}

function statusFor(service: string, overrides: Record<string, unknown> = {}) {
    return {
        consent_required: false,
        phone_required: false,
        appointment_id: `appt-${service}`,
        appointment: {
            service,
            date: '15.01.2026',
            time: '14:00',
            price: 1500,
            address: null,
        },
        ...overrides,
    };
}

describe('parseVkLinkTokenFromLocation', () => {
    it('parses bare token', () => {
        expect(parseVkLinkTokenFromLocation('link_vk_abc')).toBe('link_vk_abc');
    });

    it('parses #token', () => {
        expect(parseVkLinkTokenFromLocation('#link_vk_abc')).toBe('link_vk_abc');
    });

    it('parses path with hash', () => {
        expect(parseVkLinkTokenFromLocation('/miniapp#link_vk_abc')).toBe('link_vk_abc');
    });

    it('parses full URL', () => {
        expect(parseVkLinkTokenFromLocation('https://vk.com/app123#link_vk_abc')).toBe('link_vk_abc');
    });

    it('returns null without token', () => {
        expect(parseVkLinkTokenFromLocation('/cabinet')).toBeNull();
        expect(parseVkLinkTokenFromLocation('')).toBeNull();
        expect(parseVkLinkTokenFromLocation('#other')).toBeNull();
        expect(parseVkLinkTokenFromLocation('link_vk_')).toBeNull();
    });
});

describe('live link navigation', () => {
    const original = window.location;

    beforeEach(() => {
        mockSend.mockReset().mockResolvedValue({ result: true });
        bridgeListeners.clear();
        delete (window as unknown as Record<string, unknown>).__VK_GROUP_ID__;
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', { value: original, writable: true });
        vi.unstubAllGlobals();
    });

    it('opens confirmation for new link while cabinet is open (LocationChanged)', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');

        render(<App />);
        expect(screen.getByText('Записи')).toBeInTheDocument();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Стрижка Б')),
        });
        vi.stubGlobal('fetch', mockFetch);

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Подтвердите запись')).toBeInTheDocument();
        });
        expect(screen.getByText('Стрижка Б')).toBeInTheDocument();
        expect(mockFetch).toHaveBeenCalledWith(
            expect.stringContaining('/api/miniapp/vk-consent/status'),
            expect.anything(),
        );
        expect(String(mockFetch.mock.calls[0][0])).toContain('token=link_vk_token_b');
    });

    it('parses LocationChanged with path+hash and starts scenario', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');

        render(<App />);

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Маникюр')),
        });
        vi.stubGlobal('fetch', mockFetch);

        emitLocationChanged('/book#link_vk_path_token');

        await waitFor(() => {
            expect(screen.getByText('Маникюр')).toBeInTheDocument();
        });
        expect(String(mockFetch.mock.calls[0][0])).toContain('token=link_vk_path_token');
    });

    it('hashchange while on cabinet opens scenario', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');

        render(<App />);
        expect(screen.getByText('Записи')).toBeInTheDocument();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Педикюр')),
        });
        vi.stubGlobal('fetch', mockFetch);

        setHash('#link_vk_hash_token');
        fireEvent(window, new Event('hashchange'));

        await waitFor(() => {
            expect(screen.getByText('Педикюр')).toBeInTheDocument();
        });
    });

    it('A→Б while status A is loading shows B and ignores late A', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        let resolveA!: (v: unknown) => void;
        const promiseA = new Promise((r) => {
 resolveA = r; 
});

        const mockFetch = vi.fn().mockReturnValueOnce(promiseA);
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        // Start B before A resolves
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        // Late A response must not overwrite B
        resolveA({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга А', { consent_required: true })),
        });

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });
        expect(screen.queryByText('Согласие на обработку данных')).not.toBeInTheDocument();
        expect(screen.queryByText('Услуга А')).not.toBeInTheDocument();
    });

    it('A→Б during confirm of A: late A completion does not enter cabinet or clear B hash', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        const mockFetch = vi.fn()
            // Status A
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Услуга А')),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Услуга А')).toBeInTheDocument();
        });

        let resolveConfirmA!: (v: unknown) => void;
        const confirmA = new Promise((r) => {
 resolveConfirmA = r; 
});
        // Confirm A stays pending
        mockFetch.mockReturnValueOnce(confirmA);

        fireEvent.click(screen.getByText('Подтвердить запись'));

        // Status B for the new scenario
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        resolveConfirmA({
            ok: true,
            json: () => Promise.resolve({ ok: true }),
        });

        await new Promise((r) => setTimeout(r, 20));

        // Still on B, hash not cleared to cabinet
        expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        expect(screen.queryByText('Записи')).not.toBeInTheDocument();
        expect(window.location.hash).toBe('#link_vk_token_b');
    });

    it('repeat LocationChanged with same token does not restart scenario or re-fetch', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_same');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Одна услуга')),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Одна услуга')).toBeInTheDocument();
        });

        expect(mockFetch).toHaveBeenCalledTimes(1);

        emitLocationChanged('link_vk_same');
        emitLocationChanged('#link_vk_same');

        await new Promise((r) => setTimeout(r, 20));

        expect(mockFetch).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Одна услуга')).toBeInTheDocument();
    });

    it('after completion ViewRestore with empty hash does not reopen used token', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_done');

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Готовая запись')),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Готовая запись')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Подтвердить запись'));

        await waitFor(() => {
            expect(screen.getByText('Записи')).toBeInTheDocument();
        });

        expect(mockFetch).toHaveBeenCalledTimes(2);

        // Stale ViewRestore (hash already cleared)
        emitBridge('VKWebAppViewRestore');

        // Stale LocationChanged with the same consumed token
        emitLocationChanged('link_vk_done');

        await new Promise((r) => setTimeout(r, 20));

        expect(screen.getByText('Записи')).toBeInTheDocument();
        expect(screen.queryByText('Подтвердите запись')).not.toBeInTheDocument();
        // No third status fetch for consumed token
        expect(mockFetch).toHaveBeenCalledTimes(2);
    });

    it('ViewRestore alone does not restart active scenario', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_keep');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Активная запись')),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Активная запись')).toBeInTheDocument();
        });

        expect(mockFetch).toHaveBeenCalledTimes(1);

        emitBridge('VKWebAppViewRestore');

        await new Promise((r) => setTimeout(r, 20));

        expect(mockFetch).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Активная запись')).toBeInTheDocument();
    });

    it('invalid/expired token status shows error', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_bad');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: false,
            json: () => Promise.resolve({ error: 'invalid_token' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Не удалось проверить статус согласия')).toBeInTheDocument();
        });
    });

    it('cold start with token in hash still opens onboarding', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_cold');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Холодный старт')),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Холодный старт')).toBeInTheDocument();
        });
        expect(String(mockFetch.mock.calls[0][0])).toContain('token=link_vk_cold');
    });

    it('confirm uses scenario token, not live URL hash', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_scenario');

        const mockFetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Сценарий')),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Сценарий')).toBeInTheDocument();
        });

        // Mutate URL hash without going through navigation API
        setHash('#link_vk_other_hash');

        fireEvent.click(screen.getByText('Подтвердить запись'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        const confirmBody = JSON.parse(mockFetch.mock.calls[1][1].body as string);
        expect(confirmBody.token).toBe('link_vk_scenario');
    });

    it('unsubscribes bridge listener on unmount', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');

        const { unmount } = render(<App />);
        expect(bridgeListeners.size).toBe(1);

        unmount();
        expect(bridgeListeners.size).toBe(0);
    });

    // ── ChangeFragment (receive-only, { location: string }) ──

    it('ChangeFragment without changing hash still opens scenario and syncs hash', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');

        const replaceSpy = vi.spyOn(window.history, 'replaceState');

        render(<App />);
        expect(screen.getByText('Записи')).toBeInTheDocument();
        expect(window.location.hash).toBe('');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Через ChangeFragment')),
        });
        vi.stubGlobal('fetch', mockFetch);

        // Событие само НЕ меняет window.location.hash — App должен синхронизировать.
        emitBridge('VKWebAppChangeFragment', { location: '#link_vk_cf_token' });

        await waitFor(() => {
            expect(screen.getByText('Через ChangeFragment')).toBeInTheDocument();
        });
        expect(replaceSpy).toHaveBeenCalledWith(
            null,
            '',
            expect.stringContaining('link_vk_cf_token'),
        );
        expect(String(mockFetch.mock.calls[0][0])).toContain('token=link_vk_cf_token');

        replaceSpy.mockRestore();
    });

    it('ChangeFragment path form without hash change opens scenario', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('');

        render(<App />);

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('ChangeFragment path')),
        });
        vi.stubGlobal('fetch', mockFetch);

        emitBridge('VKWebAppChangeFragment', { location: '/app#link_vk_cf_path' });

        await waitFor(() => {
            expect(screen.getByText('ChangeFragment path')).toBeInTheDocument();
        });
        expect(String(mockFetch.mock.calls[0][0])).toContain('token=link_vk_cf_path');
    });

    it('repeat ChangeFragment with same token does not re-fetch', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_cf_same');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Один раз')),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Один раз')).toBeInTheDocument();
        });
        expect(mockFetch).toHaveBeenCalledTimes(1);

        emitBridge('VKWebAppChangeFragment', { location: 'link_vk_cf_same' });
        emitBridge('VKWebAppChangeFragment', { location: '#link_vk_cf_same' });

        await new Promise((r) => {
            setTimeout(r, 20);
        });

        expect(mockFetch).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Один раз')).toBeInTheDocument();
    });

    it('ChangeFragment without link token does not leave active scenario', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_stay');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('Остаётся')),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Остаётся')).toBeInTheDocument();
        });

        emitBridge('VKWebAppChangeFragment', { location: '/cabinet' });

        await new Promise((r) => {
            setTimeout(r, 20);
        });

        expect(screen.getByText('Остаётся')).toBeInTheDocument();
        expect(mockFetch).toHaveBeenCalledTimes(1);
    });

    // ── linkTokenRef race: navigation B + finish A in one act ──

    it('navigation B and finish A in same act keep B active with hash', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        const mockFetch = vi.fn()
            // Status A
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Услуга А')),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Услуга А')).toBeInTheDocument();
        });

        let resolveConfirmA!: (v: unknown) => void;
        const confirmA = new Promise((r) => {
            resolveConfirmA = r;
        });
        mockFetch.mockReturnValueOnce(confirmA);

        fireEvent.click(screen.getByText('Подтвердить запись'));

        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        act(() => {
            // Принять навигацию на Б
            setHash('#link_vk_token_b');

            for (const fn of bridgeListeners) {
                fn({ detail: { type: 'VKWebAppLocationChanged', data: { location: 'link_vk_token_b' } } });
            }

            // Завершение А в том же act, до effect-синхронизации
            resolveConfirmA({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        });

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        expect(screen.queryByText('Записи')).not.toBeInTheDocument();
        expect(window.location.hash).toBe('#link_vk_token_b');
    });

    // ── Stale async chains: A→Б must not continue Bridge/API for A ──

    it('A→Б during consent of A does not request phone or link for A', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        const mockFetch = vi.fn()
            // Status A — consent required
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Согласие А', { consent_required: true, phone_required: true })),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Принимаю')).toBeInTheDocument();
        });

        let resolveConsentA!: (v: unknown) => void;
        const consentA = new Promise((r) => {
            resolveConsentA = r;
        });
        mockFetch.mockReturnValueOnce(consentA);

        fireEvent.click(screen.getByText('Принимаю'));

        mockSend.mockClear();
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        const sendCallsAfterSwitch = mockSend.mock.calls.length;

        resolveConsentA({
            ok: true,
            json: () => Promise.resolve({ ok: true }),
        });

        await new Promise((r) => {
            setTimeout(r, 30);
        });

        // Поздний consent А не должен запускать phone/profile/link
        expect(mockSend).toHaveBeenCalledTimes(sendCallsAfterSwitch);
        expect(mockSend).not.toHaveBeenCalledWith('VKWebAppGetPhoneNumber');
        expect(screen.getByText('Услуга Б')).toBeInTheDocument();

        const linkCalls = mockFetch.mock.calls.filter(
            (call) => typeof call[0] === 'string' && String(call[0]).includes('/api/miniapp/link'),
        );
        expect(linkCalls).toHaveLength(0);
    });

    it('A→Б during phone request does not request profile or link for A', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        const mockFetch = vi.fn()
            // Status A — phone only
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Телефон А', { consent_required: false, phone_required: true })),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Подтвердить номер')).toBeInTheDocument();
        });

        let resolvePhoneA!: (v: unknown) => void;
        const phoneA = new Promise((r) => {
            resolvePhoneA = r;
        });
        mockSend.mockReturnValueOnce(phoneA);

        fireEvent.click(screen.getByText('Подтвердить номер'));

        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        const sendCallsAfterSwitch = mockSend.mock.calls.length;

        resolvePhoneA({
            phone_number: '79001234567',
            sign: 'phone_sign',
            is_verified: true,
        });

        await new Promise((r) => {
            setTimeout(r, 30);
        });

        // Поздний phone А не должен запускать profile/link
        expect(mockSend).toHaveBeenCalledTimes(sendCallsAfterSwitch);
        expect(mockSend).not.toHaveBeenCalledWith('VKWebAppGetUserInfo');

        const linkCalls = mockFetch.mock.calls.filter(
            (call) => typeof call[0] === 'string' && String(call[0]).includes('/api/miniapp/link'),
        );
        expect(linkCalls).toHaveLength(0);
        expect(screen.getByText('Услуга Б')).toBeInTheDocument();
    });

    it('A→Б during profile request does not call link for A', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        const mockFetch = vi.fn()
            // Status A
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Профиль А', { consent_required: false, phone_required: true })),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Подтвердить номер')).toBeInTheDocument();
        });

        mockSend
            .mockResolvedValueOnce({
                phone_number: '79001234567',
                sign: 'phone_sign',
                is_verified: true,
            });

        let resolveProfileA!: (v: unknown) => void;
        const profileA = new Promise((r) => {
            resolveProfileA = r;
        });
        mockSend.mockReturnValueOnce(profileA);

        fireEvent.click(screen.getByText('Подтвердить номер'));

        // Ждём, пока phone уже ответил, profile висит
        await waitFor(() => {
            expect(mockSend).toHaveBeenCalledWith('VKWebAppGetPhoneNumber');
        });

        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        const fetchCallsAfterSwitch = mockFetch.mock.calls.length;

        resolveProfileA({ id: 1, first_name: 'Т', last_name: 'Т' });

        await new Promise((r) => {
            setTimeout(r, 30);
        });

        // Поздний profile А не должен вызывать link
        expect(mockFetch).toHaveBeenCalledTimes(fetchCallsAfterSwitch);
        const linkCalls = mockFetch.mock.calls.filter(
            (call) => typeof call[0] === 'string' && String(call[0]).includes('/api/miniapp/link'),
        );
        expect(linkCalls).toHaveLength(0);
        expect(screen.getByText('Услуга Б')).toBeInTheDocument();
    });

    it('A→Б during permission window does not postVkPermission for A', async () => {
        (window as unknown as Record<string, unknown>).__VK_GROUP_ID__ = 241438764;
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_token_a');

        const mockFetch = vi.fn()
            // Status A — confirmation path
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(statusFor('Разрешение А')),
            })
            // Confirm A
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ ok: true }),
            });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('Подтвердить запись')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Подтвердить запись'));

        await waitFor(() => {
            expect(screen.getByText('Разрешить уведомления')).toBeInTheDocument();
        });

        let resolvePermA!: (v: unknown) => void;
        const permA = new Promise((r) => {
            resolvePermA = r;
        });
        mockSend.mockReturnValueOnce(permA);

        fireEvent.click(screen.getByText('Разрешить уведомления'));

        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve(statusFor('Услуга Б')),
        });

        emitLocationChanged('link_vk_token_b');

        await waitFor(() => {
            expect(screen.getByText('Услуга Б')).toBeInTheDocument();
        });

        const fetchCallsAfterSwitch = mockFetch.mock.calls.length;

        resolvePermA({ result: true });

        await new Promise((r) => {
            setTimeout(r, 30);
        });

        // Поздний ответ разрешения А не должен слать postVkPermission
        const permCalls = mockFetch.mock.calls.filter(
            (call) => typeof call[0] === 'string' && String(call[0]).includes('/vk-permission'),
        );
        expect(permCalls).toHaveLength(0);
        expect(mockFetch).toHaveBeenCalledTimes(fetchCallsAfterSwitch);
        expect(screen.getByText('Услуга Б')).toBeInTheDocument();

        delete (window as unknown as Record<string, unknown>).__VK_GROUP_ID__;
    });

    it('ViewRestore during active scenario does not issue new requests', async () => {
        setSearch('?vk_app_id=123&vk_user_id=456&sign=abc');
        setHash('#link_vk_view');

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(statusFor('ViewRestore сценарий')),
        });
        vi.stubGlobal('fetch', mockFetch);

        render(<App />);

        await waitFor(() => {
            expect(screen.getByText('ViewRestore сценарий')).toBeInTheDocument();
        });

        expect(mockFetch).toHaveBeenCalledTimes(1);

        emitBridge('VKWebAppViewRestore');
        emitBridge('VKWebAppViewRestore');

        await new Promise((r) => {
            setTimeout(r, 20);
        });

        expect(mockFetch).toHaveBeenCalledTimes(1);
        expect(screen.getByText('ViewRestore сценарий')).toBeInTheDocument();
    });
});
