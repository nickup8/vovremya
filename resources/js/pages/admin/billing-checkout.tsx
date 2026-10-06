import { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { QRCodeSVG } from 'qrcode.react';
import { toast } from 'sonner';
import AdminLayout from '@/layouts/AdminLayout';

/* ═══════════════ Types ═══════════════ */

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface CheckoutPlan {
    id: number | string;
    code: string;
    name: string;
    price_monthly: number;
}

interface CheckoutPrice {
    base: number;
    discount_percent: number;
    final: number;
    currency?: string;
}

interface PageProps {
    plan: CheckoutPlan;
    period_months: number;
    price: CheckoutPrice;
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

/* ═══════════════ Helpers ═══════════════ */

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

const fmt = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

const METHOD_MISMATCH_ERROR =
    'Есть незавершённый платёж другим способом. Сначала проверьте его статус';

const RETURN_BTN_CLASS =
    'mt-4 inline-flex h-[46px] w-full items-center justify-center rounded-[12px] bg-[var(--color-orange)] text-[15px] font-bold text-white transition-colors hover:bg-[var(--color-orange-600)]';

// Post-expiry verdict copy. The link is dead, the outcome is not confirmed:
// never offer a new payment here (no Init, no «Попробуйте снова»).
const EXPIRED_TITLE = 'Срок ссылки истёк. Проверяем результат оплаты';
const EXPIRED_BODY =
    'Если вы уже оплачивали — не запускайте оплату повторно. Результат появится после подтверждения платежа.';
const UNCONFIRMED_TITLE = 'Пока не удалось подтвердить оплату';
const UNCONFIRMED_BODY =
    'Если вы уже оплачивали — не запускайте оплату повторно. Нажмите «Проверить статус» или вернитесь позже: результат появится после подтверждения платежа.';

function pluralizePeriod(n: number): string {
    return `${n} ${MONTHS_RU[n] ?? 'месяцев'}`;
}

/** mm:ss остатка по абсолютному дедлайну (счёт вверх от 00:00 не бывает). */
function formatRemaining(ms: number): string {
    const total = Math.ceil(ms / 1000);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/** Абсолютный дедлайн из ISO с timezone; null = срока нет или он не разобран. */
function parseDeadline(iso: unknown): number | null {
    if (typeof iso !== 'string' || iso === '') {
        return null;
    }

    const deadline = Date.parse(iso);

    return Number.isNaN(deadline) ? null : deadline;
}

/* ═══════════════ Main Page ═══════════════ */

type PaymentMethod = 'sbp' | 'card';

type SbpStatus = 'waiting' | 'succeeded' | 'failed' | 'refunded';

interface SbpPayment {
    payload: string;
    paymentId: string | null;
    /**
     * Абсолютный срок жизни ссылки (epoch ms) из sbp_expires_at.
     * null = попытка создана до срока — таймер не показывается.
     */
    deadline: number | null;
}

export default function BillingCheckoutPage() {
    const props = usePage<PageProps>().props;
    const auth = props.auth;
    const { plan, period_months: periodMonths, price } = props;

    const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('sbp');
    const [autoRenew, setAutoRenew] = useState(false);
    const [loading, setLoading] = useState(false);
    const [checkoutError, setCheckoutError] = useState<string | null>(null);
    const [sbpPayment, setSbpPayment] = useState<SbpPayment | null>(null);
    const [sbpStatus, setSbpStatus] = useState<SbpStatus>('waiting');
    // Остаток до абсолютного дедлайна; null = срока нет (старый attempt).
    const [remainingMs, setRemainingMs] = useState<number | null>(null);
    // Исход после истечения срока не подтверждён — ручная проверка.
    const [expiredUnconfirmed, setExpiredUnconfirmed] = useState(false);

    const inFlightRef = useRef(false);

    const isSbp = paymentMethod === 'sbp';
    const monthlyEquiv = Math.round(price.final / periodMonths);
    const saving = price.base - price.final;
    // Только отображение: часы браузера никогда не меняют статус платежа.
    const linkExpired = remainingMs !== null && remainingMs <= 0;

    function failCheckout(message: string) {
        setCheckoutError(message);
        toast.error(message);
    }

    function selectPaymentMethod(method: PaymentMethod) {
        setPaymentMethod(method);
        setCheckoutError(null);
    }

    async function handleCheckout() {
        if (loading) {
            return;
        }

        setLoading(true);
        setCheckoutError(null);

        try {
            const res = await axios.post('/admin/checkout', {
                tariff_plan_id: plan.id,
                period_months: periodMonths,
                auto_renew: isSbp ? false : autoRenew,
                payment_method: paymentMethod,
            });

            const data = res.data ?? {};

            // The backend never answers this request with another method's
            // initiation; if it ever does, fail closed instead of opening
            // СБП under a card selection (or a card redirect under СБП).
            if (data.sbp_payload && paymentMethod !== 'sbp') {
                failCheckout(METHOD_MISMATCH_ERROR);

                return;
            }

            if (data.checkout_url && paymentMethod !== 'card') {
                failCheckout(METHOD_MISMATCH_ERROR);

                return;
            }

            if (data.sbp_payload) {
                const deadline = parseDeadline(data.sbp_expires_at);

                setSbpPayment({
                    payload: data.sbp_payload,
                    paymentId: data.payment_id ?? null,
                    deadline,
                });
                // Остаток — от абсолютного срока ответа; без него (старый
                // attempt) таймер не показывается вовсе.
                setRemainingMs(
                    deadline === null
                        ? null
                        : Math.max(0, deadline - Date.now()),
                );

                return;
            }

            if (data.checkout_url) {
                window.location.href = data.checkout_url;

                return;
            }

            failCheckout('Не удалось получить ссылку на оплату');
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                const errors = err.response.data?.errors ?? {};
                const first = Object.values(errors)[0];
                failCheckout(
                    Array.isArray(first) ? first[0] : 'Ошибка валидации',
                );
            } else if (
                axios.isAxiosError(err) &&
                err.response?.status === 403
            ) {
                failCheckout('Недостаточно прав');
            } else {
                failCheckout('Ошибка при создании платежа');
            }
        } finally {
            setLoading(false);
        }
    }

    // ── Countdown: пересчёт от абсолютного дедлайна бэкенда ──
    // Никогда не считаем «сейчас + 15 минут»: повторное открытие страницы
    // (и reuse попытки) продолжают исходный срок, а не начинают новый.
    // Первый расчёт делается в handleCheckout, здесь — только тики.
    useEffect(() => {
        const deadline = sbpPayment?.deadline ?? null;

        if (deadline === null) {
            return;
        }

        const tick = () => setRemainingMs(Math.max(0, deadline - Date.now()));

        const timer = window.setInterval(tick, 1000);
        // Возврат во вкладку: часы могли отстать (таймер спал) — считаем
        // заново от того же абсолютного дедлайна.
        const onVisibilityChange = () => tick();

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [sbpPayment?.deadline]);

    // ── SBP status polling: local Billing Core attempt only, never T-Bank ──
    useEffect(() => {
        if (
            !sbpPayment?.paymentId ||
            sbpStatus !== 'waiting' ||
            expiredUnconfirmed
        ) {
            return;
        }

        const paymentId = sbpPayment.paymentId;
        let stopped = false;
        let timer: number | null = null;

        async function check() {
            if (stopped || inFlightRef.current) {
                return;
            }

            inFlightRef.current = true;

            try {
                const res = await axios.get(
                    `/admin/billing/payment-status/${paymentId}`,
                );

                if (stopped) {
                    return;
                }

                const status = res.data?.status;
                // Локальный age-release (reconciliation_timeout) — не отказ
                // банка: после истечения срока это неизвестный исход.
                const undefinedOutcome = res.data?.undefined_outcome === true;

                if (status === 'succeeded') {
                    // Успех имеет приоритет над истечением срока.
                    setSbpStatus('succeeded');
                } else if (status === 'failed_terminal') {
                    if (linkExpired && undefinedOutcome) {
                        setExpiredUnconfirmed(true);
                    } else {
                        setSbpStatus('failed');
                    }
                } else if (
                    status === 'refunded' ||
                    status === 'partially_refunded'
                ) {
                    setSbpStatus('refunded');
                } else if (linkExpired && status === 'unknown') {
                    setExpiredUnconfirmed(true);
                }
                // processing / created / failed_retryable → ждём дальше
            } catch {
                if (stopped) {
                    return;
                }

                if (linkExpired) {
                    // Сеть недоступна после истечения срока — исход
                    // не подтверждён, статус по часам не угадываем.
                    setExpiredUnconfirmed(true);
                }
                // До истечения срока временный сбой polling — оплата не
                // «упала», ждём следующий тик
            } finally {
                inFlightRef.current = false;
            }
        }

        // Истечение срока (и повторная проверка по кнопке) — немедленный
        // запрос вместо ожидания следующего тика интервала.
        if (linkExpired) {
            void check();
        }

        timer = window.setInterval(() => {
            void check();
        }, 2000);

        return () => {
            stopped = true;

            if (timer !== null) {
                window.clearInterval(timer);
            }
        };
    }, [sbpPayment?.paymentId, sbpStatus, linkExpired, expiredUnconfirmed]);

    return (
        <>
            <Head title="Оплата Профи" />

            <AdminLayout title="Оплата Профи" auth={auth} fullBleed>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="w-full max-w-[640px] space-y-4 pb-6">
                        {/* ─── Back link ─── */}
                        <Link
                            href="/admin/billing"
                            className="inline-flex cursor-pointer items-center gap-1.5 text-[13px] font-semibold text-[var(--color-graphite)] transition-colors hover:text-[var(--color-ink)]"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                            Назад к тарифам
                        </Link>

                        {/* ─── Order summary ─── */}
                        <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                            <div className="text-[16px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                Оплата {plan.name}
                            </div>

                            <div className="mt-4 grid gap-3">
                                <div className="flex items-center justify-between gap-5 text-[13px]">
                                    <span className="text-[var(--color-graphite)]">
                                        Срок
                                    </span>
                                    <strong className="text-right font-semibold text-[var(--color-ink)]">
                                        {pluralizePeriod(periodMonths)}
                                    </strong>
                                </div>
                                <div className="flex items-center justify-between gap-5 text-[13px]">
                                    <span className="text-[var(--color-graphite)]">
                                        К оплате
                                    </span>
                                    <strong className="text-right text-[16px] font-bold text-[var(--color-ink)]">
                                        {fmt(price.final)}
                                    </strong>
                                </div>
                                <div className="flex items-center justify-between gap-5 text-[13px]">
                                    <span className="text-[var(--color-graphite)]">
                                        Эквивалент
                                    </span>
                                    <strong className="text-right font-semibold text-[var(--color-ink)]">
                                        ≈ {fmt(monthlyEquiv)} / мес
                                    </strong>
                                </div>
                                {saving > 0 && (
                                    <div className="flex items-center justify-between gap-5 text-[13px]">
                                        <span className="text-[var(--color-graphite)]">
                                            Экономия
                                        </span>
                                        <strong className="text-right font-semibold text-[var(--color-green)]">
                                            {fmt(saving)}
                                            {price.discount_percent > 0
                                                ? ` (−${price.discount_percent}%)`
                                                : ''}
                                        </strong>
                                    </div>
                                )}
                            </div>
                        </section>

                        {sbpPayment ? (
                            /* ─── SBP payment pending (stays on our page) ─── */
                            <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                                {sbpStatus === 'succeeded' ? (
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Оплата прошла
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            Профи активирован
                                        </p>
                                        <Link
                                            href="/admin/billing"
                                            className={RETURN_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : sbpStatus === 'failed' ? (
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Оплата не прошла
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            Попробуйте снова или выберите другой
                                            способ оплаты
                                        </p>
                                        <Link
                                            href="/admin/billing"
                                            className={RETURN_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : sbpStatus === 'refunded' ? (
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Платёж возвращён
                                        </div>
                                        <Link
                                            href="/admin/billing"
                                            className={RETURN_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : linkExpired && expiredUnconfirmed ? (
                                    /* Исход не подтверждён: без новой оплаты */
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            {UNCONFIRMED_TITLE}
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            {UNCONFIRMED_BODY}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setExpiredUnconfirmed(false)
                                            }
                                            className={RETURN_BTN_CLASS}
                                        >
                                            Проверить статус
                                        </button>
                                    </>
                                ) : linkExpired ? (
                                    /* Срок истёк: QR и ссылки в банк убраны,
                                       но polling этого же payment_id идёт */
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            {EXPIRED_TITLE}
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            {EXPIRED_BODY}
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Ожидаем подтверждение оплаты
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            Не закрывайте страницу до завершения
                                            оплаты
                                        </p>

                                        {remainingMs !== null && (
                                            <p
                                                data-testid="sbp-countdown"
                                                className="mt-1.5 text-[15px] leading-[21px] font-bold text-[var(--color-ink)]"
                                            >
                                                Осталось{' '}
                                                {formatRemaining(remainingMs)}
                                            </p>
                                        )}

                                        {/* Desktop (md+): QR rendered locally from sbp_payload */}
                                        <div className="mt-4 hidden md:block">
                                            <div className="inline-block rounded-[16px] border border-[var(--color-line)] bg-white p-4">
                                                <QRCodeSVG
                                                    value={sbpPayment.payload}
                                                    size={232}
                                                    bgColor="#ffffff"
                                                    fgColor="#000000"
                                                    level="M"
                                                    marginSize={2}
                                                    title="QR-код для оплаты через СБП"
                                                />
                                            </div>
                                            <p className="mt-3 text-[13px] leading-[18px] text-[var(--color-ink)]">
                                                Отсканируйте QR-код камерой
                                                телефона или в приложении банка
                                            </p>
                                            <p className="mt-1.5 text-[15px] leading-[21px] font-bold text-[var(--color-ink)]">
                                                Сумма: {fmt(price.final)}
                                            </p>
                                            <a
                                                href={sbpPayment.payload}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="mt-2 inline-block text-[12px] font-semibold text-[var(--color-orange)] hover:underline"
                                            >
                                                Открыть СБП
                                            </a>
                                        </div>

                                        {/* Mobile: open-bank action only, no QR */}
                                        <a
                                            href={sbpPayment.payload}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-4 inline-flex h-[46px] w-full items-center justify-center rounded-[12px] bg-[var(--color-orange)] text-[15px] font-bold text-white transition-colors hover:bg-[var(--color-orange-600)] md:hidden"
                                        >
                                            Открыть приложение банка
                                        </a>
                                    </>
                                )}
                            </section>
                        ) : (
                            <>
                                {/* ─── Payment method ─── */}
                                <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                                    <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                        Способ оплаты
                                    </div>

                                    <div
                                        role="radiogroup"
                                        aria-label="Способ оплаты"
                                        className="mt-3 grid gap-3"
                                    >
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={isSbp}
                                            onClick={() =>
                                                selectPaymentMethod('sbp')
                                            }
                                            className={`flex cursor-pointer items-center gap-3 rounded-[14px] border p-[14px] text-left ${
                                                isSbp
                                                    ? 'border-[var(--color-orange)] bg-[var(--color-orange-100)] shadow-[inset_0_0_0_1px_var(--color-orange)]'
                                                    : 'border-[var(--color-line)] bg-[var(--color-surface)] transition-colors hover:border-[var(--color-orange)]'
                                            }`}
                                        >
                                            <svg
                                                className={
                                                    isSbp
                                                        ? 'shrink-0 text-[var(--color-orange)]'
                                                        : 'shrink-0 text-[var(--color-graphite)]'
                                                }
                                                width="22"
                                                height="22"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <rect
                                                    x="3"
                                                    y="3"
                                                    width="7"
                                                    height="7"
                                                    rx="1"
                                                />
                                                <rect
                                                    x="14"
                                                    y="3"
                                                    width="7"
                                                    height="7"
                                                    rx="1"
                                                />
                                                <rect
                                                    x="3"
                                                    y="14"
                                                    width="7"
                                                    height="7"
                                                    rx="1"
                                                />
                                                <path d="M14 14h4v4h-4z" />
                                                <path d="M21 14v.01M14 21v.01M21 21v.01" />
                                            </svg>
                                            <div className="min-w-0">
                                                <div className="text-[13px] leading-[18px] font-semibold text-[var(--color-ink)]">
                                                    СБП
                                                </div>
                                                <div className="mt-[2px] text-[12px] leading-4 text-[var(--color-graphite)]">
                                                    Оплата через приложение
                                                    вашего банка
                                                </div>
                                            </div>
                                        </button>

                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={
                                                paymentMethod === 'card'
                                            }
                                            onClick={() =>
                                                selectPaymentMethod('card')
                                            }
                                            className={`flex cursor-pointer items-center gap-3 rounded-[14px] border p-[14px] text-left ${
                                                paymentMethod === 'card'
                                                    ? 'border-[var(--color-orange)] bg-[var(--color-orange-100)] shadow-[inset_0_0_0_1px_var(--color-orange)]'
                                                    : 'border-[var(--color-line)] bg-[var(--color-surface)] transition-colors hover:border-[var(--color-orange)]'
                                            }`}
                                        >
                                            <svg
                                                className={
                                                    paymentMethod === 'card'
                                                        ? 'shrink-0 text-[var(--color-orange)]'
                                                        : 'shrink-0 text-[var(--color-graphite)]'
                                                }
                                                width="22"
                                                height="22"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <rect
                                                    width="20"
                                                    height="14"
                                                    x="2"
                                                    y="5"
                                                    rx="2"
                                                />
                                                <path d="M2 10h20" />
                                            </svg>
                                            <div className="min-w-0">
                                                <div className="text-[13px] leading-[18px] font-semibold text-[var(--color-ink)]">
                                                    Банковская карта
                                                </div>
                                                <div className="mt-[2px] text-[12px] leading-4 text-[var(--color-graphite)]">
                                                    Оплата на защищённой
                                                    странице T-Bank
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </section>

                                {/* ─── Auto renewal consent ─── */}
                                <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                                    <label className="flex cursor-pointer items-start gap-[10px]">
                                        <input
                                            type="checkbox"
                                            checked={isSbp ? false : autoRenew}
                                            disabled={isSbp}
                                            onChange={(e) =>
                                                setAutoRenew(e.target.checked)
                                            }
                                            className="mt-[2px] h-4 w-4 shrink-0 cursor-pointer accent-[var(--color-orange)] disabled:cursor-not-allowed"
                                        />
                                        <span className="min-w-0">
                                            <span className="block text-[13px] leading-[18px] font-semibold text-[var(--color-ink)]">
                                                Продлевать автоматически
                                            </span>
                                            {isSbp ? (
                                                <span className="mt-[3px] block text-[12px] leading-[16px] text-[var(--color-graphite)]">
                                                    Автопродление через СБП
                                                    появится позже.
                                                </span>
                                            ) : (
                                                <span className="mt-[3px] block text-[12px] leading-[16px] text-[var(--color-graphite)]">
                                                    После окончания оплаченного
                                                    периода ИРСИ сможет
                                                    автоматически списать
                                                    стоимость следующего периода
                                                    с сохранённого способа
                                                    оплаты. Автопродление можно
                                                    будет отключить до
                                                    следующего списания.
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                    {!isSbp && (
                                        <a
                                            href="/offer#auto-renew"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-2.5 inline-block text-[12px] font-semibold text-[var(--color-orange)] hover:underline"
                                        >
                                            Условия автопродления
                                        </a>
                                    )}
                                </section>

                                {/* ─── Checkout error (visible on any width) ─── */}
                                {checkoutError && (
                                    <div
                                        role="alert"
                                        className="rounded-[12px] bg-[var(--color-red-bg)] px-4 py-3 text-[13px] leading-[18px] font-semibold text-[var(--color-red)]"
                                    >
                                        {checkoutError}
                                    </div>
                                )}

                                {/* ─── CTA ─── */}
                                <button
                                    type="button"
                                    onClick={handleCheckout}
                                    disabled={loading}
                                    className="h-[46px] w-full cursor-pointer rounded-[12px] border-0 bg-[var(--color-orange)] text-[15px] font-bold text-white transition-colors hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {loading
                                        ? isSbp
                                            ? 'Создание платежа…'
                                            : 'Перенаправление…'
                                        : `Оплатить ${fmt(price.final)}`}
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </AdminLayout>
        </>
    );
}
