import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

const mockUsePage = vi.fn();
const mockRouterGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => React.createElement('title', null, title),
    Link: ({ children, ...p }: any) => React.createElement('a', p, children),
    router: { get: (...args: unknown[]) => mockRouterGet(...args) },
    usePage: () => mockUsePage(),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...p }: any) => React.createElement('button', p, children),
}));

vi.mock('@/lib/utils', () => ({
    getInitials: () => 'TM',
}));

function makePageProps(overrides: Record<string, unknown> = {}) {
    return {
        props: {
            master: { name: 'Тест Мастер', specialty: 'Парикмахер', address: null, avatar_url: null, master_slug: 'test-master' },
            services: [{ id: 'svc-1', title: 'Стрижка', duration_minutes: 60, price: 1000 }],
            availableSlots: ['10:00', '11:00'],
            selectedDate: '2025-07-01',
            selectedServiceId: null,
            maxBotName: 'test_bot',
            ...overrides,
        },
    };
}

async function navigateToProviderStep() {
    mockUsePage.mockReturnValue(makePageProps({
        selectedServiceId: 'svc-1',
        preselectedServiceId: 'svc-1',
    }));

    mockRouterGet.mockImplementation((_url: string, _data: unknown, opts: Record<string, Function>) => {
        opts.onSuccess?.({ props: { availableSlots: ['10:00', '11:00'] } });
        opts.onFinish?.();
    });

    const { default: Widget } = await import('@/pages/booking/widget');
    render(React.createElement(Widget));

    fireEvent.click(screen.getByText('Далее'));

    await waitFor(() => {
        expect(screen.getByText('10:00')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('10:00'));
    fireEvent.click(screen.getByText('Далее'));
}

describe('Booking widget — VK provider', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('displays VK booking option', async () => {
        await navigateToProviderStep();
        expect(screen.getByText('Записаться через VK')).toBeInTheDocument();
    });

    it('sends provider=vk on VK submit', async () => {
        await navigateToProviderStep();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ vk_url: 'https://vk.com/app123#link_vk_test' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        fireEvent.click(screen.getByText('Записаться через VK'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalled();
        });

        const body = JSON.parse(mockFetch.mock.calls[0][1].body);
        expect(body.provider).toBe('vk');
        vi.unstubAllGlobals();
    });

    it('vk_url is used for redirect (no error shown on success)', async () => {
        await navigateToProviderStep();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ vk_url: 'https://vk.com/app123#link_vk_test' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        fireEvent.click(screen.getByText('Записаться через VK'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalled();
        });

        expect(screen.queryByText(/Не удалось/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/Ошибка/i)).not.toBeInTheDocument();
        vi.unstubAllGlobals();
    });

    it('Telegram redirect still works (no error on success)', async () => {
        await navigateToProviderStep();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ telegram_url: 'https://t.me/test_bot?start=book_123' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        fireEvent.click(screen.getByText('Записаться через Telegram'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalled();
        });

        expect(screen.queryByText(/Не удалось/i)).not.toBeInTheDocument();
        vi.unstubAllGlobals();
    });

    it('MAX redirect still works (no error on success)', async () => {
        await navigateToProviderStep();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ max_url: 'https://max.ru/test_bot?start=book_123' }),
        });
        vi.stubGlobal('fetch', mockFetch);

        fireEvent.click(screen.getByText('Записаться через MAX'));

        await waitFor(() => {
            expect(mockFetch).toHaveBeenCalled();
        });

        expect(screen.queryByText(/Не удалось/i)).not.toBeInTheDocument();
        vi.unstubAllGlobals();
    });

    it('missing vk_url shows error instead of redirect', async () => {
        await navigateToProviderStep();

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ vk_url: null }),
        });
        vi.stubGlobal('fetch', mockFetch);

        fireEvent.click(screen.getByText('Записаться через VK'));

        await waitFor(() => {
            expect(screen.getByText('Не удалось получить ссылку для перехода. Попробуйте позже.')).toBeInTheDocument();
        });

        vi.unstubAllGlobals();
    });
});
