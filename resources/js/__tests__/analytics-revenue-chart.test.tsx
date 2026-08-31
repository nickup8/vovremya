import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import React from 'react';

/* ── Mocks ───────────────────────────────────────────── */

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

let mockProps: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: mockProps }),
    router: { get: routerGet, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

// AdminLayout — прозрачная обёртка, чтобы не тянуть Sidebar/topbar.
vi.mock('@/layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => React.createElement('div', null, children),
}));

vi.mock('@/components/admin/ChannelsAnalytics', () => ({
    ChannelsTab: () => null,
    TopChannelsBlock: () => null,
}));

import AnalyticsPage from '@/pages/admin/analytics';

/* ── Fixtures ────────────────────────────────────────── */

const baseMetrics = {
    revenue: 96200, total_visits: 21, avg_check: 3850, attendance_rate: 83,
    lost_revenue: 6900, cancelled_count: 2, no_show_count: 1,
    new_clients_count: 8, returning_clients_count: 11, first_visit_conversion: null,
    top_services: [{ name: 'Маникюр', count: 10, percentage: 100 }], utilization_percentage: 71,
};

const chartData = [
    { label: 'Пн', value: 12000, count: 3, percent: 58 },
    { label: 'Вт', value: 20000, count: 5, percent: 76 },
    { label: 'Чт', value: 30000, count: 8, percent: 92 },
];

function buildProps(overrides: Record<string, unknown> = {}) {
    return {
        metrics: baseMetrics,
        trends: { revenue: 8, avg_check: 320, utilization: 6 },
        prev_metrics: { revenue: 89000, avg_check: 3530, utilization: 65 },
        chartData,
        activePeriod: 'week',
        dateFrom: null,
        dateTo: null,
        auth: { user: { name: 'Тест' } },
        activeTab: 'overview',
        channels_feature: false,
        autofill_feature: false,
        ...overrides,
    };
}

/** Возвращает сам bar-элемент по началу его aria-label. */
function bar(labelPrefix: string): HTMLElement {
    return screen.getByLabelText(new RegExp(`^${labelPrefix}:`));
}

describe('Analytics · Revenue chart — интерактивность', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockProps = buildProps();
    });

    it('по умолчанию summary показывает агрегат периода и total count', () => {
        render(<AnalyticsPage />);
        // Итого = 12000+20000+30000 = 62 000 ₽
        expect(screen.getByText('62 000 ₽')).toBeInTheDocument();
        expect(screen.getByText(/Итого за период/)).toBeInTheDocument();
        expect(screen.getByText(/Записей: 16/)).toBeInTheDocument();
    });

    it('hover по bar переключает summary на выбранный point', () => {
        render(<AnalyticsPage />);
        fireEvent.mouseEnter(bar('Чт'));
        expect(screen.getByText('30 000 ₽')).toBeInTheDocument();
        expect(screen.getByText(/Записей: 8/)).toBeInTheDocument();
    });

    it('mouseleave графика возвращает summary к агрегату', () => {
        const { container } = render(<AnalyticsPage />);
        const target = bar('Вт');
        fireEvent.mouseEnter(target);
        expect(screen.getByText('20 000 ₽')).toBeInTheDocument();
        // mouseleave повешен на контейнер столбцов (2 уровня вверх от bar).
        fireEvent.mouseLeave(target.parentElement!.parentElement!);
        expect(screen.getByText('62 000 ₽')).toBeInTheDocument();
        expect(container).toBeTruthy();
    });

    it('click по bar выбирает point', () => {
        render(<AnalyticsPage />);
        fireEvent.click(bar('Пн'));
        // Summary показывает значение выбранного bucket (Пн = 12 000 ₽) и его count.
        expect(screen.getByText('12 000 ₽')).toBeInTheDocument();
        expect(screen.getByText(/Записей: 3/)).toBeInTheDocument();
    });

    it('при активном point остальные bars получают dimmed state', () => {
        render(<AnalyticsPage />);
        fireEvent.mouseEnter(bar('Чт'));
        expect(bar('Чт').getAttribute('data-active')).toBe('true');
        expect(bar('Пн').getAttribute('data-active')).toBe('false');
        expect(bar('Пн').className).toContain('opacity-25');
        expect(bar('Чт').className).toContain('bg-[var(--color-orange)]');
    });

    it('empty-state: при пустом chartData показывается заглушка', () => {
        mockProps = buildProps({ chartData: [] });
        render(<AnalyticsPage />);
        expect(screen.getByText('Нет данных за период')).toBeInTheDocument();
    });
});

describe('Analytics · Period controls', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockProps = buildProps();
    });

    it('клик по периоду вызывает router.get с нужными period/date params', () => {
        render(<AnalyticsPage />);
        fireEvent.click(screen.getByText('Месяц'));
        expect(routerGet).toHaveBeenCalledTimes(1);
        const [url, params] = routerGet.mock.calls[0];
        expect(url).toBe('/admin/analytics');
        expect((params as Record<string, unknown>).period).toBe('month');
        expect((params as Record<string, unknown>).date_from).toBeTruthy();
        expect((params as Record<string, unknown>).date_to).toBeTruthy();
    });

    it('вкладка по умолчанию — Обзор (KPI видны)', () => {
        render(<AnalyticsPage />);
        expect(screen.getByText('Средний чек')).toBeInTheDocument();
        expect(screen.getByText('Выручка по периодам')).toBeInTheDocument();
    });
});

describe('Analytics · Regression: stale periodOffset', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockProps = buildProps();
    });

    it('periodOffset сбрасывается в 0 при чистой навигации (dateFrom/dateTo = null)', () => {
        const { rerender } = render(<AnalyticsPage />);

        fireEvent.click(screen.getByLabelText('Предыдущий период'));

        mockProps = buildProps({
            activePeriod: 'week',
            dateFrom: '2026-08-24',
            dateTo: '2026-08-30',
        });
        rerender(<AnalyticsPage />);
        const prevWeekRange = screen.getByText(/—/).textContent;

        mockProps = buildProps({
            activePeriod: 'week',
            dateFrom: null,
            dateTo: null,
        });
        rerender(<AnalyticsPage />);

        const currentWeekRange = screen.getByText(/—/).textContent;
        expect(currentWeekRange).not.toBe(prevWeekRange);
    });

    it('periodOffset НЕ сбрасывается если dateFrom/dateTo присутствуют', () => {
        const { rerender } = render(<AnalyticsPage />);

        fireEvent.click(screen.getByLabelText('Предыдущий период'));
        const prevWeekRange = screen.getByText(/—/).textContent;

        mockProps = buildProps({
            activePeriod: 'week',
            dateFrom: '2026-08-24',
            dateTo: '2026-08-30',
        });
        rerender(<AnalyticsPage />);

        const rangeAfter = screen.getByText(/—/).textContent;
        expect(rangeAfter).toBe(prevWeekRange);
    });
});
