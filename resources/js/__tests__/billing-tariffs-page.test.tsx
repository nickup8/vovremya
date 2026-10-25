import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import React from 'react';
import BillingPage from '@/pages/admin/billing';

const mockUsePage = vi.fn();
const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: { get: routerGet, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@/layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isAxiosError: () => false,
    },
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}));

const ALL_PRO_FEATURES = [
    'recurring_appointments',
    'free_windows',
    'slot_autofill',
    'channel_analytics',
];

// Ориентиры production-конфигурации: цены приходят сервером, тест их не хардкодит в коде страницы.
const DISCOUNT_PRICES = [
    { period_months: 1, base: 490, discount_percent: 0, final: 490 },
    { period_months: 3, base: 1470, discount_percent: 5, final: 1397 },
    { period_months: 6, base: 2940, discount_percent: 10, final: 2646 },
    { period_months: 12, base: 5880, discount_percent: 20, final: 4704 },
];

function makeProps({
    currentOverrides = {},
    features = ALL_PRO_FEATURES,
    prices = DISCOUNT_PRICES,
    receipts = null,
    paymentAttemptId = null,
}: {
    currentOverrides?: Record<string, unknown>;
    features?: string[];
    prices?: typeof DISCOUNT_PRICES;
    receipts?: unknown;
    paymentAttemptId?: string | null;
} = {}) {
    return {
        props: {
            plans: [
                {
                    id: 1,
                    code: 'pro',
                    name: 'Профи',
                    price_monthly: 490,
                    max_appointments_per_month: null,
                    features,
                    prices,
                },
            ],
            current: {
                tariff: 'start',
                tariff_name: 'Старт',
                is_paid: false,
                expires_at: null,
                days_left: 0,
                ...currentOverrides,
            },
            auth: { user: { name: 'Test' } },
            receipts,
            payment_attempt_id: paymentAttemptId,
        },
    };
}

const START_PROPS = () =>
    makeProps({
        currentOverrides: {
            tariff: 'start',
            tariff_name: 'Старт',
            is_paid: false,
            expires_at: null,
            days_left: 0,
        },
    });

const ACTIVE_PRO_PROPS = () =>
    makeProps({
        currentOverrides: {
            tariff: 'pro',
            tariff_name: 'Профи',
            is_paid: true,
            expires_at: '2026-12-01T00:00:00.000Z',
            days_left: 30,
            auto_renew_enabled: true,
            renewal_period_months: 3,
        },
    });

describe('admin/billing.tsx — «Старт»: current tariff and honest differences', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(START_PROPS());
        routerGet.mockReset();
    });

    it('shows the current «Старт» tariff compactly', () => {
        render(<BillingPage />);

        expect(screen.getByText('Тариф: Старт')).toBeTruthy();
        expect(screen.getByText('Текущий тариф')).toBeTruthy();
        expect(screen.getByText('Без ограничения количества записей')).toBeTruthy();
    });

    it('lists the free base without presenting it as a Pro exclusive', () => {
        render(<BillingPage />);

        expect(screen.getByText('Базовые возможности')).toBeTruthy();
        expect(screen.getByText(/Онлайн-запись без ограничения количества записей/u)).toBeTruthy();
        expect(screen.getByText(/Календарь, услуги и клиентская база/u)).toBeTruthy();
        expect(screen.getByText(/в том числе повторяющиеся/u)).toBeTruthy();
        expect(screen.getByText('Базовая аналитика')).toBeTruthy();
        expect(screen.getByText('Публичная страница записи')).toBeTruthy();
    });

    it('does not list shared capabilities as paid Pro advantages', () => {
        render(<BillingPage />);

        expect(screen.queryByText('Безлимит записей')).toBeNull();
        expect(screen.queryByText('Полная база клиентов')).toBeNull();
        expect(screen.queryByText('Базовая база клиентов')).toBeNull();
        expect(screen.queryByText('Что входит в Профи')).toBeNull();
    });

    it('shows the four real Pro differences with action-and-benefit copy', () => {
        render(<BillingPage />);

        expect(screen.getByText('Добавит «Профи»')).toBeTruthy();

        expect(screen.getByText('Повторяющиеся записи')).toBeTruthy();
        expect(screen.getByText('Свободные окна')).toBeTruthy();
        expect(screen.getByText('«Хочу раньше»')).toBeTruthy();
        expect(screen.getByText('Аналитика источников и ссылок')).toBeTruthy();

        // «Хочу раньше»: перенос только после согласия клиента — без
        // обещаний автоматической записи или рассылки.
        const earlier = screen.getByText(/просит время раньше своей записи/u);
        expect(earlier.textContent ?? '').toContain('только после его согласия');
    });

    it('offers «Подключить Профи», not «Продлить»', () => {
        render(<BillingPage />);

        expect(screen.getByText('Подключить Профи')).toBeTruthy();
        expect(screen.queryByText('Продлить Профи')).toBeNull();
    });

    it('hides shared capabilities from the Pro list when the plan lacks the feature codes', () => {
        mockUsePage.mockReturnValue(
            makeProps({
                features: ['recurring_appointments', 'slot_autofill', 'channel_analytics'],
            }),
        );
        render(<BillingPage />);

        expect(screen.getByText('Повторяющиеся записи')).toBeTruthy();
        expect(screen.queryByText('Свободные окна')).toBeNull();
    });
});

describe('admin/billing.tsx — period selection and payable total', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(START_PROPS());
        routerGet.mockReset();
    });

    it('defaults to 3 months and shows the full period total before checkout', () => {
        render(<BillingPage />);

        const totalLabel = screen.getByText(/К оплате за 3 месяца/u);
        const summaryGrid = totalLabel.parentElement?.parentElement;
        // Полная сумма периода заметнее месячного эквивалента.
        expect(summaryGrid?.textContent).toMatch(/1\s?397\s?₽/u);
        expect(summaryGrid?.textContent).toMatch(/≈\s?466\s?₽\s?\/ мес/u);
        expect(summaryGrid?.textContent).toMatch(/Экономия\s?73\s?₽/u);

        expect(
            screen.getByRole('button', { name: /Подключить Профи на 3 месяца — 1\s?397\s?₽/u }),
        ).toBeTruthy();
    });

    it('switching the period updates total, monthly equivalent and the CTA', () => {
        render(<BillingPage />);

        fireEvent.click(screen.getByText('12 мес'));

        const totalLabel = screen.getByText(/К оплате за 12 месяцев/u);
        const summaryGrid = totalLabel.parentElement?.parentElement;
        expect(summaryGrid?.textContent).toMatch(/4\s?704\s?₽/u);
        // Месячный эквивалент не подменяет полную стоимость периода.
        expect(summaryGrid?.textContent).toMatch(/≈\s?392\s?₽\s?\/ мес/u);
        expect(
            screen.getByRole('button', { name: /Подключить Профи на 12 месяцев — 4\s?704\s?₽/u }),
        ).toBeTruthy();
        expect(routerGet).not.toHaveBeenCalled();
    });

    it('checkout receives the selected period and nothing is charged from this page', () => {
        render(<BillingPage />);

        fireEvent.click(screen.getByText('6 мес'));
        fireEvent.click(screen.getByRole('button', { name: /Подключить Профи на 6 месяцев/u }));

        expect(routerGet).toHaveBeenCalledWith('/admin/billing/checkout?period_months=6');
    });
});

describe('admin/billing.tsx — active «Профи»: subscription status and renewal', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(ACTIVE_PRO_PROPS());
        routerGet.mockReset();
    });

    it('shows subscription status instead of a fresh upsell', () => {
        render(<BillingPage />);

        expect(screen.getByText('Профи активен до 1 декабря 2026')).toBeTruthy();
        expect(screen.getByText(/осталось 30 дн\./u)).toBeTruthy();
        expect(screen.queryByText('Тариф: Старт')).toBeNull();
    });

    it('offers renewal wording for the active plan', () => {
        render(<BillingPage />);

        expect(screen.getByText('Продлить Профи')).toBeTruthy();
        expect(screen.queryByText('Подключить Профи')).toBeNull();
    });

    it('keeps auto-renew management visible', () => {
        render(<BillingPage />);

        expect(screen.getByText('Автопродление включено')).toBeTruthy();
        expect(screen.getByText('Следующий период: каждые 3 мес.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Отключить автопродление' })).toBeTruthy();
    });

    it('pro extras are framed as already included, not as an upgrade', () => {
        render(<BillingPage />);

        expect(screen.getByText('Включено в «Профи»')).toBeTruthy();
        expect(screen.queryByText('Добавит «Профи»')).toBeNull();
    });
});

describe('admin/billing.tsx — pending payment and receipts survive the rework', () => {
    beforeEach(() => {
        routerGet.mockReset();
    });

    it('keeps the manual status re-check banner for an unfinished payment', () => {
        // Закрытый диалог не переоткрывается — баннер ручной проверки жив.
        window.history.replaceState(
            null,
            '',
            '/admin/billing?payment_attempt=pay_pending&payment_check=closed',
        );
        mockUsePage.mockReturnValue(
            makeProps({ currentOverrides: { tariff: 'pro', tariff_name: 'Профи', is_paid: true, expires_at: '2026-12-01T00:00:00.000Z' }, paymentAttemptId: 'pay_pending' }),
        );
        render(<BillingPage />);

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(screen.getByText('Статус платежа')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Проверить статус' })).toBeTruthy();
    });

    it('keeps the receipts section renderable on the new layout', () => {
        window.history.replaceState(null, '', '/admin/billing');
        mockUsePage.mockReturnValue(
            makeProps({
                currentOverrides: { tariff: 'pro', tariff_name: 'Профи', is_paid: true, expires_at: '2026-12-01T00:00:00.000Z' },
                receipts: {
                    data: [
                        {
                            id: 'r1',
                            status_label: 'Доступен в кабинете',
                            receipt_url: 'https://example.test/receipt/1',
                            amount: 1470,
                            currency: 'RUB',
                            confirmed_at: '2026-10-20T12:00:00Z',
                            period_start: '2026-09-20T00:00:00Z',
                            period_end: '2026-10-20T00:00:00Z',
                            tariff_name: 'Профи',
                        },
                    ],
                    current_page: 1,
                    last_page: 2,
                    per_page: 5,
                    total: 6,
                },
            }),
        );
        render(<BillingPage />);

        expect(screen.getByTestId('cabinet-receipts')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Открыть чек' })).toBeTruthy();
        expect(screen.getByText(/Страница 1 из 2/u)).toBeTruthy();
    });
});

describe('admin/billing.tsx — missing pro plan catalog', () => {
    beforeEach(() => {
        window.history.replaceState(null, '', '/admin/billing');
        routerGet.mockReset();
    });

    it('renders an honest unavailable state without price or dead CTA', () => {
        mockUsePage.mockReturnValue({
            props: {
                plans: [],
                current: {
                    tariff: 'start',
                    tariff_name: 'Старт',
                    is_paid: false,
                    expires_at: null,
                    days_left: 0,
                },
                auth: { user: { name: 'Test' } },
                receipts: null,
            },
        });
        render(<BillingPage />);

        expect(screen.getByText('Тариф: Старт')).toBeTruthy();
        expect(screen.getByText('Подключение «Профи»')).toBeTruthy();
        expect(screen.getByText(/нельзя подключить: прайс не загрузился/u)).toBeTruthy();

        // Без выдуманной цены и неработающей кнопки.
        expect(screen.queryByRole('button', { name: /Подключить/u })).toBeNull();
        expect(screen.queryByRole('button', { name: /Продлить/u })).toBeNull();
        expect(screen.queryByText(/₽/u)).toBeNull();
    });
});
