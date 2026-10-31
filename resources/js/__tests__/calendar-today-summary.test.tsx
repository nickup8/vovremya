import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';
import CalendarPage from '@/pages/admin/calendar';
import { tzDateKey } from '@/pages/admin/components/calendar/helpers';
import { AppointmentStatus } from '@/types/appointment-status';

const mockUsePage = vi.hoisted(() => vi.fn());
const routerReload = vi.hoisted(() => vi.fn());

const echoState = vi.hoisted(() => {
    const listeners: Record<string, (...args: unknown[]) => void> = {};
    const channel = {
        listen: (event: string, cb: (...args: unknown[]) => void) => {
            listeners[event] = cb;

            return channel;
        },
        stopListening: vi.fn(() => channel),
    };

    return {
        listeners,
        channel,
        echo: vi.fn(() => ({ private: () => channel, leave: vi.fn() })),
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    useForm: (initial: Record<string, unknown> = {}) => ({
        data: initial,
        setData: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
        transform: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        submit: vi.fn(),
    }),
    router: {
        reload: routerReload,
        patch: vi.fn(),
        post: vi.fn(),
        get: vi.fn(),
        visit: vi.fn(),
        on: vi.fn(() => vi.fn()),
    },
}));

vi.mock('@/echo-config', () => ({ echo: echoState.echo }));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock('@/components/admin/Sidebar', () => ({ default: () => null }));
vi.mock('@/components/ui/sonner', () => ({ Toaster: () => null }));
vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'light', updateAppearance: vi.fn() }),
}));

type FetchResponse = { ok: boolean; json: () => Promise<unknown> };
const fetchMock =
    vi.fn<(input: string, init?: RequestInit) => Promise<FetchResponse>>();

function okJson(data: unknown): FetchResponse {
    return { ok: true, json: async () => data };
}

beforeAll(() => {
    if (!window.matchMedia) {
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: (query: string) => ({
                matches: false,
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                addListener: vi.fn(),
                removeListener: vi.fn(),
                onchange: null,
                dispatchEvent: vi.fn(),
            }),
        });
    }
});

beforeEach(() => {
    fetchMock.mockReset();
    routerReload.mockReset();
    vi.stubGlobal('fetch', fetchMock);

    // Далёкий период: в загруженном массиве и dateRange сегодняшних записей нет.
    mockUsePage.mockReturnValue({
        props: {
            appointments: [],
            initialBlockedTimes: [],
            clients: [],
            services: [],
            slotInterval: 30,
            workingHours: [],
            timezoneConfirmed: true,
            timezone: 'UTC',
            auth: { user: { id: 'u1', name: 'Тест', tariff_code: 'start' } },
            masters: [],
            dateRange: { start: '2020-01-01', end: '2020-01-31' },
        },
    });
});

function isoAt(offsetMs: number): string {
    return new Date(Date.now() + offsetMs).toISOString();
}

describe('CalendarPage — счётчик «Сегодня» независим от открытого периода (N01)', () => {
    it('прямой URL с start/end без сегодня: заголовок из /admin/calendar/today, навигация счётчик не трогает', async () => {
        fetchMock.mockResolvedValue(
            okJson({
                date: tzDateKey(new Date(), 'UTC'),
                timezone: 'UTC',
                appointments: [
                    {
                        id: '1',
                        start_at: isoAt(-3 * 3600_000),
                        end_at: isoAt(-2 * 3600_000),
                        status: AppointmentStatus.Paid,
                    },
                    {
                        id: '2',
                        start_at: isoAt(-2 * 3600_000),
                        end_at: isoAt(-1 * 3600_000),
                        status: AppointmentStatus.Paid,
                    },
                    {
                        id: '3',
                        start_at: isoAt(-90 * 60_000),
                        end_at: isoAt(-30 * 60_000),
                        status: AppointmentStatus.Paid,
                    },
                    {
                        id: '4',
                        start_at: isoAt(60 * 60_000),
                        end_at: isoAt(2 * 3600_000),
                        status: AppointmentStatus.Booked,
                    },
                    {
                        id: '5',
                        start_at: isoAt(2 * 3600_000),
                        end_at: isoAt(3 * 3600_000),
                        status: AppointmentStatus.Booked,
                    },
                ],
            }),
        );

        render(<CalendarPage />);

        // Массив видимого периода пуст — счётчик всё равно получает полный набор за сегодня.
        const texts = await screen.findAllByText(
            'Сегодня · 5 записей · осталось 2',
        );
        expect(texts).toHaveLength(2);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/admin/calendar/today');

        // Навигация на другой период: диапазон догружается через Inertia (router.reload),
        // счётчик от неё не зависит и повторно не запрашивается.
        const nextButtons = screen.getAllByLabelText('Следующий период');
        fireEvent.click(nextButtons[0]);

        await waitFor(() => expect(routerReload).toHaveBeenCalled());
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
