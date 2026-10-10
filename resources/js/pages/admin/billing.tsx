import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import axios from 'axios';
import { toast } from 'sonner';

/* ═══════════════ Types ═══════════════ */

interface PlanPrice {
    period_months: number;
    base: number;
    discount_percent: number;
    final: number;
}

interface Plan {
    id: number | string;
    code: string;
    name: string;
    price_monthly: number;
    max_appointments_per_month: number | null;
    features: string[];
    prices: PlanPrice[];
}

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface PageProps {
    plans: Plan[];
    current: {
        tariff: string | null;
        tariff_name: string | null;
        is_paid?: boolean;
        expires_at?: string | null;
        days_left?: number;
        auto_renew_enabled?: boolean;
        renewal_period_months?: number | null;
    };
    auth?: { user?: AuthUser };
    tariff_limits?: { total: number | null; used: number } | null;
    payment_return?: 'returned' | null;
    payment_attempt_id?: string | null;
    payment_attempt_period_months?: number | null;
    [key: string]: unknown;
}

/* ═══════════════ Helpers ═══════════════ */

const FEATURE_LABELS: Record<string, string> = {
    calendar: 'Календарь записей',
    basic_client_management: 'Базовая база клиентов',
    unlimited_appointments: 'Безлимит записей',
    client_management: 'Полная база клиентов',
    channel_analytics: 'Аналитика каналов записи',
    slot_autofill: 'Автозаполнение свободных окон',
    free_windows: 'Свободные окна',
};

const BENEFIT_FEATURES = ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill', 'free_windows'];

const MONTHS_RU: Record<number, string> = {
    1: 'месяц',
    2: 'месяца',
    3: 'месяца',
    4: 'месяца',
    5: 'месяцев',
    6: 'месяцев',
    7: 'месяцев',
    8: 'месяцев',
    9: 'месяцев',
    10: 'месяцев',
    11: 'месяцев',
    12: 'месяцев',
};

const MONTHS_RU_SHORT: Record<number, string> = {
    1: 'мес',
    3: 'мес',
    6: 'мес',
    12: 'мес',
};

const FULL_MONTHS_RU = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

const fmt = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

function formatExpiry(iso: string): string {
    const [y, m, d] = iso.split('T')[0].split('-').map(Number);
    return `${d} ${FULL_MONTHS_RU[m - 1]} ${y}`;
}

function pluralizePeriod(n: number): string {
    return `${n} ${MONTHS_RU[n] ?? 'месяцев'}`;
}

/* ═══════════════ Payment return verification ═══════════════ */

// Verdict stages of the return dialog. 'verifying' polls the read-only
// status endpoint; 'success' is only reachable via a Billing Core
// confirmed attempt. FailURL / network errors / local age-release
// (reconciliation_timeout) land in 'unconfirmed' — never in 'failed' and
// never in 'success'.
type PaymentCheckStage =
    | 'verifying'
    | 'success'
    | 'failed'
    | 'refunded'
    | 'partially_refunded'
    | 'unconfirmed'
    | 'neutral';

// Only what the success dialog renders from a refreshed reload payload —
// a stale `current` prop must never be presented as updated. Tariff name
// and expiry come exclusively from this payload: while the reload is
// pending (or has failed) the dialog shows an explicit caption instead of
// old data.
interface FreshCurrent {
    tariff_name?: string | null;
    expires_at?: string | null;
}

type FreshCurrentState =
    | { status: 'loading' }
    | { status: 'ready'; current: FreshCurrent }
    | { status: 'error' };

const PAYMENT_POLL_INTERVAL_MS = 2000;
const PAYMENT_POLL_MAX_ATTEMPTS = 25;

const PAYMENT_BTN_PRIMARY =
    'h-[46px] w-full cursor-pointer rounded-[12px] border-0 bg-[var(--color-orange)] text-[14px] font-bold text-white transition-colors hover:bg-[var(--color-orange-600)]';
const PAYMENT_BTN_SECONDARY =
    'h-[42px] w-full cursor-pointer rounded-[10px] border-0 bg-transparent text-[13px] font-semibold text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-ink)]';

function initialPaymentCheck(
    returned: boolean,
    attemptId: string | null,
): PaymentCheckStage | null {
    if (attemptId !== null) {
        return 'verifying';
    }

    // Return without a bound attempt (or a foreign/unknown id dropped by the
    // server) → neutral result: no verdict, no polling.
    return returned ? 'neutral' : null;
}

/**
 * Closing the dialog writes ?payment_check=closed so a reload does not
 * reopen it, while the attempt id itself stays in the URL — the manual
 * «Проверить статус» entry keeps working across reloads.
 */
function paymentCheckClosedInUrl(): boolean {
    return (
        new URLSearchParams(window.location.search).get('payment_check') ===
        'closed'
    );
}

/* ═══════════════ Main Page ═══════════════ */

export default function BillingPage() {
    const props = usePage<PageProps>().props;
    const auth = props.auth;
    const { plans, current } = props;
    const tariffLimits = props.tariff_limits;

    const proPlan = plans.find((p) => p.code === 'pro');

    const [selectedPeriod, setSelectedPeriod] = useState(3);
    const [autoRenewActive, setAutoRenewActive] = useState(Boolean(current.auto_renew_enabled));
    const [disablingAutoRenew, setDisablingAutoRenew] = useState(false);

    // The return routes only signal "user came back from the bank" — the
    // verdict comes from Billing Core via the read-only status endpoint.
    const paymentAttemptId = props.payment_attempt_id ?? null;
    // Период этой же попытки — для «Выбрать способ оплаты» после отказа.
    // null = восстановить нельзя → выбор периода на этой странице.
    const paymentAttemptPeriodMonths =
        typeof props.payment_attempt_period_months === 'number'
            ? props.payment_attempt_period_months
            : null;
    const [paymentCheck, setPaymentCheck] = useState<PaymentCheckStage | null>(() => {
        // A closed dialog stays closed after a reload — but the attempt id
        // survives in the URL, so «Проверить статус» remains available.
        if (paymentCheckClosedInUrl()) {
            return null;
        }

        return initialPaymentCheck(props.payment_return === 'returned', paymentAttemptId);
    });
    // Set only from the onSuccess payload of the post-success reload —
    // stays null on delay/error so the old date is never shown as fresh.
    const [freshCurrent, setFreshCurrent] = useState<FreshCurrentState | null>(null);
    const renewSectionRef = useRef<HTMLElement | null>(null);

    const closePaymentCheck = useCallback(
        (focusRenew: boolean) => {
            setPaymentCheck(null);

            // The bound attempt id must survive a reload (manual re-check
            // stays possible) — the URL is only marked as closed.
            if (paymentAttemptId !== null) {
                const params = new URLSearchParams();
                params.set('payment_attempt', paymentAttemptId);
                params.set('payment_check', 'closed');

                window.history.replaceState(
                    window.history.state,
                    '',
                    `${window.location.pathname}?${params.toString()}`,
                );
            } else {
                window.history.replaceState(
                    window.history.state,
                    '',
                    window.location.pathname,
                );
            }

            if (focusRenew) {
                requestAnimationFrame(() => {
                    renewSectionRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    renewSectionRef.current?.focus({ preventScroll: true });
                });
            }
        },
        [paymentAttemptId],
    );

    // Visible re-check entry for the same attempt after the dialog is
    // closed. Manual, never automatic; the closed flag is removed from the
    // URL so a reload during verification reopens the dialog again.
    const reopenPaymentCheck = useCallback(() => {
        if (paymentAttemptId === null) {
            return;
        }

        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}?payment_attempt=${encodeURIComponent(paymentAttemptId)}`,
        );
        setPaymentCheck('verifying');
    }, [paymentAttemptId]);

    useEffect(() => {
        if (paymentCheck === null) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                closePaymentCheck(false);
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [paymentCheck, closePaymentCheck]);

    // ── Bounded verification polling: local Billing Core attempt only ──
    // Sequential by construction: one request at a time (inFlight guard),
    // the next tick is scheduled only after the previous response, cleanup
    // on state change/unmount, and an immediate re-check when the tab
    // becomes visible again.
    useEffect(() => {
        if (paymentCheck !== 'verifying' || paymentAttemptId === null) {
            return;
        }

        let cancelled = false;
        let inFlight = false;
        let attempts = 0;
        let timer: number | null = null;

        function schedule() {
            if (cancelled) {
                return;
            }

            if (attempts >= PAYMENT_POLL_MAX_ATTEMPTS) {
                // Time budget exhausted without confirmation — an unknown
                // outcome is not a failure and not a success.
                setPaymentCheck('unconfirmed');

                return;
            }

            timer = window.setTimeout(() => {
                timer = null;
                void check();
            }, PAYMENT_POLL_INTERVAL_MS);
        }

        async function check() {
            if (cancelled || inFlight) {
                return;
            }

            inFlight = true;
            attempts += 1;

            try {
                const res = await axios.get(
                    `/admin/billing/payment-status/${paymentAttemptId}`,
                );

                if (cancelled) {
                    return;
                }

                const status = res.data?.status;
                // The one minimal flag from failure_category: a local
                // age-release (reconciliation_timeout), not a bank decline.
                const undefinedOutcome = res.data?.undefined_outcome === true;

                if (status === 'succeeded') {
                    // Success only after Billing Core confirmed it — show the
                    // result right away and refresh the subscription data via
                    // documented per-visit callbacks (reload() itself returns
                    // void and signals nothing on its own).
                    if (!cancelled) {
                        setFreshCurrent({ status: 'loading' });
                        setPaymentCheck('success');
                    }

                    router.reload({
                        only: ['current'],
                        // Deliberately NOT gated by the polling effect's
                        // `cancelled` flag: the effect cleans up as soon as the
                        // stage flips to 'success', while this reload must keep
                        // delivering its payload afterwards.
                        onSuccess: (page: {
                            props?: { current?: FreshCurrent };
                        }) => {
                            const current = page.props?.current ?? null;

                            setFreshCurrent(
                                current === null
                                    ? { status: 'error' }
                                    : { status: 'ready', current },
                            );
                        },
                        onError: () => {
                            setFreshCurrent({ status: 'error' });
                        },
                        onFinish: () => {
                            // A reload that signals nothing (network cut)
                            // must not leave a perpetual "loading" caption.
                            setFreshCurrent((prev) =>
                                prev?.status === 'loading'
                                    ? { status: 'error' }
                                    : prev,
                            );
                        },
                    });
                } else if (status === 'failed_terminal') {
                    // Only a provider-confirmed decline is a failure; the local
                    // age-release stays unconfirmed — «Проверить статус»
                    // without a decline claim or a re-payment CTA.
                    setPaymentCheck(undefinedOutcome ? 'unconfirmed' : 'failed');
                } else if (status === 'refunded') {
                    setPaymentCheck('refunded');
                } else if (status === 'partially_refunded') {
                    setPaymentCheck('partially_refunded');
                } else {
                    // created / processing / failed_retryable / unknown → wait
                    schedule();
                }
            } catch {
                // Network error / timeout / 404 — cannot confirm, never guess
                if (!cancelled) {
                    setPaymentCheck('unconfirmed');
                }
            } finally {
                inFlight = false;
            }
        }

        // Returning to the tab re-checks immediately. The pending timer is
        // reset so requests stay strictly sequential.
        function onVisibilityChange() {
            if (
                document.visibilityState !== 'visible' ||
                cancelled ||
                inFlight
            ) {
                return;
            }

            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }

            void check();
        }

        void check();
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            cancelled = true;

            if (timer !== null) {
                window.clearTimeout(timer);
            }

            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [paymentCheck, paymentAttemptId]);

    const selectedPrice = proPlan?.prices.find((p) => p.period_months === selectedPeriod);
    const monthlyEquiv = selectedPrice ? Math.round(selectedPrice.final / selectedPeriod) : 0;
    const saving = selectedPrice ? selectedPrice.base - selectedPrice.final : 0;

    const isPaid = current.is_paid && current.tariff === 'pro';

    // PR1: card payment contract is unchanged — the own checkout page owns
    // the POST /admin/checkout call and the T-Bank redirect.
    function goToCheckout() {
        router.get(`/admin/billing/checkout?period_months=${selectedPeriod}`);
    }

    async function handleDisableAutoRenew() {
        if (disablingAutoRenew) return;

        setDisablingAutoRenew(true);
        try {
            await axios.post('/admin/billing/auto-renew/disable');
            setAutoRenewActive(false);
            toast.success('Автопродление отключено. Оплаченный период останется активным до даты окончания.');
        } catch {
            toast.error('Не удалось отключить автопродление');
        } finally {
            setDisablingAutoRenew(false);
        }
    }

    if (!proPlan) return null;

    // ─── Payment return dialog content (stage-driven) ───
    const paymentStage = paymentCheck;

    let dialogTone =
        'bg-[var(--color-surface-hover)] text-[var(--color-graphite)]';
    let dialogIcon: ReactNode = null;
    let dialogLabel = '';
    let dialogTitle = '';
    let dialogBody = '';
    let dialogNote: string | null = null;
    let dialogDetails = false;
    let dialogFooter: ReactNode = null;

    switch (paymentStage) {
        case 'verifying':
            dialogTone = 'bg-[var(--color-orange-100)] text-[var(--color-orange)]';
            dialogIcon = (
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2.2"
                    strokeLinecap="round"
                    className="animate-spin"
                    aria-hidden="true"
                >
                    <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                </svg>
            );
            dialogLabel = 'Проверка оплаты';
            dialogTitle = 'Проверяем оплату';
            dialogBody =
                'Подтверждаем, что платёж обработан. Это занимает до минуты — не запускайте оплату повторно.';
            dialogFooter = (
                <button
                    type="button"
                    onClick={() => closePaymentCheck(false)}
                    className={PAYMENT_BTN_SECONDARY}
                >
                    Закрыть
                </button>
            );
            break;

        case 'success':
            dialogTone = 'bg-[var(--color-green-bg)] text-[var(--color-green)]';
            dialogIcon = (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="m5 12 4 4L19 6" />
                </svg>
            );
            dialogLabel = 'Платёж завершён';
            dialogTitle = 'Оплата прошла';
            dialogBody =
                'Профи продлён. Новый период уже добавлен к текущей подписке.';
            dialogDetails = true;
            dialogNote =
                'Можно продолжать работу — дополнительные действия не нужны.';
            dialogFooter = (
                <button
                    type="button"
                    onClick={() => closePaymentCheck(false)}
                    className={PAYMENT_BTN_PRIMARY}
                >
                    Продолжить
                </button>
            );
            break;

        case 'failed':
            dialogTone = 'bg-[var(--color-red-bg)] text-[var(--color-red)]';
            dialogIcon = (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round">
                    <path d="m7 7 10 10M17 7 7 17" />
                </svg>
            );
            dialogLabel = 'Платёж не завершён';
            dialogTitle = 'Оплата не завершена';
            // No claim about the subscription state — this dialog only
            // confirms what Billing Core reported about the payment itself.
            dialogBody = 'Списание не завершено.';
            dialogNote = 'Выберите способ оплаты, чтобы повторить попытку.';
            dialogFooter = (
                <>
                    <button
                        type="button"
                        onClick={() => {
                            // Checkout с периодом ИМЕННО этой попытки (СБП
                            // по умолчанию, без автоплатежа). Период
                            // неизвестен → выбор периода на этой странице.
                            if (paymentAttemptPeriodMonths !== null) {
                                router.get(
                                    `/admin/billing/checkout?period_months=${paymentAttemptPeriodMonths}`,
                                );
                            } else {
                                closePaymentCheck(true);
                            }
                        }}
                        className={PAYMENT_BTN_PRIMARY}
                    >
                        Выбрать способ оплаты
                    </button>
                    <button
                        type="button"
                        onClick={() => closePaymentCheck(false)}
                        className={PAYMENT_BTN_SECONDARY}
                    >
                        Закрыть
                    </button>
                </>
            );
            break;

        case 'refunded':
            dialogIcon = (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                    <path d="M3 3v5h5" />
                </svg>
            );
            dialogLabel = 'Возврат платежа';
            dialogTitle = 'Платёж возвращён';
            dialogBody = 'Деньги возвращены на счёт.';
            dialogFooter = (
                <button
                    type="button"
                    onClick={() => closePaymentCheck(false)}
                    className={PAYMENT_BTN_SECONDARY}
                >
                    Закрыть
                </button>
            );
            break;

        case 'partially_refunded':
            dialogIcon = (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                    <path d="M3 3v5h5" />
                </svg>
            );
            dialogLabel = 'Частичный возврат';
            dialogTitle = 'Возвращена часть платежа';
            dialogBody = 'Банк вернул часть суммы этого платежа.';
            dialogFooter = (
                <button
                    type="button"
                    onClick={() => closePaymentCheck(false)}
                    className={PAYMENT_BTN_SECONDARY}
                >
                    Закрыть
                </button>
            );
            break;

        case 'unconfirmed':
            dialogTone = 'bg-[var(--color-orange-100)] text-[var(--color-orange)]';
            dialogIcon = (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                </svg>
            );
            dialogLabel = 'Статус не подтверждён';
            dialogTitle = 'Пока не удалось подтвердить оплату';
            dialogBody =
                'Если вы уже оплачивали — не запускайте оплату повторно. Нажмите «Проверить статус» или вернитесь позже: результат появится после подтверждения платежа.';
            dialogFooter = (
                <>
                    <button
                        type="button"
                        onClick={() => setPaymentCheck('verifying')}
                        className={PAYMENT_BTN_PRIMARY}
                    >
                        Проверить статус
                    </button>
                    <button
                        type="button"
                        onClick={() => closePaymentCheck(false)}
                        className={PAYMENT_BTN_SECONDARY}
                    >
                        Закрыть
                    </button>
                </>
            );
            break;

        case 'neutral':
            dialogIcon = (
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                </svg>
            );
            dialogLabel = 'Статус оплаты';
            dialogTitle = 'Пока не удалось подтвердить оплату';
            dialogBody =
                'Не удалось определить этот платёж. Если списание произошло, оно подтвердится автоматически — раздел тарифов обновится после обработки.';
            dialogFooter = (
                <button
                    type="button"
                    onClick={() => closePaymentCheck(false)}
                    className={PAYMENT_BTN_SECONDARY}
                >
                    Закрыть
                </button>
            );
            break;

        case null:
            break;
    }

    return (
        <>
            <Head title="Тарифы и оплата" />

            <AdminLayout title="Тарифы и оплата" auth={auth} fullBleed>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="w-full max-w-[1180px] space-y-4 pb-[180px] md:pb-10">

                    {/* ─── 1. Current Plan Strip ─── */}
                    <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-4">
                        <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-4 max-md:grid-cols-1">
                            <div className="min-w-0">
                                <div className="truncate text-[16px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">
                                    {isPaid && current.expires_at
                                        ? `${current.tariff_name} активен до ${formatExpiry(current.expires_at)}`
                                        : current.tariff_name
                                            ? `Тариф: ${current.tariff_name}`
                                            : 'Тариф не выбран'}
                                </div>
                                <div className="mt-0.5 text-[12px] leading-4 text-[var(--color-graphite)]">
                                    {isPaid && current.days_left !== undefined
                                        ? `Записи без ограничений · осталось ${current.days_left} дн.`
                                        : current.tariff === 'start'
                                            ? 'Без ограничения количества записей'
                                            : ''}
                                </div>
                            </div>
                            <span className="shrink-0 rounded-[7px] border border-[var(--color-line)] bg-[var(--color-surface-hover)] px-2.5 py-0.5 text-[11px] font-semibold text-[var(--color-graphite)] max-md:justify-self-start">
                                Текущий тариф
                            </span>
                        </div>
                    </section>

                    {/* ─── Manual re-check entry for a closed return dialog ─── */}
                    {paymentAttemptId !== null && paymentCheck === null && (
                        <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="text-[14px] font-bold leading-[19px] tracking-[-.015em] text-[var(--color-ink)]">
                                        Статус платежа
                                    </div>
                                    <div className="mt-[3px] text-[12px] leading-4 text-[var(--color-graphite)]">
                                        Результат этого платежа можно проверить
                                        вручную.
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={reopenPaymentCheck}
                                    className="shrink-0 cursor-pointer rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-4 text-[13px] font-semibold text-[var(--color-ink)] transition-colors hover:border-[var(--color-orange)] hover:text-[var(--color-orange)]"
                                >
                                    Проверить статус
                                </button>
                            </div>
                        </section>
                    )}

                    {/* ─── Auto renewal status (only while consented and active) ─── */}
                    {isPaid && autoRenewActive && (
                        <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="text-[14px] font-bold leading-[19px] tracking-[-.015em] text-[var(--color-ink)]">
                                        Автопродление включено
                                    </div>
                                    <div className="mt-[3px] text-[12px] leading-4 text-[var(--color-graphite)]">
                                        Следующий период: каждые {current.renewal_period_months} мес.
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleDisableAutoRenew}
                                    disabled={disablingAutoRenew}
                                    className="shrink-0 cursor-pointer rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-4 text-[13px] font-semibold text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {disablingAutoRenew ? 'Отключение…' : 'Отключить автопродление'}
                                </button>
                            </div>
                        </section>
                    )}

                    {/* ─── 2. Renewal Section ─── */}
                    <section
                        ref={renewSectionRef}
                        tabIndex={-1}
                        className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5 outline-none"
                    >
                        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="text-[15px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">
                                    Продлить Профи
                                </div>
                                <div className="mt-[3px] text-[12px] leading-4 text-[var(--color-graphite)]">
                                    Выберите срок. Скидка видна сразу в каждом варианте.
                                </div>
                            </div>
                            <span className="shrink-0 rounded-[7px] border border-[var(--color-line)] bg-[var(--color-surface-hover)] px-2.5 py-0.5 text-[11px] font-semibold text-[var(--color-graphite)]">
                                {fmt(proPlan.price_monthly)} / мес базовая цена
                            </span>
                        </div>

                        {/* Period Grid */}
                        <div className="grid grid-cols-4 gap-2.5 max-md:grid-cols-2">
                            {proPlan.prices.map((price) => {
                                const isActive = selectedPeriod === price.period_months;
                                const monthly = Math.round(price.final / price.period_months);
                                const cardSaving = price.base - price.final;
                                return (
                                    <button
                                        key={price.period_months}
                                        type="button"
                                        aria-pressed={isActive}
                                        onClick={() => setSelectedPeriod(price.period_months)}
                                        className={`flex min-w-0 min-h-[120px] cursor-pointer flex-col rounded-[14px] border p-[14px] text-left transition-colors ${
                                            isActive
                                                ? 'border-[var(--color-orange)] bg-[var(--color-orange-100)] shadow-[inset_0_0_0_1px_var(--color-orange)]'
                                                : 'border-[var(--color-line)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-hover)]'
                                        }`}
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <span className={`text-[13px] font-semibold ${isActive ? 'text-[var(--color-orange)]' : 'text-[var(--color-ink)]'}`}>
                                                {price.period_months} {MONTHS_RU_SHORT[price.period_months]}
                                            </span>
                                            {price.discount_percent > 0 && (
                                                <span className="rounded-[6px] bg-[var(--color-orange-100)] px-[6px] py-[2px] text-[10.5px] font-bold text-[var(--color-orange)]">
                                                    −{price.discount_percent}%
                                                </span>
                                            )}
                                        </div>
                                        <div className={`text-[18px] font-bold leading-[23px] tracking-[-.02em] ${isActive ? 'text-[var(--color-orange)]' : 'text-[var(--color-ink)]'}`}>
                                            {fmt(price.final)}
                                        </div>
                                        <div className="mt-[2px] text-[11px] leading-4 text-[var(--color-graphite)]">
                                            ≈ {fmt(monthly)} / мес
                                        </div>
                                        <div className="mt-auto pt-[2px] text-[11px] font-semibold leading-4 text-[var(--color-green)]">
                                            {cardSaving > 0 ? `Экономия ${fmt(cardSaving)}` : '\u00A0'}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Divider + Summary + CTA — desktop only */}
                        <div className="max-md:hidden">
                            <div className="my-5 border-t border-[var(--color-line-soft)]" />
                            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-6">
                                <div>
                                    <div className="text-[12px] leading-4 text-[var(--color-graphite)]">
                                        К оплате за {pluralizePeriod(selectedPeriod)}
                                    </div>
                                    <div className="mt-[2px] text-[28px] font-bold leading-[34px] tracking-[-.035em] text-[var(--color-ink)]">
                                        {selectedPrice ? fmt(selectedPrice.final) : '—'}
                                    </div>
                                    <div className="mt-[2px] text-[12px] leading-4 text-[var(--color-graphite)]">
                                        Подписка будет продлена на выбранный срок после текущей даты окончания.
                                    </div>
                                </div>
                                <div className="text-right">
                                    <div className="text-[14px] font-semibold text-[var(--color-ink)]">
                                        ≈ {fmt(monthlyEquiv)} / мес
                                    </div>
                                    {saving > 0 && (
                                        <div className="mt-[2px] text-[11px] font-semibold text-[var(--color-green)]">
                                            Экономия {fmt(saving)}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="mt-5 flex justify-end">
                                <button
                                    type="button"
                                    onClick={goToCheckout}
                                    className="h-10 cursor-pointer rounded-[10px] border-0 bg-[var(--color-orange)] px-5 text-[14px] font-semibold text-white transition-colors hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {`Продлить на ${pluralizePeriod(selectedPeriod)} — ${selectedPrice ? fmt(selectedPrice.final) : ''}`}
                                </button>
                            </div>
                        </div>
                    </section>

                    {/* ─── 3. Benefits ─── */}
                    <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                        <div className="text-[14px] font-bold leading-[19px] text-[var(--color-ink)]">
                            Что входит в Профи
                        </div>
                        <div className="mt-3 grid gap-[7px]">
                            {BENEFIT_FEATURES.filter((f) => proPlan.features.includes(f)).map((f) => (
                                <div key={f} className="flex items-center gap-[9px] text-[13px] text-[var(--color-ink)]">
                                    <svg className="shrink-0 text-[var(--color-green)]" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M20 6 9 17l-5-5" />
                                    </svg>
                                    {FEATURE_LABELS[f] ?? f}
                                </div>
                            ))}
                        </div>
                    </section>
                    </div>
                </div>

                {/* ─── 4. Mobile Sticky Purchase Bar ─── */}
                <div className="fixed inset-x-0 bottom-0 z-[55] border-t border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-3 pb-[max(12px,env(safe-area-inset-bottom))] pt-2.5 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] md:hidden">
                    <div className="flex items-center justify-between gap-3">
                        <div className="min-w-0">
                            <div className="text-[12px] leading-4 text-[var(--color-graphite)]">
                                {selectedPeriod} {MONTHS_RU_SHORT[selectedPeriod]} · ≈ {fmt(monthlyEquiv)} / мес
                            </div>
                            <div className="mt-[2px] text-[17px] font-bold leading-[22px] tracking-[-.02em] text-[var(--color-ink)]">
                                {selectedPrice ? fmt(selectedPrice.final) : '—'}
                            </div>
                            <div className={`mt-[2px] text-[11px] font-semibold leading-4 ${saving > 0 ? 'text-[var(--color-green)]' : 'text-[var(--color-graphite)]'}`}>
                                {saving > 0 ? `Экономия ${fmt(saving)}` : 'Без скидки'}
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={goToCheckout}
                            className="shrink-0 cursor-pointer rounded-[10px] border-0 bg-[var(--color-orange)] px-4 text-[13px] font-semibold text-white transition-colors hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50"
                            style={{ height: 40 }}
                        >
                            Продлить
                        </button>
                    </div>
                </div>

                {/* ─── 5. Payment Return Dialog — verifies the bound checkout attempt against Billing Core ─── */}
                {paymentStage !== null && (
                    <>
                        <div
                            className="fixed inset-0 z-[130] bg-black/[.22] backdrop-blur-[3px]"
                            onClick={() => closePaymentCheck(false)}
                            aria-hidden="true"
                        />
                        {/* Scrollable shell: readable and scrollable on desktop and mobile */}
                        <div className="fixed inset-0 z-[140] overflow-y-auto p-4 md:p-5">
                            <div className="flex min-h-full items-center justify-center">
                                <div
                                    role="dialog"
                                    aria-modal="true"
                                    aria-labelledby="payment-result-title"
                                    className="w-full max-w-[430px] overflow-hidden rounded-[22px] border border-black/[.08] bg-[var(--color-surface)] shadow-[0_22px_70px_rgba(24,24,24,0.18)]"
                                >
                                    <div className="p-5 pt-6 md:px-7 md:pb-6 md:pt-7">
                                        <div className="mb-[18px] flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 flex-none items-center justify-center rounded-[12px] ${dialogTone}`}
                                            >
                                                {dialogIcon}
                                            </div>
                                            <div className="text-[12px] font-bold uppercase leading-4 tracking-[.04em] text-[var(--color-graphite)]">
                                                {dialogLabel}
                                            </div>
                                        </div>

                                        <div
                                            id="payment-result-title"
                                            className="text-[22px] font-bold leading-[1.14] tracking-[-.035em] text-[var(--color-ink)] md:text-[24px]"
                                        >
                                            {dialogTitle}
                                        </div>
                                        <p className="mt-3 text-[14px] leading-[1.55] text-[var(--color-graphite)]">
                                            {dialogBody}
                                        </p>

                                        {dialogDetails && (
                                            <div className="mt-[22px] grid gap-[9px] border-y border-[var(--color-line)] py-[15px]">
                                                {/* Тариф и срок — только из
                                                    свежего payload'а partial
                                                    reload: пока обновление
                                                    ждётся или сорвалось —
                                                    явная подпись вместо старых
                                                    данных. */}
                                                {freshCurrent?.status ===
                                                'ready' ? (
                                                    <>
                                                        {freshCurrent.current
                                                            .tariff_name && (
                                                            <div className="flex items-center justify-between gap-5 text-[13px]">
                                                                <span className="text-[var(--color-graphite)]">
                                                                    Тариф
                                                                </span>
                                                                <strong className="text-right text-[13px] font-semibold text-[var(--color-ink)]">
                                                                    {
                                                                        freshCurrent
                                                                            .current
                                                                            .tariff_name
                                                                    }
                                                                </strong>
                                                            </div>
                                                        )}
                                                        {freshCurrent.current
                                                            .expires_at && (
                                                            <div className="flex items-center justify-between gap-5 text-[13px]">
                                                                <span className="text-[var(--color-graphite)]">
                                                                    Активен до
                                                                </span>
                                                                <strong className="text-right text-[13px] font-semibold text-[var(--color-ink)]">
                                                                    {formatExpiry(
                                                                        freshCurrent
                                                                            .current
                                                                            .expires_at,
                                                                    )}
                                                                </strong>
                                                            </div>
                                                        )}
                                                    </>
                                                ) : (
                                                    <div className="text-[12px] leading-[1.45] text-[var(--color-graphite)]">
                                                        {freshCurrent?.status ===
                                                        'error'
                                                            ? 'Не удалось обновить данные подписки. Обновите страницу — тариф и дата окончания появятся после загрузки.'
                                                            : 'Обновляем данные подписки…'}
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {dialogNote !== null && (
                                            <div className="mt-3 text-[12px] leading-[1.45] text-[var(--color-graphite)]/75">
                                                {dialogNote}
                                            </div>
                                        )}
                                    </div>

                                    <div className="grid gap-[9px] p-5 pt-4 md:px-7 md:pb-6 md:pt-[18px]">
                                        {dialogFooter}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </>
                )}
            </AdminLayout>
        </>
    );
}
