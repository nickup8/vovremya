import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import BillingPage from '@/pages/admin/billing';

const mockUsePage = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
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

const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/200glz8o7h/print';

function makeProps(overrides: Record<string, unknown> = {}) {
    return {
        props: {
            plans: [
                {
                    id: 1,
                    code: 'pro',
                    name: 'Профи',
                    price_monthly: 490,
                    max_appointments_per_month: null,
                    features: ['unlimited_appointments'],
                    prices: [1, 3, 6, 12].map((m) => ({
                        period_months: m,
                        base: 490 * m,
                        discount_percent: 0,
                        final: 490 * m,
                    })),
                },
            ],
            current: {
                tariff: 'pro',
                tariff_name: 'Профи',
                is_paid: true,
                expires_at: '2026-11-20T00:00:00Z',
                days_left: 27,
                auto_renew_enabled: true,
                renewal_period_months: 3,
            },
            auth: { user: { name: 'Test' } },
            ...overrides,
        },
    };
}

function renderAtWidth(node: React.ReactElement, width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
    window.dispatchEvent(new Event('resize'));

    return render(node);
}

describe('admin/billing.tsx — cabinet receipts section removed', () => {
    beforeEach(() => {
        mockUsePage.mockReset();
        window.history.replaceState(null, '', '/admin/billing');
    });

    it('renders no receipts section even when a legacy receipts prop is present', () => {
        // An old server (or cached props) may still send the list — the
        // page must not render it anymore.
        mockUsePage.mockReturnValue(
            makeProps({
                receipts: {
                    data: [
                        {
                            id: 'r-1',
                            status_label: 'Доступен в кабинете',
                            receipt_url: URL_A,
                            amount: 1470,
                            currency: 'RUB',
                            confirmed_at: '2026-10-20T12:00:00Z',
                            period_start: '2026-09-20T00:00:00Z',
                            period_end: '2026-10-20T00:00:00Z',
                            tariff_name: 'Профи',
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 5,
                    total: 1,
                },
            }),
        );

        renderAtWidth(<BillingPage />, 1280);

        expect(screen.queryByTestId('cabinet-receipts')).not.toBeInTheDocument();
        expect(screen.queryByTestId('cabinet-receipt-item')).not.toBeInTheDocument();
        expect(screen.queryByText('Чеки об оплате')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Открыть чек' })).not.toBeInTheDocument();
    });

    it('renders no receipts section on mobile without the prop', () => {
        mockUsePage.mockReturnValue(makeProps());

        renderAtWidth(<BillingPage />, 360);

        expect(screen.queryByTestId('cabinet-receipts')).not.toBeInTheDocument();
        expect(screen.queryByText('Чеки об оплате')).not.toBeInTheDocument();
    });

    it('keeps auto renewal block available on the page', () => {
        mockUsePage.mockReturnValue(makeProps());

        renderAtWidth(<BillingPage />, 1280);

        expect(screen.getByText('Автопродление включено')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Отключить автопродление' }),
        ).toBeInTheDocument();
    });
});
