import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
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

/**
 * Read-only identification of the workspace's in-flight attempt for this
 * plan (server props on reload/back). Not a ready-to-pay form: no payload,
 * no verdict; payment_id is null until the provider id is attached.
 */
interface PendingAttempt {
    payment_id: string | null;
    period_months: number | null;
    status: string;
}

interface PageProps {
    plan: CheckoutPlan;
    period_months: number;
    price: CheckoutPrice;
    pending_attempt?: PendingAttempt | null;
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

// Основной оранжевый CTA полной ширины: действия на терминальных
// экранах и главная кнопка экрана подтверждённого отказа.
const PRIMARY_BTN_CLASS =
    'mt-4 inline-flex h-[46px] w-full items-center justify-center rounded-[12px] bg-[var(--color-orange)] text-[15px] font-bold text-white transition-colors hover:bg-[var(--color-orange-600)]';

// Вторичное действие того же размера: уход к тарифам с экрана отказа,
// когда рядом стоит основная кнопка новой оплаты.
const SECONDARY_BTN_CLASS =
    'mt-3 inline-flex h-[46px] w-full items-center justify-center rounded-[12px] border border-[var(--color-line)] bg-[var(--color-surface)] text-[15px] font-semibold text-[var(--color-ink)] transition-colors hover:border-[var(--color-orange)] hover:text-[var(--color-orange)]';

// Unconfirmed verdict copy: the outcome is not confirmed — never offer a
// new payment here (no Init, no «Попробуйте снова»).
const EXPIRED_TITLE = 'Срок ссылки истёк. Проверяем результат оплаты';
const EXPIRED_BODY =
    'Если вы уже оплачивали — не запускайте оплату повторно. Результат появится после подтверждения платежа.';
const UNCONFIRMED_TITLE = 'Пока не удалось подтвердить оплату';
const UNCONFIRMED_BODY =
    'Если вы уже оплачивали — не запускайте оплату повторно. Нажмите «Проверить статус» или вернитесь позже: результат появится после подтверждения платежа.';

// Наш UX-бюджет авто-проверки после истечения срока (как в PR9), а не
// требование банка: максимум 25 запросов раз в 2 секунды, затем —
// неподтверждённый исход. Ручная проверка начинает новый цикл.
const EXPIRED_CHECK_BUDGET = 25;
const STATUS_POLL_INTERVAL_MS = 2000;

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

type SbpStatus =
    | 'waiting'
    | 'succeeded'
    | 'failed'
    | 'refunded'
    | 'partially_refunded';

interface SbpPayment {
    /**
     * null = попытка возобновлена без initiation (reload/back): проверяем
     * статус существующего платежа, QR и ссылку в банк не показываем.
     */
    payload: string | null;
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
    // Исход не подтверждён (сеть/unknown/reconciliation_timeout) — ручная
    // проверка. Независимо от дедлайна, включая attempts без срока.
    const [unconfirmed, setUnconfirmed] = useState(false);

    // Reload/Back с незавершённой попыткой сервера: показываем вход к её
    // проверке, а не новую готовую к оплате форму.
    const pendingAttempt = props.pending_attempt ?? null;
    const [resumedAttemptScreen, setResumedAttemptScreen] = useState(
        pendingAttempt !== null,
    );
    // Период именно проверяемой попытки — кнопка после подтверждённого
    // отказа ведёт на checkout с ним, а не с периодом URL. null = period
    // восстановить нельзя → выбор периода на странице тарифов.
    const [attemptPeriodMonths, setAttemptPeriodMonths] = useState<
        number | null
    >(null);

    const inFlightRef = useRef(false);
    // Клик по «Проверить статус»: начать новый цикл немедленным запросом.
    const retryRequestedRef = useRef(false);
    // Синхронный guard повторного POST: состояние loading применяется
    // следующим рендером, ref — сразу в этом же клике.
    const submittingRef = useRef(false);

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
        // Пока POST/redirect в полёте способ зафиксирован
        if (loading || submittingRef.current) {
            return;
        }

        setPaymentMethod(method);
        setCheckoutError(null);
    }

    /**
     * Подтверждённый отказ → снова форма выбора на этой же странице.
     *
     * Сбрасывает всё состояние старой попытки: экран СБП, статус, таймер,
     * unconfirmed, ошибку checkout'а и флаг ручной проверки. Способ — СБП
     * по умолчанию, автопродление выключено; тариф/период/цена живут в
     * props и не меняются. Никаких запросов: следующий явный клик
     * «Оплатить» идёт через существующий handleCheckout. inFlightRef не
     * трогаем — незавершённый запрос старой попытки снимет флаг сам в
     * finally, а её поздний ответ гасится cleanup-флагом polling'а.
     */
    function resetToPaymentForm() {
        setSbpPayment(null);
        setSbpStatus('waiting');
        setRemainingMs(null);
        setUnconfirmed(false);
        setCheckoutError(null);
        retryRequestedRef.current = false;
        setPaymentMethod('sbp');
        setAutoRenew(false);
        setAttemptPeriodMonths(null);
        // Экран существующей попытки убран: форма выбора — вместо него
        setResumedAttemptScreen(false);
    }

    /**
     * Возобновление существующей попытки сервера (reload/back): тот же
     * paymentId уходит в существующий polling, без нового POST/Init и без
     * QR — payload этой попытки сервер в read-only props не отдаёт.
     */
    function resumePendingAttempt() {
        const paymentId = pendingAttempt?.payment_id ?? null;

        if (paymentId === null) {
            return;
        }

        retryRequestedRef.current = true;
        setAttemptPeriodMonths(pendingAttempt?.period_months ?? null);
        setSbpPayment({
            payload: null,
            paymentId,
            deadline: null,
        });
    }

    /**
     * Путь после подтверждённого отказа: к форме выбора способа оплаты с
     * периодом ЭТОЙ попытки. Период, который нельзя достоверно
     * восстановить, не подставляется — уходим к выбору периода на странице
     * тарифов. Тот же период, что уже на странице, — форма без переезда.
     */
    function chooseMethodAfterFailure() {
        if (attemptPeriodMonths === null) {
            router.get('/admin/billing');

            return;
        }

        if (attemptPeriodMonths !== periodMonths) {
            router.get(
                `/admin/billing/checkout?period_months=${attemptPeriodMonths}`,
            );

            return;
        }

        resetToPaymentForm();
    }

    async function handleCheckout() {
        if (submittingRef.current) {
            return;
        }

        submittingRef.current = true;
        setLoading(true);
        setCheckoutError(null);

        // После присвоения location.href страница уходит в банк: CTA
        // остаётся «Перенаправление…» и disabled до самого ухода.
        let redirecting = false;

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

                setAttemptPeriodMonths(periodMonths);
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
                redirecting = true;
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
            if (!redirecting) {
                setLoading(false);
                submittingRef.current = false;
            }
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
        if (!sbpPayment?.paymentId || sbpStatus !== 'waiting' || unconfirmed) {
            return;
        }

        const paymentId = sbpPayment.paymentId;
        let stopped = false;
        let timer: number | null = null;
        // Бюджет считает только запросы после истечения срока; новый цикл
        // (истечение или «Проверить статус») начинается снова с нуля.
        let expiredChecks = 0;

        async function check() {
            if (stopped || inFlightRef.current) {
                return;
            }

            if (linkExpired) {
                if (expiredChecks >= EXPIRED_CHECK_BUDGET) {
                    setUnconfirmed(true);

                    return;
                }

                expiredChecks += 1;
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
                // банка: всегда неподтверждённый исход, независимо от
                // дедлайна (включая attempts без срока).
                const undefinedOutcome = res.data?.undefined_outcome === true;

                if (status === 'succeeded') {
                    // Успех имеет приоритет над истечением срока.
                    setSbpStatus('succeeded');
                } else if (status === 'failed_terminal') {
                    if (undefinedOutcome) {
                        setUnconfirmed(true);
                    } else {
                        setSbpStatus('failed');
                    }
                } else if (status === 'refunded') {
                    // Полный возврат — терминальный экран, polling остановлен
                    setSbpStatus('refunded');
                } else if (status === 'partially_refunded') {
                    // Частичный возврат — свой экран, polling тоже остановлен
                    setSbpStatus('partially_refunded');
                } else if (linkExpired) {
                    if (
                        status === 'unknown' ||
                        expiredChecks >= EXPIRED_CHECK_BUDGET
                    ) {
                        // Исход неизвестен либо UX-бюджет авто-проверки
                        // исчерпан — ручная проверка начинает новый цикл.
                        setUnconfirmed(true);
                    }
                    // created / processing / failed_retryable → ждём дальше
                }
                // До истечения срока (и без срока): created / processing /
                // failed_retryable / unknown → ждём дальше, бюджет не нужен
            } catch {
                if (stopped) {
                    return;
                }

                if (linkExpired) {
                    // Сеть недоступна после истечения срока — исход
                    // не подтверждён, статус по часам не угадываем.
                    setUnconfirmed(true);
                }
                // До истечения срока временный сбой polling — оплата не
                // «упала», ждём следующий тик
            } finally {
                inFlightRef.current = false;
            }
        }

        // Истечение срока или ручная проверка — немедленный запрос вместо
        // ожидания следующего тика интервала.
        if (linkExpired || retryRequestedRef.current) {
            void check();
        }

        retryRequestedRef.current = false;

        timer = window.setInterval(() => {
            void check();
        }, STATUS_POLL_INTERVAL_MS);

        return () => {
            stopped = true;

            if (timer !== null) {
                window.clearInterval(timer);
            }
        };
    }, [sbpPayment?.paymentId, sbpStatus, linkExpired, unconfirmed]);

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
                                            className={PRIMARY_BTN_CLASS}
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
                                        {/* Подтверждённый отказ банка — новая
                                            оплата возможна; unconfirmed /
                                            локальное истечение сюда не
                                            попадают (другие ветки ниже). Кнопка
                                            ведёт к форме выбора способа с
                                            периодом этой попытки. */}
                                        <button
                                            type="button"
                                            onClick={chooseMethodAfterFailure}
                                            className={PRIMARY_BTN_CLASS}
                                        >
                                            Выбрать способ оплаты
                                        </button>
                                        <Link
                                            href="/admin/billing"
                                            className={SECONDARY_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : sbpStatus === 'refunded' ? (
                                    /* Возврат: polling уже остановлен, экран
                                       не обещает ни успеха, ни новой оплаты и
                                       ничего не утверждает о подписке. */
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Платёж возвращён
                                        </div>
                                        <Link
                                            href="/admin/billing"
                                            className={PRIMARY_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : sbpStatus === 'partially_refunded' ? (
                                    /* Частичный возврат — отличим от полного
                                       тем же спокойным тоном: без success и
                                       без новой оплаты, без утверждений о
                                       подписке. */
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Частичный возврат
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            Возвращена часть платежа
                                        </p>
                                        <Link
                                            href="/admin/billing"
                                            className={PRIMARY_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : unconfirmed ? (
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
                                            onClick={() => {
                                                retryRequestedRef.current = true;
                                                setUnconfirmed(false);
                                            }}
                                            className={PRIMARY_BTN_CLASS}
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
                                            {sbpPayment.payload === null
                                                ? 'Проверяем статус платежа'
                                                : 'Ожидаем подтверждение оплаты'}
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            {sbpPayment.payload === null
                                                ? 'Подтверждаем, что платёж обработан. Не запускайте оплату повторно.'
                                                : 'Не закрывайте страницу до завершения оплаты'}
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

                                        {/* Возобновлённая попытка без initiation
                                            (reload/back): проверка статуса без
                                            QR и ссылок в банк. */}
                                        {sbpPayment.payload === null && (
                                            <Link
                                                href="/admin/billing"
                                                className={SECONDARY_BTN_CLASS}
                                            >
                                                Вернуться к тарифам
                                            </Link>
                                        )}

                                        {/* Desktop (md+): QR rendered locally from sbp_payload */}
                                        {sbpPayment.payload !== null && (
                                            <div className="mt-4 hidden md:block">
                                                <div className="inline-block rounded-[16px] border border-[var(--color-line)] bg-white p-4">
                                                    <QRCodeSVG
                                                        value={
                                                            sbpPayment.payload
                                                        }
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
                                                    телефона или в приложении
                                                    банка
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
                                        )}

                                        {/* Mobile: open-bank action only, no QR */}
                                        {sbpPayment.payload !== null && (
                                            <a
                                                href={sbpPayment.payload}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="mt-4 inline-flex h-[46px] w-full items-center justify-center rounded-[12px] bg-[var(--color-orange)] text-[15px] font-bold text-white transition-colors hover:bg-[var(--color-orange-600)] md:hidden"
                                            >
                                                Открыть приложение банка
                                            </a>
                                        )}
                                    </>
                                )}
                            </section>
                        ) : resumedAttemptScreen && pendingAttempt !== null ? (
                            /* ─── Existing unfinished attempt of this
                               workspace+plan (reload/back): an entry to its
                               status check — never a fresh ready-to-pay form.
                               No POST, no Init, no automatic check. ─── */
                            <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                                {pendingAttempt.payment_id ? (
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Незавершённый платёж
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            Есть платёж, статус которого ещё не
                                            подтверждён. Проверьте его, прежде
                                            чем запускать оплату заново.
                                        </p>
                                        <button
                                            type="button"
                                            onClick={resumePendingAttempt}
                                            className={PRIMARY_BTN_CLASS}
                                        >
                                            Проверить статус
                                        </button>
                                        <Link
                                            href="/admin/billing"
                                            className={SECONDARY_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
                                    </>
                                ) : (
                                    <>
                                        <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                            Статус уточняется
                                        </div>
                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                            Этот платёж ещё обрабатывается.
                                            Проверка станет доступна после
                                            подтверждения — не запускайте оплату
                                            повторно.
                                        </p>
                                        <Link
                                            href="/admin/billing"
                                            className={SECONDARY_BTN_CLASS}
                                        >
                                            Вернуться к тарифам
                                        </Link>
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
                                            disabled={loading}
                                            onClick={() =>
                                                selectPaymentMethod('sbp')
                                            }
                                            className={`flex cursor-pointer items-center gap-3 rounded-[14px] border p-[14px] text-left disabled:cursor-not-allowed disabled:opacity-60 ${
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
                                            disabled={loading}
                                            onClick={() =>
                                                selectPaymentMethod('card')
                                            }
                                            className={`flex cursor-pointer items-center gap-3 rounded-[14px] border p-[14px] text-left disabled:cursor-not-allowed disabled:opacity-60 ${
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
                                            disabled={isSbp || loading}
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
