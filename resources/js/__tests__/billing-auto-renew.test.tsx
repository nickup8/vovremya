import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import BillingPage from '@/pages/admin/billing';

const mockUsePage = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
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

function openModal() {
    fireEvent.click(screen.getByText('Продлить'));
    screen.getByText('Переход к оплате');
}

describe('admin/billing.tsx — auto renewal consent', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(makeProps());
        vi.mocked(axios.post).mockReset().mockResolvedValue({ data: {} });
    });

    it('shows unchecked consent checkbox with copy and offer link', () => {
        render(<BillingPage />);
        openModal();

        const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
        expect(checkbox.checked).toBe(false);

        expect(screen.getByText('Автоматически продлевать Профи каждые 3 мес.')).toBeTruthy();
        expect(
            screen.getByText(/После окончания оплаченного периода ИРСИ сможет автоматически списать стоимость следующего периода/),
        ).toBeTruthy();

        const link = screen.getByRole('link', { name: 'Условия автопродления' });
        expect(link.getAttribute('href')).toBe('/offer#auto-renew');
    });

    it('sends auto_renew=false when checkbox is left unchecked', async () => {
        render(<BillingPage />);
        openModal();

        fireEvent.click(screen.getByText('Продолжить'));

        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(axios.post).toHaveBeenCalledWith('/admin/checkout', {
            tariff_plan_id: 1,
            period_months: 3,
            auto_renew: false,
        });
    });

    it('sends auto_renew=true when checkbox is checked', async () => {
        render(<BillingPage />);
        openModal();

        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByText('Продолжить'));

        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(axios.post).toHaveBeenCalledWith('/admin/checkout', {
            tariff_plan_id: 1,
            period_months: 3,
            auto_renew: true,
        });
    });

    it('keeps checkbox value between modal reopenings', () => {
        render(<BillingPage />);
        openModal();

        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByText('Отмена'));

        openModal();
        const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
        expect(checkbox.checked).toBe(true);
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
