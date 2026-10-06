import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import React from 'react';
import axios from 'axios';
import BillingPage from '@/pages/admin/billing';

const mockUsePage = vi.fn();
const { routerReload } = vi.hoisted(() => ({ routerReload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: {
        get: vi.fn(),
        reload: routerReload,
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
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
        get: vi.fn(),
        post: vi.fn(),
        isAxiosError: () => false,
    },
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}));

function makeProps(
    payment: { payment_return?: string | null; payment_attempt_id?: string | null } = {},
    currentOverrides: Record<string, unknown> = {},
) {
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
            payment_return: null,
            payment_attempt_id: null,
            ...payment,
        },
    };
}

async function flush() {
    await act(async () => {});
}

function tick(ms: number) {
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}

describe('admin/billing.tsx — payment return verification', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(makeProps());
        vi.mocked(axios.get).mockReset().mockResolvedValue({
            data: { status: 'processing' },
        });
        routerReload.mockReset().mockResolvedValue(undefined);
        vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    // ── Return flow: verifying state ──

    it('return with bound attempt shows «Проверяем оплату» and checks status immediately', async () => {
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Проверяем оплату')).toBeTruthy();
        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_1',
        );
        expect(screen.queryByText('Оплата прошла')).toBeNull();
    });

    it('reload with attempt id in props reopens verification without a flash', async () => {
        // The id survives reload via the URL → server props, flash is one-shot
        mockUsePage.mockReturnValue(
            makeProps({ payment_return: null, payment_attempt_id: 'pay_1' }),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Проверяем оплату')).toBeTruthy();
        expect(axios.get).toHaveBeenCalledWith(
            '/admin/billing/payment-status/pay_1',
        );
    });

    // ── Verdicts from Billing Core only ──

    it('return after webhook (succeeded) shows success and refreshes subscription data', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });
        mockUsePage.mockReturnValue(
            makeProps(
                { payment_return: 'returned', payment_attempt_id: 'pay_1' },
                { tariff: 'pro', is_paid: true, expires_at: '2027-01-01T00:00:00.000Z' },
            ),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        expect(routerReload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['current'] }),
        );
        expect(screen.queryByText('Проверяем оплату')).toBeNull();

        // Polling stopped after the confirmed verdict
        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('stale current date is never shown as refreshed after success', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });
        // reload() never signals completion by itself — no callbacks fire
        vi.mocked(routerReload).mockImplementation(() => {});
        mockUsePage.mockReturnValue(
            makeProps(
                { payment_return: 'returned', payment_attempt_id: 'pay_1' },
                { tariff: 'pro', is_paid: true, expires_at: '2027-03-01T00:00:00.000Z' },
            ),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        // Старая дата из props не выдаётся за обновлённую
        expect(screen.queryByText('Активен до')).toBeNull();
        expect(screen.queryByText('1 марта 2027')).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('refreshed date appears only after reload onSuccess payload', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });
        const captured: {
            only?: string[];
            onSuccess?: (page: unknown) => void;
        }[] = [];
        vi.mocked(routerReload).mockImplementation((options: unknown) => {
            captured.push(options as (typeof captured)[number]);
        });
        mockUsePage.mockReturnValue(
            makeProps(
                { payment_return: 'returned', payment_attempt_id: 'pay_1' },
                { tariff: 'pro', is_paid: true, expires_at: '2027-03-01T00:00:00.000Z' },
            ),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        expect(screen.queryByText('Активен до')).toBeNull();
        expect(captured).toHaveLength(1);
        expect(captured[0].only).toEqual(['current']);

        // Задержанный ответ partial reload — дата берётся из его payload
        await act(async () => {
            captured[0].onSuccess?.({
                props: { current: { expires_at: '2027-12-01T00:00:00.000Z' } },
            });
        });

        expect(screen.getByText('Активен до')).toBeTruthy();
        expect(screen.getByText('1 декабря 2027')).toBeTruthy();
        expect(screen.queryByText('1 марта 2027')).toBeNull();
    });

    it('reload error keeps success without ever showing the stale date', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });
        vi.mocked(routerReload).mockImplementation((options: unknown) => {
            const opts = options as {
                onError?: (errors: unknown) => void;
                onFinish?: (visit: unknown) => void;
            };
            // Ошибка обновления: onError/onFinish без onSuccess
            opts.onError?.({});
            opts.onFinish?.({});
        });
        mockUsePage.mockReturnValue(
            makeProps(
                { payment_return: 'returned', payment_attempt_id: 'pay_1' },
                { tariff: 'pro', is_paid: true, expires_at: '2027-03-01T00:00:00.000Z' },
            ),
        );

        render(<BillingPage />);
        await flush();

        // Подтверждённый успех не зависит от обновления данных…
        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        // …но старая дата не выдаётся за обновлённую
        tick(10000);
        expect(screen.queryByText('Активен до')).toBeNull();
        expect(screen.queryByText('1 марта 2027')).toBeNull();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('return before webhook keeps verifying until Billing Core confirms', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get)
            .mockResolvedValueOnce({ data: { status: 'processing' } })
            .mockResolvedValueOnce({ data: { status: 'processing' } })
            .mockResolvedValue({ data: { status: 'succeeded' } });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Проверяем оплату')).toBeTruthy();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        tick(2000);
        await flush();
        expect(screen.getByText('Проверяем оплату')).toBeTruthy();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);

        tick(2000);
        await flush();
        expect(screen.getByText('Оплата прошла')).toBeTruthy();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(3);
    });

    it('confirmed decline shows failure with a retry CTA and no subscription claims', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Оплата не завершена')).toBeTruthy();
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Попробовать ещё раз' }),
        ).toBeTruthy();
        // Неподтверждённые утверждения о неизменности подписки убраны
        expect(screen.queryByText(/остались без изменений/)).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('local age-release (undefined_outcome) stays unconfirmed without a retry CTA', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'failed_terminal', undefined_outcome: true },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(
            screen.getByText('Пока не удалось подтвердить оплату'),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Проверить статус' }),
        ).toBeTruthy();
        // Ни утверждения об отказе банка, ни повторной оплаты
        expect(screen.queryByText('Оплата не завершена')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Попробовать ещё раз' }),
        ).toBeNull();
        expect(screen.queryByText('Оплата прошла')).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('refunded is never shown as success and claims nothing about the subscription', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'refunded' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Платёж возвращён')).toBeTruthy();
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(screen.queryByText(/остались без изменений/)).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('partial refund is not presented as a full refund', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'partially_refunded' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(screen.getByText('Возвращена часть платежа')).toBeTruthy();
        expect(screen.getByText('Частичный возврат')).toBeTruthy();
        expect(screen.queryByText('Платёж возвращён')).toBeNull();
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(screen.queryByText(/остались без изменений/)).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    // ── Unknown outcome: unconfirmed, no re-payment ──

    it('network error shows «Пока не удалось подтвердить оплату» and stops polling', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockRejectedValue(new Error('network down'));
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(
            screen.getByText('Пока не удалось подтвердить оплату'),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Проверить статус' }),
        ).toBeTruthy();
        // Без повторной оплаты
        expect(
            screen.queryByRole('button', { name: 'Попробовать ещё раз' }),
        ).toBeNull();
        expect(screen.queryByText('Оплата прошла')).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('time budget without confirmation ends in unconfirmed, not failure', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        // 1 immediate check + 24 interval ticks = bounded budget
        for (let i = 0; i < 24; i++) {
            tick(2000);
            await flush();
        }

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(25);
        expect(
            screen.getByText('Пока не удалось подтвердить оплату'),
        ).toBeTruthy();
        expect(screen.queryByText('Оплата не завершена')).toBeNull();
        expect(screen.queryByText('Оплата прошла')).toBeNull();

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(25);
    });

    it('«Проверить статус» resumes the bounded polling', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('network down'));
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        expect(
            screen.getByRole('button', { name: 'Проверить статус' }),
        ).toBeTruthy();

        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'succeeded' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Проверить статус' }),
        );
        await flush();

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);
        expect(screen.getByText('Оплата прошла')).toBeTruthy();
    });

    // ── No id: neutral result ──

    it('return without attempt id shows a neutral result and never polls', async () => {
        vi.useFakeTimers();
        mockUsePage.mockReturnValue(
            makeProps({ payment_return: 'returned', payment_attempt_id: null }),
        );

        render(<BillingPage />);
        await flush();

        expect(
            screen.getByText('Пока не удалось подтвердить оплату'),
        ).toBeTruthy();
        expect(screen.queryByText('Оплата прошла')).toBeNull();
        expect(screen.queryByText('Оплата не завершена')).toBeNull();
        // No id → no status request, no manual re-check that cannot work
        expect(axios.get).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Проверить статус' }),
        ).toBeNull();

        tick(10000);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('no return signal renders no dialog at all', () => {
        render(<BillingPage />);

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(axios.get).not.toHaveBeenCalled();
    });

    // ── Polling hygiene ──

    it('never runs parallel requests while one is in flight', async () => {
        vi.useFakeTimers();
        let resolveFirst: (value: { data: { status: string } }) => void = () => {};
        vi.mocked(axios.get).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        // Tab return + elapsed time while the request is still pending
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
            vi.advanceTimersByTime(10000);
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        await act(async () => {
            resolveFirst({ data: { status: 'processing' } });
        });
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        // Sequential polling continues only after the previous response
        tick(2000);
        await flush();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);
    });

    it('returning to the tab re-checks immediately without waiting for the tick', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        await flush();

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);
    });

    it('unmount cleans up the polling loop', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        const { unmount } = render(<BillingPage />);
        await flush();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        unmount();
        tick(10000);

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('closing the dialog stops polling and strips the attempt id from the URL', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.get).mockResolvedValue({
            data: { status: 'processing' },
        });
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Закрыть' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(window.history.replaceState).toHaveBeenCalledTimes(1);
        expect(window.history.replaceState).toHaveBeenCalledWith(
            null,
            '',
            window.location.pathname,
        );

        tick(10000);
        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
    });

    it('dialog shell is scrollable on desktop and mobile', async () => {
        mockUsePage.mockReturnValue(
            makeProps({
                payment_return: 'returned',
                payment_attempt_id: 'pay_1',
            }),
        );

        render(<BillingPage />);
        await flush();

        const dialog = screen.getByRole('dialog');
        const shell = dialog.parentElement?.parentElement;
        expect(shell?.className).toContain('overflow-y-auto');
        expect(shell?.className).toContain('p-4');
        expect(shell?.className).toContain('md:p-5');
    });
});
