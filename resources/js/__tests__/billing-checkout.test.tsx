import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import BillingCheckoutPage from '@/pages/admin/billing-checkout';

const mockUsePage = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('@/layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isAxiosError: (err: unknown) =>
            Boolean(
                err &&
                typeof err === 'object' &&
                'isAxiosError' in err &&
                (err as { isAxiosError: boolean }).isAxiosError,
            ),
    },
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}));

function makeProps() {
    return {
        props: {
            plan: { id: 1, code: 'pro', name: 'Профи', price_monthly: 490 },
            period_months: 3,
            price: {
                base: 1470,
                discount_percent: 10,
                final: 1323,
                currency: 'RUB',
            },
            auth: { user: { name: 'Test' } },
        },
    };
}

describe('admin/billing-checkout.tsx — own checkout page', () => {
    const originalLocation = window.location;

    beforeEach(() => {
        mockUsePage.mockReturnValue(makeProps());
        vi.mocked(axios.post).mockReset().mockResolvedValue({ data: {} });
        vi.mocked(toast.error).mockClear();
        Object.defineProperty(window, 'location', {
            value: { href: '' },
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', {
            value: originalLocation,
            writable: true,
            configurable: true,
        });
    });

    it('renders period, amount, monthly equivalent and saving', () => {
        render(<BillingCheckoutPage />);

        expect(screen.getByText('Оплата Профи')).toBeTruthy();
        expect(screen.getByText('3 месяца')).toBeTruthy();
        expect(screen.getByText('1 323 ₽')).toBeTruthy();
        expect(screen.getByText('≈ 441 ₽ / мес')).toBeTruthy();
        expect(screen.getByText('147 ₽ (−10%)')).toBeTruthy();
    });

    it('renders both payment methods with SBP selected by default', () => {
        render(<BillingCheckoutPage />);

        const sbp = screen.getByRole('radio', { name: /СБП/ });
        const card = screen.getByRole('radio', { name: /Банковская карта/ });

        expect(sbp.getAttribute('aria-checked')).toBe('true');
        expect(card.getAttribute('aria-checked')).toBe('false');
        expect(
            screen.getByText('Оплата через приложение вашего банка'),
        ).toBeTruthy();
        expect(
            screen.getByText('Оплата на защищённой странице T-Bank'),
        ).toBeTruthy();
    });

    it('card is selectable', () => {
        render(<BillingCheckoutPage />);

        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );

        const sbp = screen.getByRole('radio', { name: /СБП/ });
        const card = screen.getByRole('radio', { name: /Банковская карта/ });

        expect(card.getAttribute('aria-checked')).toBe('true');
        expect(sbp.getAttribute('aria-checked')).toBe('false');
    });

    it('auto-renew is disabled for SBP with a notice', () => {
        render(<BillingCheckoutPage />);

        const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
        expect(checkbox.disabled).toBe(true);
        expect(checkbox.checked).toBe(false);
        expect(
            screen.getByText('Автопродление через СБП появится позже.'),
        ).toBeTruthy();
    });

    it('card keeps auto-renew enabled', () => {
        render(<BillingCheckoutPage />);

        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );

        const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
        expect(checkbox.disabled).toBe(false);
        expect(
            screen.queryByText('Автопродление через СБП появится позже.'),
        ).toBeNull();
    });

    it('back link goes to the billing page', () => {
        render(<BillingCheckoutPage />);

        const back = screen.getByRole('link', { name: /Назад к тарифам/ });
        expect(back.getAttribute('href')).toBe('/admin/billing');
    });

    it('CTA posts payment_method sbp by default', async () => {
        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/admin/checkout', {
                tariff_plan_id: 1,
                period_months: 3,
                auto_renew: false,
                payment_method: 'sbp',
            }),
        );
    });

    it('CTA posts card checkout payload and redirects to checkout_url', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                checkout_url: 'https://securepay.tinkoff.ru/pay?paymentId=1',
            },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/admin/checkout', {
                tariff_plan_id: 1,
                period_months: 3,
                auto_renew: false,
                payment_method: 'card',
            }),
        );
        await waitFor(() => {
            expect(window.location.href).toBe(
                'https://securepay.tinkoff.ru/pay?paymentId=1',
            );
        });
    });

    it('consent checkbox is unchecked by default and toggles auto_renew payload for card', async () => {
        render(<BillingCheckoutPage />);

        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );

        const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
        expect(checkbox.checked).toBe(false);

        fireEvent.click(checkbox);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/admin/checkout', {
                tariff_plan_id: 1,
                period_months: 3,
                auto_renew: true,
                payment_method: 'card',
            }),
        );
    });

    it('sbp response stays on IRSI checkout and exposes Open-bank action', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                payment_method: 'sbp',
                payment_id: 'pay_1',
                sbp_payload: 'https://qr.nspk.ru/TESTPAYLOAD',
                subscription_id: 7,
                amount: 1323,
            },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        const action = await screen.findByRole('link', {
            name: 'Открыть приложение банка',
        });
        expect(action.getAttribute('href')).toBe(
            'https://qr.nspk.ru/TESTPAYLOAD',
        );
        expect(screen.getByText('Ожидаем подтверждение оплаты')).toBeTruthy();
        expect(window.location.href).toBe('');
    });

    it('shows validation toast on 422', async () => {
        vi.mocked(axios.post).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: { errors: { tariff_plan_id: ['Цена не найдена'] } },
            },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith('Цена не найдена'),
        );
    });

    it('shows permission toast on 403', async () => {
        vi.mocked(axios.post).mockRejectedValue({
            isAxiosError: true,
            response: { status: 403 },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith('Недостаточно прав'),
        );
    });

    it('shows generic toast on other errors', async () => {
        vi.mocked(axios.post).mockRejectedValue({
            isAxiosError: true,
            response: { status: 500 },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith(
                'Ошибка при создании платежа',
            ),
        );
    });
});
