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

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        usePage: () => mockUsePage(),
        Link: ({ children }: { children: React.ReactNode }) => (
            <a>{children}</a>
        ),
        // «Живой» useForm: setData обновляет data через useState, иначе
        // предзаполнение формы (дата/время из слота) в тесте не наблюдаемо.
        useForm: (initial: Record<string, unknown> = {}) => {
            const [data, setDataState] =
                useState<Record<string, unknown>>(initial);

            const setData = (
                key: string | Record<string, unknown>,
                value?: unknown,
            ): void => {
                setDataState((prev) => {
                    if (typeof key === 'object') {
                        const next = { ...prev, ...key };

                        return Object.keys(next).every(
                            (k) => next[k] === prev[k],
                        )
                            ? prev
                            : next;
                    }

                    return prev[key] === value
                        ? prev
                        : { ...prev, [key]: value };
                });
            };

            return {
                data,
                setData,
                processing: false,
                errors: {},
                reset: () => setDataState(initial),
                clearErrors: vi.fn(),
                transform: vi.fn(),
                post: vi.fn(),
                patch: vi.fn(),
                put: vi.fn(),
                delete: vi.fn(),
                submit: vi.fn(),
            };
        },
        router: {
            reload: routerReload,
            patch: vi.fn(),
            post: vi.fn(),
            get: vi.fn(),
            visit: vi.fn(),
            on: vi.fn(() => vi.fn()),
        },
    };
});

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

// Значение поля «Время» в открытой форме новой записи (скрытый native select).
function readFormTime(dialog: HTMLElement): string {
    const label = Array.from(dialog.querySelectorAll('label')).find((l) =>
        l.textContent?.trim().startsWith('Время'),
    );

    return (
        (
            label?.parentElement?.querySelector(
                'select',
            ) as HTMLSelectElement | null
        )?.value ?? ''
    );
}

beforeEach(() => {
    fetchMock.mockReset();
    routerReload.mockReset();
    Object.keys(echoState.listeners).forEach((k) => {
        delete echoState.listeners[k];
    });
    vi.stubGlobal('fetch', fetchMock);
    localStorage.clear();

    fetchMock.mockImplementation(async (input: string) => {
        // Слоты для формы новой записи: валидация времени сверяет form.data.time
        // с этим списком, иначе предзаполненное время очищается.
        if (String(input).includes('/admin/calendar/available-slots')) {
            return okJson({
                freeSlots: [
                    '09:00',
                    '09:30',
                    '10:00',
                    '10:30',
                    '11:00',
                    '11:30',
                ],
                outsideSlots: [],
            });
        }

        return okJson({
            date: tzDateKey(new Date(), 'UTC'),
            timezone: 'UTC',
            appointments: [],
        });
    });
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

    it('отменённая карточка открывает детали, свободная зона того же слота — новую запись на 10:00', async () => {
        // На 10:00 только отменённая запись: слот фактически свободен.
        renderPage({ appointments: [cancelledBooking] });

        // ─── Неделя, свитч выключен: отменённая скрыта, слот открыт ───
        let slot = document.getElementById(`${todayKey}__10:00`);
        expect(slot).not.toBeNull();

        fireEvent.click(slot as HTMLElement);
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        // Прокрутить загрузку available-slots и валидацию времени в форме.
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));

        // ─── Неделя, свитч включён ───
        fireEvent.click(getSwitch());
        const card = screen.getByText('Пётр Отменённый').closest('button');
        expect(card).not.toBeNull();
        // Карточка интерактивна (мышь/касание/клавиатура) и занимает свою
        // колонку — вторая колонка слота остаётся свободной зоной.
        expect(card?.className).not.toContain('pointer-events-none');
        expect(card?.style.width).toBe('calc(50% - 12px)');

        // Клик по отменённой карточке — её детали.
        fireEvent.click(card as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        // Клик по свободной зоне того же слота — новая запись на 10:00.
        slot = document.getElementById(`${todayKey}__10:00`);
        expect(slot).not.toBeNull();
        fireEvent.click(slot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));

        // ─── День, свитч включён: та же пара зон ───
        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();

        const dayCard = screen.getByText('Пётр Отменённый').closest('button');
        expect(dayCard).not.toBeNull();
        expect(dayCard?.className).not.toContain('pointer-events-none');
        expect(dayCard?.style.width).toBe('calc(50% - 12px)');

        fireEvent.click(dayCard as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        const daySlot = document.getElementById(`${todayKey}__m1__10:00`);
        expect(daySlot).not.toBeNull();
        fireEvent.click(daySlot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
    });

    it('частичное пересечение: свободные 10:00 открывает форму, детали обеих — отдельно', async () => {
        // Отменённая 10:00–11:00, действующая 10:30–11:00.
        renderPage({
            appointments: [
                cancelledBooking,
                { ...activeBooking, time: '10:30', duration: 30 },
            ],
        });
        fireEvent.click(getSwitch());

        // Обе в своих колонках: правая (действующая) не закрывает ряд 10:00.
        const cancelledCard = screen
            .getByText('Пётр Отменённый')
            .closest('button');
        const activeCard = screen.getByText('Анна Активная').closest('button');
        expect(cancelledCard?.style.width).toBe('calc(50% - 12px)');
        expect(activeCard?.style.width).toBe('calc(50% - 12px)');
        expect(activeCard?.style.left).toBe('calc(50% + 6px)');

        // Детали отменённой.
        fireEvent.click(cancelledCard as HTMLElement);
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        // Детали действующей.
        fireEvent.click(activeCard as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Анна Активная')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        // Свободные 10:00 → форма с временем 10:00.
        const slot = document.getElementById(`${todayKey}__10:00`);
        expect(slot).not.toBeNull();
        fireEvent.click(slot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));

        // ─── День: те же проверки ───
        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);

        const dayCancelled = screen
            .getByText('Пётр Отменённый')
            .closest('button');
        const dayActive = screen.getByText('Анна Активная').closest('button');
        expect(dayCancelled?.style.width).toBe('calc(50% - 12px)');
        expect(dayActive?.style.width).toBe('calc(50% - 12px)');
        expect(dayActive?.style.left).toBe('calc(50% + 6px)');

        fireEvent.click(dayCancelled as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        fireEvent.click(dayActive as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Анна Активная')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        const daySlot = document.getElementById(`${todayKey}__m1__10:00`);
        expect(daySlot).not.toBeNull();
        fireEvent.click(daySlot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
    });

    it('две отменённые на одно время: обе карточки открываются, слот остаётся свободным', async () => {
        renderPage({
            appointments: [
                cancelledBooking,
                {
                    ...cancelledBooking,
                    id: 'c2',
                    client_name: 'Второй Отменённый',
                },
            ],
        });
        fireEvent.click(getSwitch());

        // Один маркер на кластер → 3 колонки, а не 4 отдельными маркерами.
        const card1 = screen.getByText('Пётр Отменённый').closest('button');
        const card2 = screen.getByText('Второй Отменённый').closest('button');
        expect(card1?.style.width).toContain('33.33');
        expect(card2?.style.width).toContain('33.33');

        // Обе открываются отдельно.
        fireEvent.click(card1 as HTMLElement);
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        expect(within(dialog).queryByText('Второй Отменённый')).toBeNull();
        fireEvent.keyDown(document, { key: 'Escape' });

        fireEvent.click(card2 as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(
            within(dialog).getByText('Второй Отменённый'),
        ).toBeInTheDocument();
        expect(within(dialog).queryByText('Пётр Отменённый')).toBeNull();
        fireEvent.keyDown(document, { key: 'Escape' });

        // Свободный слот → форма с временем 10:00.
        const slot = document.getElementById(`${todayKey}__10:00`);
        expect(slot).not.toBeNull();
        fireEvent.click(slot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));

        // ─── День: те же проверки ───
        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);

        const dayCard1 = screen.getByText('Пётр Отменённый').closest('button');
        const dayCard2 = screen
            .getByText('Второй Отменённый')
            .closest('button');
        expect(dayCard1?.style.width).toContain('33.33');
        expect(dayCard2?.style.width).toContain('33.33');

        fireEvent.click(dayCard1 as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        fireEvent.click(dayCard2 as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(
            within(dialog).getByText('Второй Отменённый'),
        ).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });

        const daySlot = document.getElementById(`${todayKey}__m1__10:00`);
        expect(daySlot).not.toBeNull();
        fireEvent.click(daySlot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:00');
    });

    it('раскладка кластера: на 10:45 три карточки оставляют отдельную зону слота', async () => {
        // Цепочка пересечений 10:00–10:30, 10:15–11:00, 10:30–11:00, 10:45–11:00.
        renderPage({
            appointments: [
                { ...cancelledBooking, id: 'c1', time: '10:00', duration: 30 },
                { ...cancelledBooking, id: 'c2', time: '10:15', duration: 45 },
                { ...cancelledBooking, id: 'c3', time: '10:30', duration: 30 },
                { ...cancelledBooking, id: 'c4', time: '10:45', duration: 15 },
            ],
        });
        fireEvent.click(getSwitch());

        const cardC1 = screen.getByText('10:00–10:30').closest('button');
        const cardC2 = screen.getByText('10:15–11:00').closest('button');
        const cardC3 = screen.getByText('10:30–11:00').closest('button');
        const cardC4 = screen.getByText('10:45–11:00').closest('button');

        // Маркер кластера держит колонку 0 весь интервал 10:00–11:00 → 4 колонки.
        [cardC1, cardC2, cardC3, cardC4].forEach((card) => {
            expect(card?.style.width).toBe('calc(25% - 12px)');
        });

        // На 10:45 видны три карточки — заняты колонки 1–3, колонка 0 свободна.
        const covering = [cardC2, cardC3, cardC4];
        const lefts = covering.map((card) => card?.style.left);
        expect(new Set(lefts).size).toBe(3);
        expect(lefts).not.toContain('calc(0% + 6px)');
        expect(cardC1?.style.left).toBe('calc(25% + 6px)');
        expect(cardC3?.style.left).toBe('calc(25% + 6px)');

        // Зона слота, содержащего 10:45 (строка 10:30), кликабельна.
        const slot = document.getElementById(`${todayKey}__10:30`);
        expect(slot).not.toBeNull();
        fireEvent.click(slot as HTMLElement);
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:30');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));

        // ─── День: та же раскладка ───
        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);

        const dayC2 = screen.getByText('10:15–11:00').closest('button');
        const dayC3 = screen.getByText('10:30–11:00').closest('button');
        const dayC4 = screen.getByText('10:45–11:00').closest('button');
        expect(dayC2?.style.width).toBe('calc(25% - 12px)');
        expect(dayC3?.style.width).toBe('calc(25% - 12px)');
        expect(dayC4?.style.width).toBe('calc(25% - 12px)');

        const dayLefts = [dayC2, dayC3, dayC4].map((card) => card?.style.left);
        expect(new Set(dayLefts).size).toBe(3);
        expect(dayLefts).not.toContain('calc(0% + 6px)');

        const daySlot = document.getElementById(`${todayKey}__m1__10:30`);
        expect(daySlot).not.toBeNull();
        fireEvent.click(daySlot as HTMLElement);
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(within(dialog).getByText('Новая запись')).toBeInTheDocument();
        await act(async () => {});
        expect(readFormTime(dialog)).toBe('10:30');
    });

    it('действующая запись на время отменённой открывается в карточке при любом положении свитча', async () => {
        renderPage();

        // Свитч выключен: отменённая скрыта, действующая кликабельна.
        fireEvent.click(screen.getByText('Анна Активная'));
        let dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Анна Активная')).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });

        // Свитч включён: обе карточки видимы и открываются отдельно.
        fireEvent.click(getSwitch());
        expect(screen.getByText('Анна Активная')).toBeInTheDocument();
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Анна Активная'));
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Анна Активная')).toBeInTheDocument();
        expect(within(dialog).queryByText('Пётр Отменённый')).toBeNull();

        fireEvent.keyDown(document, { key: 'Escape' });

        // Отменённая на то же время открывается своей карточкой.
        fireEvent.click(screen.getByText('Пётр Отменённый'));
        dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        expect(within(dialog).getByText('Пётр Отменённый')).toBeInTheDocument();
        expect(within(dialog).queryByText('Анна Активная')).toBeNull();

        fireEvent.keyDown(document, { key: 'Escape' });

        // Режим «День»: та же проверка при совпадении записей по времени.
        fireEvent.click(screen.getAllByRole('button', { name: 'День' })[0]);
        expect(screen.getByText('Пётр Отменённый')).toBeInTheDocument();

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
