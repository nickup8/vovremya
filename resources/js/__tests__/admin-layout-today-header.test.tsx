import {
    describe,
    it,
    expect,
    vi,
    beforeEach,
    afterEach,
    beforeAll,
} from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';
import React from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { tzDateKey } from '@/pages/admin/components/calendar/helpers';
import { AppointmentStatus } from '@/types/appointment-status';

const mockUsePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockUsePage(),
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    router: { post: vi.fn(), get: vi.fn() },
}));

vi.mock('@/components/admin/Sidebar', () => ({
    default: () => null,
}));

vi.mock('@/components/ui/sonner', () => ({
    Toaster: () => null,
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'light', updateAppearance: vi.fn() }),
}));

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

type FetchResponse = { ok: boolean; json: () => Promise<unknown> };
const fetchMock =
    vi.fn<(input: string, init?: RequestInit) => Promise<FetchResponse>>();

function okJson(data: unknown): FetchResponse {
    return { ok: true, json: async () => data };
}

function isoAt(offsetMs: number): string {
    return new Date(Date.now() + offsetMs).toISOString();
}

function todayPayload(appointments: unknown[]): {
    date: string;
    timezone: string;
    appointments: unknown[];
} {
    return {
        date: tzDateKey(new Date(), 'UTC'),
        timezone: 'UTC',
        appointments,
    };
}

const FIVE_TWO_UPCOMING = todayPayload([
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
]);

const FIVE_CURRENT = todayPayload([
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
        start_at: isoAt(-45 * 60_000),
        end_at: isoAt(-5 * 60_000),
        status: AppointmentStatus.Paid,
    },
    {
        id: '5',
        start_at: isoAt(-30 * 60_000),
        end_at: isoAt(30 * 60_000),
        status: AppointmentStatus.Booked,
    },
]);

const FIVE_DONE = todayPayload(
    Array.from({ length: 5 }, (_, i) => ({
        id: String(i + 1),
        start_at: isoAt(-(5 - i) * 3600_000),
        end_at: isoAt(-(5 - i) * 3600_000 + 1800_000),
        status: AppointmentStatus.Paid,
    })),
);

function renderLayout(
    props: Partial<React.ComponentProps<typeof AdminLayout>> = {},
) {
    mockUsePage.mockReturnValue({
        props: { notifications: { unread_count: 0, items: [] } },
    });

    return render(
        <AdminLayout
            title="Календарь"
            showTodaySummary
            timezone="UTC"
            {...props}
        >
            <div>content</div>
        </AdminLayout>,
    );
}

beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AdminLayout — счётчик «Сегодня» (N01, независимый источник)', () => {
    it('загружает данные с GET /admin/calendar/today и показывает «осталось» на ПК и мобильном', async () => {
        fetchMock.mockResolvedValue(okJson(FIVE_TWO_UPCOMING));

        renderLayout();

        // Оба блока (мобильный и десктопный) — одна и та же строка.
        const texts = await screen.findAllByText(
            'Сегодня · 5 записей · осталось 2',
        );
        expect(texts).toHaveLength(2);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/admin/calendar/today');
    });

    it('последний визит на сегодня', async () => {
        fetchMock.mockResolvedValue(okJson(FIVE_CURRENT));

        renderLayout();

        const texts = await screen.findAllByText(
            'Сегодня · 5 записей · последний визит на сегодня',
        );
        expect(texts).toHaveLength(2);
    });

    it('на сегодня всё ✓', async () => {
        fetchMock.mockResolvedValue(okJson(FIVE_DONE));

        renderLayout();

        const texts = await screen.findAllByText(
            'Сегодня · 5 записей · на сегодня всё ✓',
        );
        expect(texts).toHaveLength(2);
    });

    it('нулевое число записей: «Сегодня · 0 записей»', async () => {
        fetchMock.mockResolvedValue(okJson(todayPayload([])));

        renderLayout();

        const texts = await screen.findAllByText('Сегодня · 0 записей');
        expect(texts).toHaveLength(2);
    });

    it('все записи дня отменены → total = 0', async () => {
        fetchMock.mockResolvedValue(
            okJson(
                todayPayload([
                    {
                        id: '1',
                        start_at: isoAt(3600_000),
                        end_at: isoAt(5400_000),
                        status: AppointmentStatus.Cancelled,
                    },
                ]),
            ),
        );

        renderLayout();

        const texts = await screen.findAllByText('Сегодня · 0 записей');
        expect(texts).toHaveLength(2);
    });

    it('снимок не за сегодня по таймзоне мастера не показывается как «Сегодня»', async () => {
        fetchMock.mockResolvedValue(
            okJson({
                date: '2000-01-01',
                timezone: 'UTC',
                appointments: [
                    {
                        id: '1',
                        start_at: '2000-01-01T10:00:00.000Z',
                        end_at: '2000-01-01T11:00:00.000Z',
                        status: AppointmentStatus.Booked,
                    },
                ],
            }),
        );

        renderLayout();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {
            await Promise.resolve();
        });

        expect(screen.queryByText(/Сегодня/u)).toBeNull();
    });

    it('showTodaySummary выключен — без запроса и без блока', () => {
        fetchMock.mockResolvedValue(okJson(todayPayload([])));

        renderLayout({ showTodaySummary: false });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.queryByText(/Сегодня/u)).toBeNull();
    });
});
