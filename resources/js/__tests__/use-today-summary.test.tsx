import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useTodaySummary } from '@/hooks/useTodaySummary';
import { tzDateKey } from '@/pages/admin/components/calendar/helpers';
import { AppointmentStatus } from '@/types/appointment-status';

const echoState = vi.hoisted(() => {
    const listeners: Record<string, () => void> = {};
    const channel = {
        listen: (event: string, cb: () => void) => {
            listeners[event] = cb;

            return channel;
        },
        stopListening: vi.fn(() => channel),
    };

    return {
        listeners,
        channel,
        echo: () => ({ private: () => channel }),
    };
});

vi.mock('@/echo-config', () => ({ echo: echoState.echo }));

type FetchResponse = { ok: boolean; json: () => Promise<unknown> };
const fetchMock =
    vi.fn<(input: string, init?: RequestInit) => Promise<FetchResponse>>();

function okJson(data: unknown): FetchResponse {
    return { ok: true, json: async () => data };
}

function httpError(): FetchResponse {
    return { ok: false, json: async () => ({ message: 'error' }) };
}

function isoAt(offsetMs: number): string {
    return new Date(Date.now() + offsetMs).toISOString();
}

function payloadFor(
    date: string,
    counts: { past: number; upcoming: number },
): unknown {
    const visits: unknown[] = [];

    for (let i = 0; i < counts.past; i += 1) {
        visits.push({
            id: `past-${i}`,
            start_at: isoAt(-(5 - i) * 3600_000),
            end_at: isoAt(-(5 - i) * 3600_000 + 1800_000),
            status: AppointmentStatus.Paid,
        });
    }

    for (let i = 0; i < counts.upcoming; i += 1) {
        visits.push({
            id: `up-${i}`,
            start_at: isoAt((i + 1) * 3600_000),
            end_at: isoAt((i + 2) * 3600_000),
            status: AppointmentStatus.Booked,
        });
    }

    return { date, timezone: 'UTC', appointments: visits };
}

const today = () => tzDateKey(new Date(), 'UTC');

const mountParams = {
    enabled: true,
    timezone: 'UTC',
    authUserId: 'u1',
} as const;

beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);

    for (const key of Object.keys(echoState.listeners)) {
        delete echoState.listeners[key];
    }
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('useTodaySummary — независимый источник (N01)', () => {
    it('монтирование: GET /admin/calendar/today без параметров периода, header из снимка', async () => {
        fetchMock.mockResolvedValue(
            okJson(payloadFor(today(), { past: 3, upcoming: 2 })),
        );

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await waitFor(() =>
            expect(result.current.header).toBe(
                'Сегодня · 5 записей · осталось 2',
            ),
        );
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/admin/calendar/today');
    });

    it('enabled=false — без запроса и без header', () => {
        fetchMock.mockResolvedValue(
            okJson(payloadFor(today(), { past: 1, upcoming: 0 })),
        );

        const { result } = renderHook(() =>
            useTodaySummary({ ...mountParams, enabled: false }),
        );

        expect(fetchMock).not.toHaveBeenCalled();
        expect(result.current.header).toBeNull();
    });

    it('WS-событие о записи вне массива видимого периода обновляет счётчик', async () => {
        fetchMock
            .mockResolvedValueOnce(
                okJson(payloadFor(today(), { past: 4, upcoming: 1 })),
            )
            .mockResolvedValueOnce(
                okJson(payloadFor(today(), { past: 4, upcoming: 2 })),
            );

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await waitFor(() =>
            expect(result.current.header).toBe(
                'Сегодня · 5 записей · осталось 1',
            ),
        );

        // Событие пришло для записи, которой не было в первом снимке.
        await act(async () => {
            echoState.listeners['.AppointmentCreated']?.();
        });

        await waitFor(() =>
            expect(result.current.header).toBe(
                'Сегодня · 6 записей · осталось 2',
            ),
        );
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('устаревший ответ после нового события не применяется, применяется свежий', async () => {
        const resolvers: Array<(r: FetchResponse) => void> = [];
        fetchMock.mockImplementation(
            () => new Promise((resolve) => resolvers.push(resolve)),
        );

        const { result } = renderHook(() => useTodaySummary(mountParams));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        // Новое событие во время in-flight запроса.
        await act(async () => {
            echoState.listeners['.AppointmentStatusChanged']?.();
        });
        // Дополнительного fetch пока нет — он уйдёт дренажом после завершения.
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // Старый ответ (одна запись) приходит вторым по порядку применения — отбрасывается seq'ом.
        await act(async () => {
            resolvers[0](okJson(payloadFor(today(), { past: 1, upcoming: 0 })));
        });
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(result.current.header).toBeNull(); // снимок не применён

        await act(async () => {
            resolvers[1](okJson(payloadFor(today(), { past: 3, upcoming: 2 })));
        });
        await waitFor(() =>
            expect(result.current.header).toBe(
                'Сегодня · 5 записей · осталось 2',
            ),
        );
    });

    it('повторные события коалесцируются: дренаж один, снимок заменяется без двойного подсчёта', async () => {
        const resolvers: Array<(r: FetchResponse) => void> = [];
        fetchMock.mockImplementation(
            () => new Promise((resolve) => resolvers.push(resolve)),
        );

        const { result } = renderHook(() => useTodaySummary(mountParams));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await act(async () => {
            for (let i = 0; i < 5; i += 1) {
                echoState.listeners['.AppointmentCreated']?.();
            }
        });
        expect(fetchMock).toHaveBeenCalledTimes(1); // флаг pending, без параллельных запросов

        await act(async () => {
            resolvers[0](okJson(payloadFor(today(), { past: 3, upcoming: 2 })));
        });
        // Ровно один дренажный запрос на серию событий.
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        await act(async () => {
            resolvers[1](okJson(payloadFor(today(), { past: 3, upcoming: 2 })));
        });
        await waitFor(() =>
            expect(result.current.header).toBe(
                'Сегодня · 5 записей · осталось 2',
            ),
        );
        expect(result.current.summary?.total).toBe(5); // не 10 — снимок заменён, не прибавлен
    });

    it('возврат во вкладку перезагружает данные за сегодня', async () => {
        fetchMock.mockResolvedValue(
            okJson(payloadFor(today(), { past: 3, upcoming: 2 })),
        );

        const { result } = renderHook(() => useTodaySummary(mountParams));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
        });

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');
    });

    it('смена дня в таймзоне мастера (UTC) вызывает перезагрузку данных', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        // Ответы строятся лениво — метки «сегодня/завтра» считаются от system time на момент fetch.
        const respond = [
            () => payloadFor('2026-06-15', { past: 3, upcoming: 2 }),
            () => payloadFor('2026-06-16', { past: 1, upcoming: 1 }),
        ];
        let call = 0;
        fetchMock.mockImplementation(async () => {
            const data = respond[call]();
            call += 1;

            return okJson(data);
        });

        const { result } = renderHook(() => useTodaySummary(mountParams));

        // Флешим микрозадачи первого fetch (fake timers — waitFor здесь не работает).
        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');

        // Полночь в таймзоне мастера прошла; тик ≤30с ловит смену дня.
        vi.setSystemTime(new Date('2026-06-16T00:00:05Z'));
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30_000);
        });
        // Микрозадачи повторного fetch после эффектов тика.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current.header).toBe('Сегодня · 2 записи · осталось 1');
    });

    it('снимок за несегодняшнюю дату не резюмируется как «Сегодня»', async () => {
        fetchMock.mockResolvedValue(
            okJson(payloadFor('2000-01-01', { past: 5, upcoming: 0 })),
        );

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await act(async () => {
            await Promise.resolve();
        });

        expect(result.current.summary).toBeNull();
        expect(result.current.header).toBeNull();
    });

    it('первый запрос rejected → отложенный повтор → успех без WS и visibilitychange', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        fetchMock
            .mockRejectedValueOnce(new Error('network'))
            .mockResolvedValueOnce(
                okJson(payloadFor('2026-06-15', { past: 3, upcoming: 2 })),
            );

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(result.current.header).toBeNull();

        // Нет немедленного цикла: до задержки повтора второй запрос не уходит.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(14_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(result.current.header).toBeNull();

        // Отложенный повтор (15с) без WS-событий и visibilitychange.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');
    });

    it('первый запрос HTTP 500 → отложенный повтор → успех без WS и visibilitychange', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        fetchMock
            .mockResolvedValueOnce(httpError())
            .mockResolvedValueOnce(
                okJson(payloadFor('2026-06-15', { past: 1, upcoming: 1 })),
            );

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(result.current.header).toBeNull();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(15_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current.header).toBe('Сегодня · 2 записи · осталось 1');
    });

    it('сервер возвращает вчерашнюю дату: нет немедленного цикла, частота ограничена; затем актуальная дата восстанавливает заголовок', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        let call = 0;
        fetchMock.mockImplementation(async () => {
            call += 1;

            if (call <= 2) {
                // Сервер «застрял» на вчерашней дате.
                return okJson(
                    payloadFor('2026-06-14', { past: 5, upcoming: 0 }),
                );
            }

            return okJson(payloadFor('2026-06-15', { past: 3, upcoming: 2 }));
        });

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        // Вчерашний снимок не показывается как «Сегодня».
        expect(result.current.summary).toBeNull();
        expect(result.current.header).toBeNull();

        // Короткий интервал: немедленного цикла нет.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // Первое окно троттлинга даты (30с) — ровно один запрос, всё ещё вчера.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current.header).toBeNull();

        // Сервер отдаёт актуальную дату — заголовок восстанавливается.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');

        // Дальше авто-циклов нет: дата совпадает, тик только пересчитывает границы.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(60_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');
    });

    it('после размонтирования повторов нет, запоздалый ответ не применяется', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        let resolveFirst: ((r: FetchResponse) => void) | undefined;
        let rejectFirst: ((e: unknown) => void) | undefined;
        fetchMock.mockImplementation(
            () =>
                new Promise((resolve, reject) => {
                    resolveFirst = resolve;
                    rejectFirst = reject;
                }),
        );

        const { result, unmount } = renderHook(() =>
            useTodaySummary(mountParams),
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);

        unmount();

        // Успешный запоздалый ответ: seq инвалидирован, состояние не трогаем.
        await act(async () => {
            resolveFirst?.(
                okJson(payloadFor('2026-06-15', { past: 3, upcoming: 2 })),
            );
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(result.current.header).toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // Ошибка после размонтирования: отложенного повтора не будет.
        await act(async () => {
            rejectFirst?.(new Error('network'));
            await vi.advanceTimersByTimeAsync(60_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('после выключения повторов нет, запоздалый ответ не применяется', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        const resolvers: Array<(r: FetchResponse) => void> = [];
        fetchMock.mockImplementation(
            () => new Promise((resolve) => resolvers.push(resolve)),
        );

        const { result, rerender } = renderHook(
            ({ enabled }: { enabled: boolean }) =>
                useTodaySummary({ ...mountParams, enabled }),
            { initialProps: { enabled: true } },
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);

        rerender({ enabled: false });

        // Запоздалый ответ после выключения не применяется.
        await act(async () => {
            resolvers[0](
                okJson(payloadFor('2026-06-15', { past: 3, upcoming: 2 })),
            );
            await vi.advanceTimersByTimeAsync(60_000);
        });
        expect(result.current.header).toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('временная ошибка при уже успешном снимке: снимок сохраняется, отложенный повтор не крутится', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));

        fetchMock
            .mockResolvedValueOnce(
                okJson(payloadFor('2026-06-15', { past: 3, upcoming: 2 })),
            )
            .mockRejectedValueOnce(new Error('network'));

        const { result } = renderHook(() => useTodaySummary(mountParams));

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');

        // WS-событие → неудачный refresh: прежний снимок остаётся.
        await act(async () => {
            echoState.listeners['.AppointmentCreated']?.();
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');

        // Нет авто-повтора при наличии снимка.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(60_000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(result.current.header).toBe('Сегодня · 5 записей · осталось 2');
    });
});
