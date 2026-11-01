import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { echo } from '@/echo-config';
import type { AppointmentStatus } from '@/types/appointment-status';
import {
    formatTodayHeader,
    getTodaySummary,
    tzDateKey,
} from '@/pages/admin/components/calendar/helpers';
import type {
    TodaySummary,
    TodayVisit,
} from '@/pages/admin/components/calendar/helpers';

interface TodayAppointmentDto {
    id: string;
    start_at: string;
    end_at: string;
    status: AppointmentStatus;
}

interface TodayResponse {
    date: string;
    timezone: string;
    appointments: TodayAppointmentDto[];
}

interface UseTodaySummaryParams {
    /** Включить счётчик: fetch, WS-события, тики. */
    enabled: boolean;
    /** Таймзона мастера (фолбэк до первого ответа сервера). */
    timezone?: string;
    /** id мастера — канал WS `App.Models.User.{id}`. */
    authUserId?: string;
}

export interface UseTodaySummaryResult {
    /** Готовая строка «Сегодня · N записей · …»; null — данных ещё нет. */
    header: string | null;
    summary: TodaySummary | null;
    /** Принудительная перезагрузка данных за сегодня. */
    refresh: () => void;
}

/** Задержка отложенного повтора после сетевой ошибки / non-2xx без снимка. */
const RETRY_DELAY_MS = 15_000;
/** Минимальный интервал авто-перезагрузки при рассинхроне даты payload/клиента. */
const DAY_MISMATCH_THROTTLE_MS = 30_000;

function toVisit(dto: TodayAppointmentDto): TodayVisit {
    return {
        id: dto.id,
        start: Date.parse(dto.start_at),
        end: Date.parse(dto.end_at),
        status: dto.status,
    };
}

/**
 * Независимый источник сегодняшних записей для счётчика N01.
 *
 * Данные приходят с `GET /admin/calendar/today` — полный набор за сегодня
 * по таймзоне мастера, без привязки к открытому периоду календаря.
 * Обновления: монтирование, вкладка снова видима, смена дня в таймзоне мастера,
 * WS-события (создание/статус/перенос/подтверждение — даже для записей вне
 * массива видимого периода). Границы визитов (start/end) пересчитываются
 * локально по таймеру к ближайшей границе — без серверных reload'ов.
 * Защита от устаревших ответов: seq-номер (применяется только последний),
 * повторные события во время запроса коалесцируются в один повторный fetch,
 * применение ответа заменяет снимок целиком — двойного подсчёта не бывает.
 * Ошибка первого запроса (сеть / non-2xx) без снимка: отложенный повтор
 * (не чаще RETRY_DELAY_MS), прежний успешный снимок при временной ошибке
 * не затирается. payload.date ≠ сегодня у клиента не показывается как
 * «Сегодня» и не крутит немедленный цикл запросов — авто-повтор даты
 * троттлится DAY_MISMATCH_THROTTLE_MS независимо от новизны объекта payload.
 * При выключении / размонтировании таймеры снимаются, in-flight ответ
 * инвалидируется seq'ом и не применяется.
 */
export function useTodaySummary({
    enabled,
    timezone = 'Europe/Moscow',
    authUserId,
}: UseTodaySummaryParams): UseTodaySummaryResult {
    const [payload, setPayload] = useState<TodayResponse | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const [retryToken, setRetryToken] = useState(0);

    const seqRef = useRef(0);
    const inflightRef = useRef(false);
    const pendingRef = useRef(false);
    const enabledRef = useRef(enabled);
    const payloadRef = useRef<TodayResponse | null>(null);
    const lastAutoFetchAtRef = useRef(0);

    useEffect(() => {
        enabledRef.current = enabled;
    }, [enabled]);

    const applyPayload = useCallback((data: TodayResponse) => {
        payloadRef.current = data;
        setPayload(data);
    }, []);

    const load = useCallback(() => {
        seqRef.current += 1;
        lastAutoFetchAtRef.current = Date.now();

        if (inflightRef.current) {
            // Повторное событие во время запроса: дренаж циклом после завершения.
            pendingRef.current = true;

            return;
        }

        inflightRef.current = true;

        void (async () => {
            try {
                for (;;) {
                    if (!enabledRef.current) {
                        break;
                    }

                    pendingRef.current = false;
                    const mySeq = seqRef.current;

                    try {
                        const res = await fetch('/admin/calendar/today', {
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        if (res.ok) {
                            const data = (await res.json()) as TodayResponse;

                            if (mySeq === seqRef.current) {
                                applyPayload(data);
                            }
                        } else if (mySeq === seqRef.current) {
                            // HTTP non-2xx: прежний снимок остаётся, отложенный повтор
                            setRetryToken((t) => t + 1);
                        }
                    } catch {
                        // Сетевая ошибка: прежний снимок остаётся, отложенный повтор
                        if (mySeq === seqRef.current) {
                            setRetryToken((t) => t + 1);
                        }
                    }

                    if (!pendingRef.current) {
                        break;
                    }
                }
            } finally {
                inflightRef.current = false;
            }
        })();
    }, [applyPayload]);

    const refresh = useCallback(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!enabled) {
            // Инвалидация in-flight: запоздалый ответ не будет применён
            seqRef.current += 1;

            return;
        }

        load();
    }, [enabled, load]);

    // Отложенный повтор после ошибки первого запроса, когда снимка ещё нет.
    // Ограниченная частота: один таймер на RETRY_DELAY_MS, без polling'а каждый секунду.
    useEffect(() => {
        if (!enabled || retryToken === 0 || payload !== null) {
            return;
        }

        const timer = window.setTimeout(() => {
            if (enabledRef.current && payloadRef.current === null) {
                load();
            }
        }, RETRY_DELAY_MS);

        return () => window.clearTimeout(timer);
    }, [retryToken, enabled, payload, load]);

    // Локальный пересчёт границ визитов: таймер к ближайшей границе start/end,
    // но не дальше 30с — так ловится смена дня в таймзоне мастера.
    useEffect(() => {
        if (!enabled || !payload) {
            return;
        }

        let next = now + 30_000;

        for (const dto of payload.appointments) {
            const s = Date.parse(dto.start_at);
            const e = Date.parse(dto.end_at);

            if (s > now && s < next) {
                next = s;
            }

            if (e > now && e < next) {
                next = e;
            }
        }

        const timer = window.setTimeout(
            () => setNow(Date.now()),
            Math.max(50, next - now),
        );

        return () => window.clearTimeout(timer);
    }, [enabled, payload, now]);

    // Смена дня в таймзоне мастера — перезагрузка данных за сегодня.
    // Троттлинг: немедленного цикла при payload.date ≠ сегодня нет,
    // частота ограничена независимо от замены объекта payload.
    useEffect(() => {
        if (!enabled || !payload) {
            return;
        }

        const key = tzDateKey(new Date(now), payload.timezone || timezone);

        if (key !== payload.date) {
            const nowMs = Date.now();

            if (
                nowMs - lastAutoFetchAtRef.current >=
                DAY_MISMATCH_THROTTLE_MS
            ) {
                void load();
            }
        }
    }, [enabled, payload, now, timezone, load]);

    // Возврат во вкладку — данные за сегодня могли устареть.
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const onVisibility = () => {
            if (document.visibilityState === 'visible') {
                setNow(Date.now());
                void load();
            }
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () =>
            document.removeEventListener('visibilitychange', onVisibility);
    }, [enabled, load]);

    // WS: создание/статус/перенос/подтверждение — обновляют счётчик, даже если
    // запись отсутствует в массиве видимого периода. Свой cleanup убирает только
    // свои обработчики (канал общий с useCalendarData).
    useEffect(() => {
        if (!enabled || !authUserId) {
            return;
        }

        const channel = echo<'reverb'>().private(
            `App.Models.User.${authUserId}`,
        );
        const onChanged = () => {
            void load();
        };

        channel
            .listen('.AppointmentCreated', onChanged)
            .listen('.AppointmentStatusChanged', onChanged)
            .listen('.AppointmentRescheduled', onChanged)
            .listen('.AppointmentVisitConfirmed', onChanged);

        return () => {
            channel.stopListening('.AppointmentCreated', onChanged);
            channel.stopListening('.AppointmentStatusChanged', onChanged);
            channel.stopListening('.AppointmentRescheduled', onChanged);
            channel.stopListening('.AppointmentVisitConfirmed', onChanged);
        };
    }, [enabled, authUserId, load]);

    // Размонтирование: инвалидация in-flight ответа.
    useEffect(() => {
        return () => {
            seqRef.current += 1;
        };
    }, []);

    const summary = useMemo<TodaySummary | null>(() => {
        if (!payload) {
            return null;
        }

        const tz = payload.timezone || timezone;

        if (tzDateKey(new Date(now), tz) !== payload.date) {
            return null; // снимок за «вчера» — не показываем как «Сегодня»
        }

        return getTodaySummary(payload.appointments.map(toVisit), now);
    }, [payload, now, timezone]);

    const header = useMemo(
        () => (summary ? formatTodayHeader(summary) : null),
        [summary],
    );

    return { header, summary, refresh };
}
