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
const { mockRouter } = vi.hoisted(() => ({
    mockRouter: {
        get: vi.fn(),
        reload: vi.fn(),
        post: vi.fn(),
        visit: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: mockRouter,
    Link: ({
        href,
        children,
        className,
    }: {
        href: string;
        children: React.ReactNode;
        className?: string;
    }) => (
        <a href={href} className={className}>
            {children}
        </a>
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

function makeProps(overrides: Record<string, unknown> = {}) {
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
            ...overrides,
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

const EXPIRED_TITLE = 'Срок ссылки истёк. Проверяем результат оплаты';
const UNCONFIRMED_TITLE = 'Пока не удалось подтвердить оплату';

/** ISO-дедлайн через ms от «сейчас» (отрицательный = уже истёк). */
function isoIn(ms: number): string {
    return new Date(Date.now() + ms).toISOString();
}

/** SBP checkout с ответом, несущим sbp_expires_at (бэкенд-контракт PR10.1). */
async function startSbpCheckoutWithExpiry(expiresAt: string | null) {
    vi.mocked(axios.post).mockResolvedValue({
        data: { ...SBP_CHECKOUT_RESPONSE.data, sbp_expires_at: expiresAt },
    });
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
        vi.mocked(mockRouter.get).mockReset();
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
        // Полный возврат отличим от частичного
        expect(screen.queryByText('Частичный возврат')).toBeNull();
        expect(screen.queryByText('Возвращена часть платежа')).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Вернуться к тарифам' }),
        ).toBeTruthy();

        // Не success-экран и без кнопок новой оплаты
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(screen.queryByText('Профи активирован')).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Выбрать способ оплаты' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Проверить статус' }),
        ).toBeNull();
        // Ни о подписке, ни об активации ничего не утверждаем
        expect(screen.queryByText(/подписк/i)).toBeNull();

        act(() => {
            vi.advanceTimersByTime(10000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('partially_refunded shows its own copy and stops polling', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'partially_refunded' },
        });

        await startSbpCheckout();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Частичный возврат')).toBeTruthy();
        expect(screen.getByText('Возвращена часть платежа')).toBeTruthy();
        // Частичный возврат отличим от полного
        expect(screen.queryByText('Платёж возвращён')).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Вернуться к тарифам' }),
        ).toBeTruthy();

        // Не success-экран и без кнопок новой оплаты
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(screen.queryByText('Профи активирован')).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Выбрать способ оплаты' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Проверить статус' }),
        ).toBeNull();
        // Ни о подписке, ни об активации ничего не утверждаем
        expect(screen.queryByText(/подписк/i)).toBeNull();

        // polling остановлен
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

    // ── SBP link lifetime (RedirectDueDate) ──

    it('countdown is computed from the absolute server deadline', async () => {
        vi.useFakeTimers();

        await startSbpCheckoutWithExpiry(isoIn(5 * 60_000));

        const countdown = screen.getByTestId('sbp-countdown');
        // 5 минут до дедлайна — а не «новые 15 минут» от открытия страницы.
        expect(countdown.textContent).toContain('05:00');
        expect(countdown.textContent).not.toContain('15:00');

        act(() => {
            vi.advanceTimersByTime(61_000);
        });

        expect(screen.getByTestId('sbp-countdown').textContent).toContain(
            '03:59',
        );
        expect(screen.getByTestId('sbp-qr')).toBeTruthy();
    });

    it('expiry removes the QR and bank links and checks the same payment', async () => {
        vi.useFakeTimers();

        await startSbpCheckoutWithExpiry(isoIn(3000));
        expect(screen.getByTestId('sbp-qr')).toBeTruthy();

        act(() => {
            vi.advanceTimersByTime(3000);
        });
        await act(async () => {});

        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(screen.queryByTestId('sbp-countdown')).toBeNull();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Открыть приложение банка' }),
        ).toBeNull();
        expect(screen.queryByRole('link', { name: 'Открыть СБП' })).toBeNull();
        expect(screen.queryByText('Ожидаем подтверждение оплаты')).toBeNull();
        // Новую оплату не запускаем
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        // Статус проверяется по этому же payment_id через существующий endpoint
        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_1',
        );
    });

    it('returning to the tab after the deadline expires the link', async () => {
        vi.useFakeTimers();

        const deadline = isoIn(90_000);
        await startSbpCheckoutWithExpiry(deadline);
        expect(screen.getByTestId('sbp-countdown').textContent).toContain(
            '01:30',
        );

        // Часы ушли, пока вкладка была скрыта: тики интервала не срабатывали
        vi.setSystemTime(new Date(Date.parse(deadline) + 1000));
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        await act(async () => {});

        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(screen.queryByRole('link', { name: 'Открыть СБП' })).toBeNull();
    });

    it('success wins over an expired link', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        expect(screen.queryByText(EXPIRED_TITLE)).toBeNull();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Вернуться к тарифам' }),
        ).toBeTruthy();
    });

    it('confirmed decline after expiry still shows the failure screen', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal' },
        });

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата не прошла')).toBeTruthy();
        expect(screen.queryByText(UNCONFIRMED_TITLE)).toBeNull();
    });

    it('network error after expiry → unconfirmed, «Проверить статус» re-checks', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockRejectedValue(new Error('network down'));

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(screen.queryByText(EXPIRED_TITLE)).toBeNull();
        // Без обещания новой оплаты без подтверждённого исхода
        expect(screen.queryByText(/Попробуйте снова/)).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();

        // Автоматический polling остановлен — запросов больше нет
        const calls = vi.mocked(axios.get).mock.calls.length;
        act(() => {
            vi.advanceTimersByTime(10_000);
        });
        expect(vi.mocked(axios.get).mock.calls.length).toBe(calls);

        // Ручная проверка возобновляет опрос того же payment_id
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});

        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(vi.mocked(axios.get).mock.calls.length).toBeGreaterThan(calls);
        expect(vi.mocked(axios.get).mock.calls.at(-1)).toEqual([
            '/admin/billing/payment-status/pay_1',
        ]);
    });

    it('unknown outcome after expiry stays unconfirmed', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'unknown' },
        });

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
    });

    it('reconciliation_timeout after expiry is not a decline', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal', undefined_outcome: true },
        });

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
    });

    it('reconciliation_timeout before the deadline stays unconfirmed', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal', undefined_outcome: true },
        });

        await startSbpCheckoutWithExpiry(isoIn(5 * 60_000));

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        // Локальный age-release — не отказ банка до дедлайна тоже
        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
        expect(screen.queryByText(/Попробуйте снова/)).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Проверить статус' }),
        ).toBeTruthy();

        // Авто-проверка остановлена
        act(() => {
            vi.advanceTimersByTime(10_000);
        });
        expect(vi.mocked(axios.get).mock.calls.length).toBe(1);
    });

    it('reconciliation_timeout with deadline=null stays unconfirmed and manual check re-checks at once', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal', undefined_outcome: true },
        });

        await startSbpCheckoutWithExpiry(null);

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
        expect(screen.queryByTestId('sbp-countdown')).toBeNull();

        // Ручная проверка без дедлайна тоже начинается немедленно
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});

        expect(vi.mocked(axios.get).mock.calls.length).toBe(2);
        expect(screen.getByText('Ожидаем подтверждение оплаты')).toBeTruthy();
    });

    it('processing after the deadline exhausts the 25-request budget', async () => {
        vi.useFakeTimers();

        await startSbpCheckoutWithExpiry(isoIn(1000));

        // Истечение → немедленный запрос нового цикла
        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});
        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(vi.mocked(axios.get).mock.calls.length).toBe(1);

        // 24 тика интервала по 2 c → всего 25 запросов бюджета
        for (let i = 0; i < 24; i++) {
            act(() => {
                vi.advanceTimersByTime(2000);
            });
            await act(async () => {});
        }

        expect(vi.mocked(axios.get).mock.calls.length).toBe(25);
        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();

        // Больше автоматических запросов не уходит
        act(() => {
            vi.advanceTimersByTime(30_000);
        });
        await act(async () => {});
        expect(vi.mocked(axios.get).mock.calls.length).toBe(25);
    });

    it('manual check starts a new bounded cycle after the budget', async () => {
        vi.useFakeTimers();

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        for (let i = 0; i < 24; i++) {
            act(() => {
                vi.advanceTimersByTime(2000);
            });
            await act(async () => {});
        }

        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(vi.mocked(axios.get).mock.calls.length).toBe(25);

        // Новый цикл: немедленный запрос, снова без параллельных
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});

        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(vi.mocked(axios.get).mock.calls.length).toBe(26);

        // И снова максимум 25 запросов в цикле (26…50)
        for (let i = 0; i < 24; i++) {
            act(() => {
                vi.advanceTimersByTime(2000);
            });
            await act(async () => {});
        }

        expect(vi.mocked(axios.get).mock.calls.length).toBe(50);
        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
    });

    it('success inside the post-expiry budget wins and stops polling', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get)
            .mockResolvedValueOnce({ data: { status: 'processing' } })
            .mockResolvedValueOnce({ data: { status: 'processing' } })
            .mockResolvedValue({ data: { status: 'succeeded' } });

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});
        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        expect(screen.queryByText(UNCONFIRMED_TITLE)).toBeNull();

        const total = vi.mocked(axios.get).mock.calls.length;
        expect(total).toBeLessThan(25);

        act(() => {
            vi.advanceTimersByTime(30_000);
        });
        expect(vi.mocked(axios.get).mock.calls.length).toBe(total);
    });

    it('old attempt without a deadline shows no timer and never expires', async () => {
        vi.useFakeTimers();

        await startSbpCheckoutWithExpiry(null);

        expect(screen.queryByTestId('sbp-countdown')).toBeNull();

        act(() => {
            vi.advanceTimersByTime(16 * 60_000);
        });
        await act(async () => {});

        expect(screen.queryByText(EXPIRED_TITLE)).toBeNull();
        expect(screen.getByTestId('sbp-qr')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Открыть приложение банка' }),
        ).toBeTruthy();
    });

    it('an already-expired deadline never restarts the 15 minutes', async () => {
        vi.useFakeTimers();

        // Reuse попытки возвращает исходный (прошедший) срок
        await startSbpCheckoutWithExpiry(isoIn(-1000));
        await act(async () => {});

        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(screen.queryByTestId('sbp-countdown')).toBeNull();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
    });

    it('unmount clears the countdown and polling timers and the listener', async () => {
        vi.useFakeTimers();
        const removeSpy = vi.spyOn(document, 'removeEventListener');

        const { unmount } = await startSbpCheckoutWithExpiry(isoIn(60_000));

        expect(screen.getByTestId('sbp-countdown')).toBeTruthy();
        const callsBefore = vi.mocked(axios.get).mock.calls.length;

        unmount();

        expect(removeSpy).toHaveBeenCalledWith(
            'visibilitychange',
            expect.any(Function),
        );
        expect(vi.getTimerCount()).toBe(0);

        act(() => {
            vi.advanceTimersByTime(60_000);
        });
        expect(vi.mocked(axios.get).mock.calls.length).toBe(callsBefore);

        removeSpy.mockRestore();
    });

    // ── Confirmed failure → «Выбрать способ оплаты» ──

    const CHOOSE_METHOD = 'Выбрать способ оплаты';

    /** Подтверждённый failed: GET pay_1 → экран отказа. */
    async function reachConfirmedFailure() {
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
    }

    it('confirmed failure shows the primary method button with a secondary return link', async () => {
        await reachConfirmedFailure();

        const primary = screen.getByRole('button', {
            name: CHOOSE_METHOD,
        });
        expect(primary.className).toContain('bg-[var(--color-orange)]');

        // Ссылка возврата остаётся вторичной (обводка, не заливка)
        const back = screen.getByRole('link', {
            name: 'Вернуться к тарифам',
        });
        expect(back.getAttribute('href')).toBe('/admin/billing');
        expect(back.className).toContain('border-[var(--color-line)]');
        expect(back.className).not.toContain('bg-[var(--color-orange)]');
    });

    it('choosing a method after failure restores the form without POST', async () => {
        await reachConfirmedFailure();

        const postsBefore = vi.mocked(axios.post).mock.calls.length;
        expect(postsBefore).toBe(1);
        const getsBefore = vi.mocked(axios.get).mock.calls.length;

        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));
        await act(async () => {});

        // Форма на этой же странице: тариф/период/цена сохранены
        expect(screen.getByText('Оплата Профи')).toBeTruthy();
        expect(screen.getByText('3 месяца')).toBeTruthy();
        expect(screen.getByText('1 323 ₽')).toBeTruthy();

        // СБП по умолчанию, autoRenew=false
        expect(
            screen
                .getByRole('radio', { name: /СБП/ })
                .getAttribute('aria-checked'),
        ).toBe('true');
        const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
        expect(checkbox.checked).toBe(false);
        expect(checkbox.disabled).toBe(true);

        // Состояние старой попытки сброшено: ни экрана, ни ошибки, ни QR/таймера
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(screen.queryByTestId('sbp-countdown')).toBeNull();

        // Клик не делал POST / Init / автоплатёж и не трогал статус-опрос
        expect(vi.mocked(axios.post).mock.calls.length).toBe(postsBefore);
        expect(vi.mocked(axios.get).mock.calls.length).toBe(getsBefore);
        expect(window.location.href).toBe('');

        // Следующий явный клик «Оплатить» использует существующий handleCheckout
        vi.mocked(axios.post).mockResolvedValue(SBP_CHECKOUT_RESPONSE);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );
        await act(async () => {});

        expect(vi.mocked(axios.post)).toHaveBeenLastCalledWith(
            '/admin/checkout',
            {
                tariff_plan_id: 1,
                period_months: 3,
                auto_renew: false,
                payment_method: 'sbp',
            },
        );
        expect(screen.getByTestId('sbp-qr')).toBeTruthy();
    });

    it('a fresh SBP attempt after failure gets a new paymentId, QR and timer', async () => {
        await reachConfirmedFailure();

        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));
        await act(async () => {});

        // Все GET после сброса относятся только к новой попытке
        const callsAtReset = vi.mocked(axios.get).mock.calls.length;
        const oldPollingCalls = vi.mocked(axios.get).mock.calls.slice();
        expect(
            oldPollingCalls.every((call) => String(call[0]).includes('pay_1')),
        ).toBe(true);

        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                ...SBP_CHECKOUT_RESPONSE.data,
                payment_id: 'pay_2',
                sbp_payload: 'https://qr.nspk.ru/NEWATTEMPT',
                sbp_expires_at: isoIn(60_000),
            },
        });

        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );
        await act(async () => {});

        expect(vi.mocked(axios.post).mock.calls.length).toBe(2);

        // Новый QR
        expect(screen.getByTestId('sbp-qr').getAttribute('data-value')).toBe(
            'https://qr.nspk.ru/NEWATTEMPT',
        );

        // Новый таймер начинается от нового ответа
        expect(screen.getByTestId('sbp-countdown').textContent).toContain(
            '01:00',
        );

        // Новый paymentId опрашивается, старый — больше нет
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        const newCalls = vi.mocked(axios.get).mock.calls.slice(callsAtReset);
        expect(newCalls.length).toBeGreaterThan(0);
        expect(newCalls.at(-1)).toEqual([
            '/admin/billing/payment-status/pay_2',
        ]);
        expect(newCalls.some((call) => String(call[0]).includes('pay_1'))).toBe(
            false,
        );
    });

    it('choosing card after failure posts card and opens checkout_url', async () => {
        await reachConfirmedFailure();

        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));
        await act(async () => {});

        vi.mocked(axios.post).mockResolvedValue({
            data: {
                checkout_url: 'https://securepay.tinkoff.ru/pay?paymentId=9',
            },
        });

        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );
        await act(async () => {});

        expect(vi.mocked(axios.post)).toHaveBeenLastCalledWith(
            '/admin/checkout',
            {
                tariff_plan_id: 1,
                period_months: 3,
                auto_renew: false,
                payment_method: 'card',
            },
        );
        // handleCheckout сам уводит на checkout_url
        expect(window.location.href).toBe(
            'https://securepay.tinkoff.ru/pay?paymentId=9',
        );
    });

    it('unconfirmed outcome never offers a new payment', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal', undefined_outcome: true },
        });

        await startSbpCheckoutWithExpiry(isoIn(5 * 60_000));

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText(UNCONFIRMED_TITLE)).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: CHOOSE_METHOD }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Проверить статус' }),
        ).toBeTruthy();
    });

    it('local link expiry never offers a new payment', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });

        await startSbpCheckoutWithExpiry(isoIn(1000));

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        await act(async () => {});

        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: CHOOSE_METHOD }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
    });

    it('reset stops the old polling and a late old answer never flips the screen', async () => {
        await reachConfirmedFailure();

        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));
        await act(async () => {});

        const callsAfterReset = vi.mocked(axios.get).mock.calls.length;
        expect(callsAfterReset).toBe(1);

        // Старый интервал мёртв: время идёт, GET не уходит
        act(() => {
            vi.advanceTimersByTime(60_000);
        });
        await act(async () => {});
        expect(vi.mocked(axios.get).mock.calls.length).toBe(callsAfterReset);

        // «Поздний» ответ старой попытки (так бы ответил сервер pay_1) не
        // доходит и не меняет форму: polling остановлен, состояние сброшено.
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });
        act(() => {
            vi.advanceTimersByTime(60_000);
        });
        await act(async () => {});

        expect(vi.mocked(axios.get).mock.calls.length).toBe(callsAfterReset);
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(screen.queryByText('Оплата не прошла')).toBeNull();
        expect(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeTruthy();
    });

    // ── POST/redirect в полёте: форма зафиксирована ──

    it('during POST method, auto-renew and CTA are locked and a second click sends no POST', async () => {
        let resolvePost: (value: {
            data: Record<string, unknown>;
        }) => void = () => {};
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolvePost = resolve;
                }),
        );

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('radio', { name: /Банковская карта/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );
        await act(async () => {});

        // CTA disabled и в состоянии ожидания, способы и автопродление — тоже.
        // Для карты CTA уже в состоянии «Перенаправление…».
        const cta = screen.getByRole('button', { name: 'Перенаправление…' });
        expect(cta.hasAttribute('disabled')).toBe(true);
        expect(
            screen.getByRole('radio', { name: /СБП/ }).hasAttribute('disabled'),
        ).toBe(true);
        expect(
            screen
                .getByRole('radio', { name: /Банковская карта/ })
                .hasAttribute('disabled'),
        ).toBe(true);
        expect(
            (screen.getByRole('checkbox') as HTMLInputElement).disabled,
        ).toBe(true);

        // Повторный клик по CTA и попытка сменить способ — без второго POST
        fireEvent.click(cta);
        fireEvent.click(screen.getByRole('radio', { name: /СБП/ }));
        expect(vi.mocked(axios.post)).toHaveBeenCalledTimes(1);

        await act(async () => {
            resolvePost({ data: SBP_CHECKOUT_RESPONSE.data });
        });
    });

    it('card redirect holds «Перенаправление…» and the disabled CTA until the page leaves', async () => {
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

        expect(window.location.href).toBe(
            'https://securepay.tinkoff.ru/pay?paymentId=1',
        );
        // Уход в банк не возвращает управление форме
        const cta = screen.getByRole('button', { name: 'Перенаправление…' });
        expect(cta.hasAttribute('disabled')).toBe(true);
        expect(
            screen.getByRole('radio', { name: /СБП/ }).hasAttribute('disabled'),
        ).toBe(true);
    });

    it('checkout error returns control: form controls and CTA are usable again', async () => {
        vi.mocked(axios.post).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: { errors: { plan: ['Платёж уже обрабатывается'] } },
            },
        });

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: /Оплатить 1.323 ₽/u }),
        );

        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toContain('Платёж уже обрабатывается');

        const cta = screen.getByRole('button', { name: /Оплатить 1.323 ₽/u });
        expect(cta.hasAttribute('disabled')).toBe(false);
        expect(
            screen.getByRole('radio', { name: /СБП/ }).hasAttribute('disabled'),
        ).toBe(false);
        expect(
            (screen.getByRole('checkbox') as HTMLInputElement).disabled,
        ).toBe(true); // СБП по умолчанию — чекбокс disabled по способу, не по загрузке

        // Повторная отправка после ошибки возможна
        vi.mocked(axios.post).mockResolvedValue(SBP_CHECKOUT_RESPONSE);
        fireEvent.click(cta);
        await act(async () => {});
        expect(vi.mocked(axios.post)).toHaveBeenCalledTimes(2);
        expect(screen.getByTestId('sbp-qr')).toBeTruthy();
    });

    // ── Reload/Back: существующая незавершённая попытка ──

    it('existing attempt with a payment id is shown as a check entry, not a payable form', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: 'pay_9',
                    period_months: 3,
                    status: 'processing',
                },
            }),
        );

        render(<BillingCheckoutPage />);

        expect(screen.getByText('Незавершённый платёж')).toBeTruthy();
        // Ни формы выбора способа, ни CTA новой оплаты, ни автопродления
        expect(screen.queryByRole('radio')).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(screen.queryByRole('checkbox')).toBeNull();
        // Без сохранённого SBP-payload продолжать нечего — только проверка
        expect(
            screen.queryByRole('button', { name: 'Продолжить оплату' }),
        ).toBeNull();
        // И никаких автоматических проверок
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();

        // Ручная проверка — тот же polling, без POST
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});

        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_9',
        );
        expect(axios.post).not.toHaveBeenCalled();
        expect(screen.getByText('Проверяем статус платежа')).toBeTruthy();
        // QR и ссылки в банк у возобновлённой попытки нет
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Открыть приложение банка' }),
        ).toBeNull();

        // Подтверждённый отказ → кнопка выбора способа с периодом попытки
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal' },
        });
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата не прошла')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));
        await act(async () => {});

        // Период попытки совпадает с URL — форма на месте, POST не уходил
        expect(screen.getByRole('radio', { name: /СБП/ })).toBeTruthy();
        expect(screen.queryByText('Незавершённый платёж')).toBeNull();
        expect(screen.getByText('3 месяца')).toBeTruthy();
        expect(axios.post).not.toHaveBeenCalled();
        expect(mockRouter.get).not.toHaveBeenCalled();
    });

    it('existing attempt without a payment id shows «Статус уточняется» without any check or payment', () => {
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: null,
                    period_months: 3,
                    status: 'created',
                },
            }),
        );

        render(<BillingCheckoutPage />);

        expect(screen.getByText('Статус уточняется')).toBeTruthy();
        // Ни неработающей кнопки проверки, ни новой оплаты
        expect(
            screen.queryByRole('button', { name: 'Проверить статус' }),
        ).toBeNull();
        expect(screen.queryByRole('radio')).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('«Выбрать способ оплаты» goes to checkout with the attempt period when it differs from the URL', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: 'pay_9',
                    period_months: 6,
                    status: 'processing',
                },
            }),
        );

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        expect(screen.getByText('Оплата не прошла')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));

        expect(mockRouter.get).toHaveBeenCalledWith(
            '/admin/billing/checkout?period_months=6',
        );
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('an unrecoverable attempt period leads to period selection, never to an invented one', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: 'pay_9',
                    period_months: null,
                    status: 'processing',
                },
            }),
        );

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});

        fireEvent.click(screen.getByRole('button', { name: CHOOSE_METHOD }));

        expect(mockRouter.get).toHaveBeenCalledWith('/admin/billing');
        expect(axios.post).not.toHaveBeenCalled();
    });

    // ── Reload/Back: продолжение той же СБП-попытки ──

    it('reload of an unpaid SBP attempt resumes the same link without POST/Init', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        const expiresAt = isoIn(5 * 60_000);
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: 'pay_9',
                    period_months: 3,
                    status: 'processing',
                    method: 'sbp',
                    sbp_payload: 'https://qr.nspk.ru/RESUMEATTEMPT',
                    sbp_expires_at: expiresAt,
                },
            }),
        );

        render(<BillingCheckoutPage />);
        expect(axios.post).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', { name: 'Продолжить оплату' }),
        );
        await act(async () => {});

        // Тот же payload и исходный абсолютный дедлайн — без нового Init
        expect(screen.getByTestId('sbp-qr').getAttribute('data-value')).toBe(
            'https://qr.nspk.ru/RESUMEATTEMPT',
        );
        expect(screen.getByTestId('sbp-countdown').textContent).toContain(
            '05:00',
        );
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(axios.post).not.toHaveBeenCalled();

        // Тот же paymentId опрашивается
        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});
        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_9',
        );
    });

    it('an already-expired SBP link resumes as a status check without a live link', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: 'pay_9',
                    period_months: 3,
                    status: 'processing',
                    method: 'sbp',
                    sbp_payload: 'https://qr.nspk.ru/EXPIREDATTEMPT',
                    sbp_expires_at: isoIn(-60_000),
                },
            }),
        );

        render(<BillingCheckoutPage />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Продолжить оплату' }),
        );
        await act(async () => {});

        // Истёкшая ссылка: без QR, без ссылок в банк, без новой оплаты —
        // только проверка статуса той же попытки
        expect(screen.getByText(EXPIRED_TITLE)).toBeTruthy();
        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(screen.queryByRole('link', { name: 'Открыть СБП' })).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Открыть приложение банка' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Оплатить 1.323 ₽/u }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: CHOOSE_METHOD }),
        ).toBeNull();
        expect(axios.post).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        await act(async () => {});
        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_9',
        );
    });

    it('an attempt of an unknown method never shows a payable SBP link', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                pending_attempt: {
                    payment_id: 'pay_9',
                    period_months: 3,
                    status: 'processing',
                    method: null,
                    sbp_payload: null,
                    sbp_expires_at: null,
                },
            }),
        );

        render(<BillingCheckoutPage />);

        // Способ неизвестен — не угадываем СБП: только проверка статуса
        expect(
            screen.queryByRole('button', { name: 'Продолжить оплату' }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Проверить статус' }),
        ).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await act(async () => {});

        expect(screen.queryByTestId('sbp-qr')).toBeNull();
        expect(screen.getByText('Проверяем статус платежа')).toBeTruthy();
        expect(axios.post).not.toHaveBeenCalled();
    });
});
