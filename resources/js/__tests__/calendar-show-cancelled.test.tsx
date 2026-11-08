import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest';
import {
    render,
    screen,
    fireEvent,
    waitFor,
    within,
    act,
} from '@testing-library/react';
import React from 'react';
import CalendarPage from '@/pages/admin/calendar';
import { AppointmentStatus } from '@/types/appointment-status';
import {
    dateToKey,
    tzDateKey,
    getWeekDates,
} from '@/pages/admin/components/calendar/helpers';
import type {
    Appointment,
    WorkingHour,
} from '@/pages/admin/components/calendar/types';

const mockUsePage = vi.hoisted(() => vi.fn());
const routerReload = vi.hoisted(() => vi.fn());

const echoState = vi.hoisted(() => {
    const listeners: Record<string, Array<(...args: unknown[]) => void>> = {};
    const channel = {
        listen: (event: string, cb: (...args: unknown[]) => void) => {
            (listeners[event] ??= []).push(cb);

            return channel;
        },
        stopListening: (event: string, cb: (...args: unknown[]) => void) => {
            listeners[event] = (listeners[event] ?? []).filter((f) => f !== cb);

            return channel;
        },
    };

    return {
        listeners,
        emit: (event: string, payload: unknown) => {
            for (const cb of listeners[event] ?? []) {
                cb(payload);
            }
        },
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

const readSource = (relative: string) =>
    fs.readFileSync(path.resolve(__dirname, relative), 'utf-8');

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

    // Radix Switch использует useSize → ResizeObserver.
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

const todayKey = dateToKey(new Date());

const activeBooking: Appointment = {
    id: 'a1',
    client_name: 'Анна Активная',
    client_phone: null,
    client_avatar_url: null,
    service: 'Маникюр',
    time: '10:00',
    date: todayKey,
    duration: 60,
    price: 1000,
    status: AppointmentStatus.Booked,
    master_id: 'm1',
};

// Отменённая запись на то же время, что и действующая (N11).
const cancelledBooking: Appointment = {
    ...activeBooking,
    id: 'c1',
    client_name: 'Пётр Отменённый',
    status: AppointmentStatus.Cancelled,
};

const workingHours: WorkingHour[] = Array.from({ length: 7 }, (_, i) => ({
    user_id: 'm1',
    day_of_week: i,
    start_time: '09:00',
    end_time: '18:00',
    break_start_time: null,
    break_end_time: null,
    is_working: true,
}));

function pageProps(overrides: Record<string, unknown> = {}) {
    const rangeStart = new Date();
    rangeStart.setDate(rangeStart.getDate() - 30);
    const rangeEnd = new Date();
    rangeEnd.setDate(rangeEnd.getDate() + 30);

    return {
        appointments: [activeBooking, cancelledBooking],
        initialBlockedTimes: [],
        clients: [],
        services: [],
        slotInterval: 30,
        workingHours,
        timezoneConfirmed: true,
        timezone: 'UTC',
        auth: { user: { id: 'u1', name: 'Тест', tariff_code: 'start' } },
        masters: [{ id: 'm1', name: 'Мастер' }],
        dateRange: { start: dateToKey(rangeStart), end: dateToKey(rangeEnd) },
        ...overrides,
    };
}

function renderPage(overrides: Record<string, unknown> = {}) {
    mockUsePage.mockReturnValue({ props: pageProps(overrides) });

    return render(<CalendarPage />);
}

function getSwitch(): HTMLElement {
    return screen.getByRole('switch', { name: 'Отменённые записи' });
}

beforeEach(() => {
    fetchMock.mockReset();
    routerReload.mockReset();
    Object.keys(echoState.listeners).forEach((k) => {
        delete echoState.listeners[k];
    });
    vi.stubGlobal('fetch', fetchMock);
    localStorage.clear();

    fetchMock.mockResolvedValue(
        okJson({
            date: tzDateKey(new Date(), 'UTC'),
            timezone: 'UTC',
            appointments: [],
        }),
    );
});

describe('N11 — фильтр отменённых записей в сетке', () => {
    it('по умолчанию свитч выключен: отменённая запись скрыта, действующая на то же время видна', async () => {
        renderPage();

        expect(getSwitch()).toHaveAttribute('aria-checked', 'false');
        expect(screen.getByText('Анна Активная')).toBeInTheDocument();
        expect(screen.queryByText('Пётр Отменённый')).toBeNull();
    });

    it('включение показывает отменённую запись со статусным оформлением, выключение — скрывает', async () => {
        renderPage();

        fireEvent.click(getSwitch());

        const cancelledText = screen.getByText('Пётр Отменённый');
        expect(cancelledText).toBeInTheDocument();
        // Оформление «Отменён»: карточка получает line-through (AppointmentCard).
        expect(cancelledText.parentElement?.className).toContain(
            'line-through',
        );
        // Действующая запись на то же время остаётся видимой рядом.
        expect(screen.getByText('Анна Активная')).toBeInTheDocument();

        fireEvent.click(getSwitch());

        expect(screen.queryByText('Пётр Отменённый')).toBeNull();
        expect(screen.getByText('Анна Активная')).toBeInTheDocument();
    });

    it('фильтр работает во всех трёх режимах: Неделя, День, Месяц', async () => {
        renderPage();

        // Неделя (режим по умолчанию) — скрыта.
        expect(screen.queryByText('Пётр Отменённый')).toBeNull();

        fireEvent.click(getSwitch());

        // Неделя — видна.
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'Месяц' })[0]);
        expect(
            screen.getByText(`${cancelledBooking.time} — Пётр Отменённый`),
        ).toBeInTheDocument();

        // Выключение скрывает и в месяце.
        fireEvent.click(getSwitch());
        expect(
            screen.queryByText(`${cancelledBooking.time} — Пётр Отменённый`),
        ).toBeNull();
        expect(
            screen.getByText(`${activeBooking.time} — Анна Активная`),
        ).toBeInTheDocument();
    });

    it('после отмены (обновление props) запись скрывается при выключенном свитче и видна при включённом', async () => {
        const { rerender } = renderPage();

        // Действующая запись отменена: сервер вернул статус cancelled.
        mockUsePage.mockReturnValue({
            props: pageProps({
                appointments: [
                    { ...activeBooking, status: AppointmentStatus.Cancelled },
                    cancelledBooking,
                ],
            }),
        });
        rerender(<CalendarPage />);

        await waitFor(() => {
            expect(screen.queryByText('Анна Активная')).toBeNull();
        });

        // Свитч включён — отменённая запись отображается.
        fireEvent.click(getSwitch());
        expect(screen.getByText('Анна Активная')).toBeInTheDocument();
    });

    it('после отмены через WS запись скрывается при выключенном свитче', async () => {
        renderPage();
        expect(screen.getByText('Анна Активная')).toBeInTheDocument();

        act(() => {
            echoState.emit('.AppointmentStatusChanged', {
                id: 'a1',
                status: AppointmentStatus.Cancelled,
            });
        });

        await waitFor(() => {
            expect(screen.queryByText('Анна Активная')).toBeNull();
        });
    });

    it('свитч и кнопка «Свободные окна» (с бейджем «Профи») остаются в одной строке', () => {
        renderPage();

        const freeWindowsButton = screen.getByRole('button', {
            name: /Свободные окна/,
        });
        expect(
            within(freeWindowsButton).getByText('Профи'),
        ).toBeInTheDocument();

        const switchEl = getSwitch();
        const row = freeWindowsButton.parentElement;
        expect(row).not.toBeNull();
        expect(row).toContainElement(freeWindowsButton);
        expect(row).toContainElement(switchEl);
        // Строка не переносится: оба элемента — прямые потомки одного flex-ряда.
        expect(switchEl.closest('label')?.parentElement).toBe(row);
    });
});

describe('N11 — сохранение выбора в localStorage (отдельно для каждого мастера)', () => {
    const KEY_U1 = 'calendar_show_cancelled_u1';
    const KEY_U2 = 'calendar_show_cancelled_u2';

    it('выбор записывается в localStorage с ключом текущего мастера', () => {
        renderPage();

        fireEvent.click(getSwitch());
        expect(localStorage.getItem(KEY_U1)).toBe('1');

        fireEvent.click(getSwitch());
        expect(localStorage.getItem(KEY_U1)).toBe('0');
    });

    it('выбор восстанавливается после перезагрузки страницы', () => {
        localStorage.setItem(KEY_U1, '1');
        renderPage();

        expect(getSwitch()).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();
    });

    it('выбор хранится отдельно для каждого мастера', () => {
        localStorage.setItem(KEY_U1, '1');
        // Другой мастер (u2) — свой ключ, значение u1 не действует.
        renderPage({
            auth: { user: { id: 'u2', name: 'Другой', tariff_code: 'start' } },
        });

        expect(getSwitch()).toHaveAttribute('aria-checked', 'false');
        expect(screen.queryByText('Пётр Отменённый')).toBeNull();
        expect(localStorage.getItem(KEY_U2)).toBeNull();
    });

    it('выбор сохраняется при смене режима отображения', () => {
        renderPage();

        fireEvent.click(getSwitch());
        expect(localStorage.getItem(KEY_U1)).toBe('1');

        fireEvent.click(screen.getAllByRole('button', { name: 'Месяц' })[0]);
        expect(getSwitch()).toHaveAttribute('aria-checked', 'true');
        expect(localStorage.getItem(KEY_U1)).toBe('1');
        expect(
            screen.getByText(`${cancelledBooking.time} — Пётр Отменённый`),
        ).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);
        expect(getSwitch()).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();
    });

    it('выбор сохраняется при смене периода', async () => {
        // Загружен ровно текущий период: следующая неделя уже вне dateRange,
        // поэтому навигация обязан догрузить диапазон через router.reload.
        const week = getWeekDates(new Date());
        renderPage({
            dateRange: { start: dateToKey(week[0]), end: dateToKey(week[6]) },
        });

        fireEvent.click(getSwitch());
        expect(routerReload).not.toHaveBeenCalled();

        fireEvent.click(screen.getAllByLabelText('Следующий период')[0]);

        await waitFor(() => expect(routerReload).toHaveBeenCalled());
        expect(getSwitch()).toHaveAttribute('aria-checked', 'true');
        expect(localStorage.getItem(KEY_U1)).toBe('1');
    });

    it('без доступного localStorage свитч продолжает работать в памяти', () => {
        const getItemSpy = vi
            .spyOn(Storage.prototype, 'getItem')
            .mockImplementation(() => {
                throw new Error('denied');
            });
        const setItemSpy = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new Error('denied');
            });

        try {
            renderPage();

            // Восстановление не удалось — по умолчанию выключен, но без падения.
            expect(getSwitch()).toHaveAttribute('aria-checked', 'false');
            expect(screen.queryByText('Пётр Отменённый')).toBeNull();

            // Переключение работает в памяти.
            fireEvent.click(getSwitch());
            expect(getSwitch()).toHaveAttribute('aria-checked', 'true');
            expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();
        } finally {
            getItemSpy.mockRestore();
            setItemSpy.mockRestore();
        }
    });
});

describe('N11 — показ отменённых не влияет на доступность и счётчик', () => {
    it('WeekView получает полный localAppointments (hasCollision/DnD), рендер-списки — отфильтрованные', () => {
        const source = readSource('../pages/admin/calendar.tsx');

        // WeekView: hasCollision и DnD работают по полному списку.
        expect(source).toContain('localAppointments={localAppointments}');
        expect(source).toContain(
            'getAppointmentsForDay={getDisplayAppointmentsForDay}',
        );
        // DayView и MonthView получают только список отображения.
        expect(source).toContain('localAppointments={displayAppointments}');
        expect(source).toContain('appointments={displayAppointments}');
    });

    it('клик по свободному слоту открывает диалог новой записи при любом положении свитча', () => {
        renderPage();

        // Слот 11:00 (вне записей) в режиме «Неделя».
        const slot = document.getElementById(`${todayKey}__11:00`);
        expect(slot).not.toBeNull();

        fireEvent.click(slot as HTMLElement);
        // «Новая запись» есть и в шапке AdminLayout — сверяемся с заголовком диалога.
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));
        // Свитч включён — слот по-прежнему доступен.
        fireEvent.click(getSwitch());

        const slotAfter = document.getElementById(`${todayKey}__11:30`);
        expect(slotAfter).not.toBeNull();
        fireEvent.click(slotAfter as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
    });

    it('действующая запись на время отменённой открывается в карточке при любом положении свитча', () => {
        renderPage();

        // Свитч выключен: отменённая скрыта, действующая кликабельна.
        fireEvent.click(screen.getByText('Анна Активная'));
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Анна Активная')).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });

        // Свитч включён: обе записи видимы, действующая по-прежнему открывается.
        fireEvent.click(getSwitch());
        fireEvent.click(screen.getByText('Анна Активная'));
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Анна Активная')).toBeInTheDocument();
    });

    it('счётчик «Сегодня» не зависит от положения свитча', async () => {
        renderPage();

        const headers = await screen.findAllByText('Сегодня · 0 записей');
        expect(headers.length).toBeGreaterThan(0);

        const callsAfterMount = fetchMock.mock.calls.length;

        fireEvent.click(getSwitch());
        fireEvent.click(getSwitch());

        expect(
            screen.getAllByText('Сегодня · 0 записей').length,
        ).toBeGreaterThan(0);
        // Переключение не вызывает перезагрузку данных счётчика.
        expect(fetchMock.mock.calls.length).toBe(callsAfterMount);
        expect(routerReload).not.toHaveBeenCalled();
    });
});
