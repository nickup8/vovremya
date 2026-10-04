import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import axios from 'axios';
import { toast } from 'sonner';
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

function makeProps(currentOverrides: Record<string, unknown> = {}) {
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
                tariff: 'start',
                tariff_name: 'Старт',
                is_paid: false,
                expires_at: null,
                days_left: 0,
                ...currentOverrides,
            },
            auth: { user: { name: 'Test' } },
        },
    };
}

describe('admin/billing.tsx — CTA goes to own checkout', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(makeProps());
        routerGet.mockReset();
    });

    it('mobile «Продлить» opens own checkout with selected period', () => {
        render(<BillingPage />);

        fireEvent.click(screen.getByText('Продлить'));

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith('/admin/billing/checkout?period_months=3');
    });

    it('desktop CTA opens own checkout with selected period', () => {
        render(<BillingPage />);

        fireEvent.click(screen.getByRole('button', { name: /Продлить на 3 месяца — 1.470 ₽/u }));

        expect(routerGet).toHaveBeenCalledWith('/admin/billing/checkout?period_months=3');
    });

    it('passes the selected period to the checkout url', () => {
        render(<BillingPage />);

        fireEvent.click(screen.getByText('12 мес'));
        fireEvent.click(screen.getByText('Продлить'));

        expect(routerGet).toHaveBeenCalledWith('/admin/billing/checkout?period_months=12');
    });

    it('no longer opens the old confirmation modal', () => {
        render(<BillingPage />);

        fireEvent.click(screen.getByText('Продлить'));

        expect(screen.queryByText('Переход к оплате')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Продолжить' })).toBeNull();
    });
});

describe('admin/billing.tsx — auto renewal status & disable', () => {
    beforeEach(() => {
        vi.mocked(axios.post).mockReset().mockResolvedValue({ data: {} });
        vi.mocked(toast.success).mockClear();
        vi.mocked(toast.error).mockClear();
    });

    function renderWithEnabledAutoRenew() {
        mockUsePage.mockReturnValue(
            makeProps({
                tariff: 'pro',
                tariff_name: 'Профи',
                is_paid: true,
                expires_at: '2026-12-01T00:00:00.000Z',
                days_left: 30,
                auto_renew_enabled: true,
                renewal_period_months: 3,
            }),
        );
        render(<BillingPage />);
    }

    it('shows enabled state with period and disable button', () => {
        renderWithEnabledAutoRenew();

        expect(screen.getByText('Автопродление включено')).toBeTruthy();
        expect(screen.getByText('Следующий период: каждые 3 мес.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Отключить автопродление' })).toBeTruthy();
        expect(screen.queryByText('Отменить подписку')).toBeNull();
    });

    it('disable sends request, confirms period retention and hides the block', async () => {
        renderWithEnabledAutoRenew();

        fireEvent.click(screen.getByRole('button', { name: 'Отключить автопродление' }));

        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(axios.post).toHaveBeenCalledWith('/admin/billing/auto-renew/disable');

        await waitFor(() =>
            expect(toast.success).toHaveBeenCalledWith(
                'Автопродление отключено. Оплаченный период останется активным до даты окончания.',
            ),
        );

        await waitFor(() => expect(screen.queryByText('Автопродление включено')).toBeNull());
        expect(screen.queryByRole('button', { name: 'Отключить автопродление' })).toBeNull();
    });

    it('does not show status block or enable toggle when auto renew is off', () => {
        mockUsePage.mockReturnValue(
            makeProps({
                tariff: 'pro',
                tariff_name: 'Профи',
                is_paid: true,
                expires_at: '2026-12-01T00:00:00.000Z',
                days_left: 30,
                auto_renew_enabled: false,
                renewal_period_months: null,
            }),
        );
        render(<BillingPage />);

        expect(screen.queryByText('Автопродление включено')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Отключить автопродление' })).toBeNull();
        expect(screen.queryByText(/включить автопродление/i)).toBeNull();
    });
});
