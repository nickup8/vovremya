import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
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
        get: vi.fn(),
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

vi.mock('qrcode.react', () => ({
    QRCodeSVG: ({
        value,
        size,
        title,
    }: {
        value: string;
        size: number;
        title?: string;
    }) => (
        <svg
            data-testid="sbp-qr"
            data-value={value}
            data-size={String(size)}
            aria-label={title}
        />
    ),
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

const SBP_CHECKOUT_RESPONSE = {
    data: {
        payment_method: 'sbp',
        payment_id: 'pay_1',
        sbp_payload: 'https://qr.nspk.ru/TESTPAYLOAD',
        subscription_id: 7,
        amount: 1323,
    },
};

/** SBP checkout до экрана ожидания (микротаски, без реальных таймеров). */
async function startSbpCheckout() {
    vi.mocked(axios.post).mockResolvedValue(SBP_CHECKOUT_RESPONSE);
    const view = render(<BillingCheckoutPage />);
    fireEvent.click(screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }));
    await act(async () => {});

    return view;
}

describe('admin/billing-checkout.tsx — own checkout page', () => {
    const originalLocation = window.location;

    beforeEach(() => {
        mockUsePage.mockReturnValue(makeProps());
        vi.mocked(axios.post).mockReset().mockResolvedValue({ data: {} });
        vi.mocked(axios.get)
            .mockReset()
            .mockResolvedValue({ data: { status: 'processing' } });
        vi.mocked(toast.error).mockClear();
        Object.defineProperty(window, 'location', {
            value: { href: '' },
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        vi.useRealTimers();
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
        // Mobile action is hidden on md+ (no QR there)
        expect(action.className).toContain('md:hidden');
        expect(screen.getByText('Ожидаем подтверждение оплаты')).toBeTruthy();
        expect(
            screen.getByText('Не закрывайте страницу до завершения оплаты'),
        ).toBeTruthy();
        // SBP CTA must not redirect away from IRSI (no T-Bank / checkout_url)
        expect(window.location.href).toBe('');
    });

    it('sbp desktop renders QR built from exact sbp_payload', async () => {
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

        const qr = await screen.findByTestId('sbp-qr');

        // QR encodes the backend payload verbatim — no external services
        expect(qr.getAttribute('data-value')).toBe(
            'https://qr.nspk.ru/TESTPAYLOAD',
        );
        expect(qr.getAttribute('data-size')).toBe('232');

        // QR lives inside the desktop-only wrapper (hidden on mobile)
        const desktopWrapper = qr.closest('[class*="md:block"]');
        expect(desktopWrapper).toBeTruthy();
        expect(desktopWrapper?.className).toContain('hidden');

        expect(
            screen.getByText(
                'Отсканируйте QR-код камерой телефона или в приложении банка',
            ),
        ).toBeTruthy();
        expect(screen.getByText('Сумма: 1 323 ₽')).toBeTruthy();

        // Secondary desktop link, not a primary CTA button
        const openSbp = screen.getByRole('link', { name: 'Открыть СБП' });
        expect(openSbp.getAttribute('href')).toBe(
            'https://qr.nspk.ru/TESTPAYLOAD',
        );
    });

    it('sbp pending never mentions polling that is not implemented yet', async () => {
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

        await screen.findByText('Ожидаем подтверждение оплаты');

        expect(
            screen.queryByText(/подтверждение появится на этой странице/),
        ).toBeNull();
        expect(window.location.href).toBe('');
    });

    // ── SBP status polling ──

    it('sbp checkout starts polling payment status every 2s', async () => {
        vi.useFakeTimers();

        await startSbpCheckout();

        expect(screen.getByText('Ожидаем подтверждение оплаты')).toBeTruthy();
        expect(axios.get).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(2000);
        });

        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_1',
        );
    });

    it('succeeded stops polling and shows success', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });

        await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        expect(screen.getByText('Профи активирован')).toBeTruthy();
        const back = screen.getByRole('link', { name: 'Вернуться к тарифам' });
        expect(back.getAttribute('href')).toBe('/admin/billing');
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        act(() => {
            vi.advanceTimersByTime(10000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('failed_terminal stops polling and shows failure', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal' },
        });

        await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата не прошла')).toBeTruthy();
        expect(
            screen.getByText(
                'Попробуйте снова или выберите другой способ оплаты',
            ),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Вернуться к тарифам' }),
        ).toBeTruthy();

        act(() => {
            vi.advanceTimersByTime(10000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('refunded shows neutral state and stops polling', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'refunded' },
        });

        await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Платёж возвращён')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Вернуться к тарифам' }),
        ).toBeTruthy();

        act(() => {
            vi.advanceTimersByTime(10000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('processing keeps polling and stays on waiting screen', async () => {
        vi.useFakeTimers();

        await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);
        expect(screen.getByText('Ожидаем подтверждение оплаты')).toBeTruthy();
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
    });

    it('polling request error keeps waiting without terminal failure', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockRejectedValue(new Error('network down'));

        await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.queryByText('Оплата не прошла')).toBeNull();
        expect(screen.getByText('Ожидаем подтверждение оплаты')).toBeTruthy();

        // Следующий тик продолжает polling
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);
    });

    it('unmount clears the polling interval', async () => {
        vi.useFakeTimers();

        const { unmount } = await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        unmount();

        act(() => {
            vi.advanceTimersByTime(10000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('card flow never polls payment status', async () => {
        vi.useFakeTimers();
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
        await act(async () => {});

        act(() => {
            vi.advanceTimersByTime(10000);
        });
        expect(axios.get).not.toHaveBeenCalled();
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

    // ── Never open another method's payment ──

    it('never opens СБП when card is selected', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: SBP_CHECKOUT_RESPONSE.data,
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );
        await act(async () => {});

        // No card→СБП substitution: no QR, no bank link, no redirect.
        expect(window.location.href).toBe('');
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Открыть приложение банка' }),
        ).toBeNull();
        expect(screen.queryByText('Ожидаем подтверждение оплаты')).toBeNull();

        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toContain(
            'Есть незавершённый платёж другим способом',
        );
        expect(toast.error).toHaveBeenCalledWith(
            'Есть незавершённый платёж другим способом. Сначала проверьте его статус',
        );
    });

    it('never redirects to a card checkout when СБП is selected', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                payment_method: 'card',
                checkout_url: 'https://securepay.tinkoff.ru/pay?paymentId=9',
            },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );
        await act(async () => {});

        // No СБП→card substitution: we stay on the page.
        expect(window.location.href).toBe('');

        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toContain(
            'Есть незавершённый платёж другим способом',
        );
    });

    it('shows the controlled 422 message in an always-visible alert', async () => {
        const message =
            'Есть незавершённый платёж другим способом. Сначала проверьте его статус';
        vi.mocked(axios.post).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: { errors: { payment_method: [message] } },
            },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toBe(message);
        expect(toast.error).toHaveBeenCalledWith(message);

        // Visible at every viewport: no responsive hiding on the alert.
        expect(alert.className).not.toContain('hidden');
        expect(alert.className).not.toContain('md:');
    });
});
