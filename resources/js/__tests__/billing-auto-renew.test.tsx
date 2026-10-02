import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import axios from 'axios';
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

function makeProps() {
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
