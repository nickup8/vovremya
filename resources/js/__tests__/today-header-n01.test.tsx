import { describe, it, expect } from 'vitest';
import {
    getTodaySummary,
    formatTodayHeader,
    pluralizeZapisi,
    tzDateKey,
    type TodayVisit,
} from '@/pages/admin/components/calendar/helpers';
import { AppointmentStatus } from '@/types/appointment-status';

function visit(
    overrides: Partial<TodayVisit> & Pick<TodayVisit, 'start' | 'end'>,
): TodayVisit {
    return {
        id: 'v1',
        status: AppointmentStatus.Booked,
        ...overrides,
    };
}

describe('pluralizeZapisi', () => {
    it.each([
        [0, 'записей'],
        [1, 'запись'],
        [2, 'записи'],
        [4, 'записи'],
        [5, 'записей'],
        [11, 'записей'],
        [12, 'записей'],
        [14, 'записей'],
        [21, 'запись'],
        [22, 'записи'],
        [111, 'записей'],
        [121, 'запись'],
        [122, 'записи'],
    ])('%i → %s', (n, word) => {
        expect(pluralizeZapisi(n)).toBe(word);
    });
});

describe('tzDateKey — день в таймзоне мастера, не браузера', () => {
    it('Pacific/Auckland: 11:00 UTC = ещё 15 июня, 12:00 UTC = уже 16 июня', () => {
        expect(
            tzDateKey(new Date('2026-06-15T11:00:00Z'), 'Pacific/Auckland'),
        ).toBe('2026-06-15');
        expect(
            tzDateKey(new Date('2026-06-15T12:00:00Z'), 'Pacific/Auckland'),
        ).toBe('2026-06-16');
    });

    it('Europe/Moscow: вчерашний UTC-день может быть уже «сегодня» у мастера', () => {
        expect(
            tzDateKey(new Date('2026-06-14T22:00:00Z'), 'Europe/Moscow'),
        ).toBe('2026-06-15');
        expect(
            tzDateKey(new Date('2026-06-14T20:59:00Z'), 'Europe/Moscow'),
        ).toBe('2026-06-14');
    });

    it('детерминирована независимо от системной таймзоны', () => {
        // Явный timeZone задаёт результат; локальная зона процесса не участвует.
        expect(
            tzDateKey(new Date('2026-01-01T00:00:00Z'), 'Pacific/Kiritimati'),
        ).toBe('2026-01-01');
        expect(tzDateKey(new Date('2026-01-01T00:00:00Z'), 'Etc/GMT+12')).toBe(
            '2025-12-31',
        );
    });
});

describe('getTodaySummary — правила N01 на абсолютных start/end', () => {
    it('total: всё с началом «сегодня», кроме Cancelled (сервер уже отфильтровал день)', () => {
        const visits = [
            visit({
                id: '1',
                start: 1000,
                end: 2000,
                status: AppointmentStatus.Booked,
            }),
            visit({
                id: '2',
                start: 1000,
                end: 2000,
                status: AppointmentStatus.Paid,
            }),
            visit({
                id: '3',
                start: 1000,
                end: 2000,
                status: AppointmentStatus.NoShow,
            }),
            visit({
                id: '4',
                start: 1000,
                end: 2000,
                status: AppointmentStatus.Prepaid,
            }),
            visit({
                id: '5',
                start: 1000,
                end: 2000,
                status: AppointmentStatus.Cancelled,
            }),
        ];

        expect(getTodaySummary(visits, 1500).total).toBe(4);
    });

    it('remaining: start > now, кроме Cancelled/Paid/NoShow; Prepaid считается', () => {
        const now = 5000;
        const visits = [
            visit({
                id: 'booked',
                start: now + 1,
                end: now + 100,
                status: AppointmentStatus.Booked,
            }),
            visit({
                id: 'pending',
                start: now + 1,
                end: now + 100,
                status: AppointmentStatus.PendingPayment,
            }),
            visit({
                id: 'prepaid',
                start: now + 1,
                end: now + 100,
                status: AppointmentStatus.Prepaid,
            }),
            visit({
                id: 'paid',
                start: now + 1,
                end: now + 100,
                status: AppointmentStatus.Paid,
            }),
            visit({
                id: 'noshow',
                start: now + 1,
                end: now + 100,
                status: AppointmentStatus.NoShow,
            }),
            visit({
                id: 'cancelled',
                start: now + 1,
                end: now + 100,
                status: AppointmentStatus.Cancelled,
            }),
        ];

        expect(getTodaySummary(visits, now).remaining).toBe(3);
    });

    it('текущий: start <= now < end, кроме Cancelled/NoShow; Paid не завершает визит досрочно', () => {
        const now = 5000;
        const visits = [
            visit({
                id: 'paid-now',
                start: 4000,
                end: 6000,
                status: AppointmentStatus.Paid,
            }),
            visit({
                id: 'booked-now',
                start: 4500,
                end: 5500,
                status: AppointmentStatus.Booked,
            }),
        ];
        expect(getTodaySummary(visits, now).hasCurrent).toBe(true);

        const blocked = [
            visit({
                id: 'noshow',
                start: 4000,
                end: 6000,
                status: AppointmentStatus.NoShow,
            }),
            visit({
                id: 'cancelled',
                start: 4000,
                end: 6000,
                status: AppointmentStatus.Cancelled,
            }),
        ];
        expect(getTodaySummary(blocked, now).hasCurrent).toBe(false);
    });

    it('граница начала: now == start — визит уже текущий', () => {
        const visits = [visit({ start: 5000, end: 6000 })];
        const summary = getTodaySummary(visits, 5000);

        expect(summary.hasCurrent).toBe(true);
        expect(summary.remaining).toBe(0);
        expect(formatTodayHeader(summary)).toBe(
            'Сегодня · 1 запись · последний визит на сегодня',
        );
    });

    it('за миллисекунду до старта — ещё предстоящий, не текущий', () => {
        const visits = [visit({ start: 5000, end: 6000 })];
        const summary = getTodaySummary(visits, 4999);

        expect(summary.hasCurrent).toBe(false);
        expect(summary.remaining).toBe(1);
        expect(formatTodayHeader(summary)).toBe(
            'Сегодня · 1 запись · осталось 1',
        );
    });

    it('граница окончания: now == end — визит уже не текущий', () => {
        const visits = [visit({ start: 5000, end: 6000 })];
        const summary = getTodaySummary(visits, 6000);

        expect(summary.hasCurrent).toBe(false);
        expect(summary.remaining).toBe(0);
        expect(formatTodayHeader(summary)).toBe(
            'Сегодня · 1 запись · на сегодня всё ✓',
        );
    });

    it('за миллисекунду до окончания — ещё текущий', () => {
        const visits = [visit({ start: 5000, end: 6000 })];
        expect(getTodaySummary(visits, 5999).hasCurrent).toBe(true);
    });

    it('переходы: осталось → текущий → всё ✓ при движении времени', () => {
        const visits = [visit({ start: 5000, end: 6000 })];

        expect(formatTodayHeader(getTodaySummary(visits, 4000))).toBe(
            'Сегодня · 1 запись · осталось 1',
        );
        expect(formatTodayHeader(getTodaySummary(visits, 5500))).toBe(
            'Сегодня · 1 запись · последний визит на сегодня',
        );
        expect(formatTodayHeader(getTodaySummary(visits, 6000))).toBe(
            'Сегодня · 1 запись · на сегодня всё ✓',
        );
    });
});

describe('formatTodayHeader — тексты ПК/мобильный (общая строка)', () => {
    it('total == 0 → «Сегодня · 0 записей» без суффикса', () => {
        expect(
            formatTodayHeader({ total: 0, remaining: 0, hasCurrent: false }),
        ).toBe('Сегодня · 0 записей');
    });

    it('remaining > 0 → «осталось N»', () => {
        expect(
            formatTodayHeader({ total: 5, remaining: 2, hasCurrent: false }),
        ).toBe('Сегодня · 5 записей · осталось 2');
    });

    it('remaining == 0 и hasCurrent → «последний визит на сегодня»', () => {
        expect(
            formatTodayHeader({ total: 5, remaining: 0, hasCurrent: true }),
        ).toBe('Сегодня · 5 записей · последний визит на сегодня');
    });

    it('remaining > 0 имеет приоритет над hasCurrent', () => {
        expect(
            formatTodayHeader({ total: 5, remaining: 1, hasCurrent: true }),
        ).toBe('Сегодня · 5 записей · осталось 1');
    });

    it('иначе при total > 0 → «на сегодня всё ✓»', () => {
        expect(
            formatTodayHeader({ total: 5, remaining: 0, hasCurrent: false }),
        ).toBe('Сегодня · 5 записей · на сегодня всё ✓');
    });

    it('склонение включает 11–14, 21, 22', () => {
        expect(
            formatTodayHeader({ total: 11, remaining: 0, hasCurrent: false }),
        ).toBe('Сегодня · 11 записей · на сегодня всё ✓');
        expect(
            formatTodayHeader({ total: 14, remaining: 0, hasCurrent: false }),
        ).toBe('Сегодня · 14 записей · на сегодня всё ✓');
        expect(
            formatTodayHeader({ total: 21, remaining: 0, hasCurrent: false }),
        ).toBe('Сегодня · 21 запись · на сегодня всё ✓');
        expect(
            formatTodayHeader({ total: 22, remaining: 0, hasCurrent: false }),
        ).toBe('Сегодня · 22 записи · на сегодня всё ✓');
    });
});
