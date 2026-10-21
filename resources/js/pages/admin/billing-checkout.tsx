import { useCallback, useEffect, useRef, useState } from 'react';
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
    /** 'sbp' | 'card' | null; null = способ неизвестен — не угадываем. */
    method?: string | null;
    /**
     * Сохранённый SBP-payload ЭТОЙ попытки (только пока она ждёт оплаты):
     * продолжение без нового Init и без продления дедлайна.
     */
    sbp_payload?: string | null;
    /** Абсолютный срок этой же ссылки; истёкший срок передаётся как есть. */
    sbp_expires_at?: string | null;
    /**
     * Сохранённый card-checkout_url ЭТОЙ попытки (только пока она ждёт
     * оплаты): продолжение без POST /admin/checkout и без нового Init.
     */
    checkout_url?: string | null;
    /**
     * Абсолютный срок карточной ссылки ЭТОЙ попытки (epoch из
     * card_expires_at); истёкший срок передаётся как есть. null =
     * попытка создана до срока — таймер и истечение не показываются.
     */
    card_expires_at?: string | null;
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

// Наш UX-бюджет авто-проверки (как в PR9), а не требование банка:
// максимум 25 запросов раз в 2 секунды, затем — неподтверждённый исход.
// Бюджет действует после истечения срока ссылки и в ожидании без
// платёжной ссылки (payload === null). Ручная проверка SBP-потока
// начинает новый ограниченный цикл. Возобновлённая карта бюджетом не
// опрашивается вообще: её проверка — только POST /check по кнопке.
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
    // Весь отображаемый платёжный контекст (SBP-экран/терминальный экран
    // карточного вердикта, его countdown, unconfirmed) хранится ВМЕСТЕ с
    // ключом попытки — по образцу manualChecking/cardCheckNotice. Смена
    // pending_attempt в props сама по себе скрывает чужой контекст: новый
    // ключ не находит своё состояние и рендерит pending B вместо старого
    // sbpPayment. Поздний GET гасится cleanup-флагом polling'а; поздний
    // POST — epoch (см. invalidateManualStatusCheck).
    const [sbpViewState, setSbpViewState] = useState<{
        key: string;
        payment: SbpPayment | null;
        status: SbpStatus;
        remainingMs: number | null;
        unconfirmed: boolean;
    } | null>(null);
    // Ручная проверка через POST /check (координационный lock+cooldown на
    // сервере): ref-guard гасит повторный клик до ре-рендера, manualChecking
    // остаётся только UI-флагом loading/disabled. Хранится вместе с ключом
    // попытки: смена попытки в props сама по себе гасит busy — без
    // эффектов-сбросов и без setState во время рендера.
    const [manualCheckingState, setManualCheckingState] = useState<{
        key: string;
        busy: boolean;
    }>({ key: '', busy: false });
    // Подпись последней POST-проверки карточной попытки на её же экране:
    // 'none' — ещё не проверяли; 'cooldown' — checked=false при
    // нетерминальном статусе (локальный перечит, не свежий ответ банка).
    // Keyed: чужая попытка никогда не показывает старую подпись.
    const [cardCheckNoticeState, setCardCheckNoticeState] = useState<{
        key: string;
        notice: 'none' | 'unconfirmed' | 'request_error' | 'cooldown';
    } | null>(null);
    // «Сейчас» для карточного countdown: обновляется только тиком
    // интервала или visibilitychange (колбэки, не effect body). Сам
    // абсолютный срок НИКОГДА не копируется в состояние — он приходит
    // из props попытки. null-срок (старый attempt) не показывает таймер.
    const [cardNow, setCardNow] = useState(() => Date.now());

    // Reload/Back с незавершённой попыткой сервера: показываем вход к её
    // проверке, а не новую готовую к оплате форму.
    const pendingAttempt = props.pending_attempt ?? null;
    // Ключ идентичности возобновляемой попытки: смена любого из полей —
    // уже ДРУГАЯ попытка, её per-attempt состояние не наследуется.
    const pendingAttemptKey =
        (pendingAttempt?.payment_id ?? 'no-id') +
        '|' +
        (pendingAttempt?.card_expires_at ?? '');
    // Пользователь явно увёл форму с ЭТОГО ключа попытки; новый ключ
    // попытки снова показывает экран возобновления.
    const [dismissedAttemptKey, setDismissedAttemptKey] = useState<
        string | null
    >(null);
    const resumedAttemptScreen =
        pendingAttempt !== null && dismissedAttemptKey !== pendingAttemptKey;
    // Период именно проверяемой попытки — кнопка после подтверждённого
    // отказа ведёт на checkout с ним, а не с периодом URL. null = period
    // восстановить нельзя → выбор периода на странице тарифов. Keyed store:
    // смена попытки сама по себе возвращает null.
    const [attemptPeriodState, setAttemptPeriodState] = useState<{
        key: string;
        months: number | null;
    } | null>(null);
    const attemptPeriodMonths =
        attemptPeriodState?.key === pendingAttemptKey
            ? attemptPeriodState.months
            : null;
    // Keyed-вывод платёжного контекста: чужой (прошлой) попытки контекст
    // не виден — без эффектов-сбросов и без setState во время рендера.
    const sbpView =
        sbpViewState?.key === pendingAttemptKey
            ? sbpViewState
            : {
                  payment: null,
                  status: 'waiting' as SbpStatus,
                  remainingMs: null,
                  unconfirmed: false,
              };
    const sbpPayment = sbpView.payment;
    const sbpStatus = sbpView.status;
    const remainingMs = sbpView.remainingMs;
    const unconfirmed = sbpView.unconfirmed;
    // Сеттеры пишут под текущий ключ попытки; несвойственный ключ
    // начинается с чистого базового состояния (см. sbpView выше).
    // useCallback по ключу: эффекты видят стабильную ссылку между
    // ре-рендерами и получают новый сеттер только при смене попытки.
    const patchSbpView = useCallback(
        (
            patch: Partial<{
                payment: SbpPayment | null;
                status: SbpStatus;
                remainingMs: number | null;
                unconfirmed: boolean;
            }>,
        ) =>
            setSbpViewState((prev) => ({
                key: pendingAttemptKey,
                payment: null,
                status: 'waiting' as SbpStatus,
                remainingMs: null,
                unconfirmed: false,
                ...(prev?.key === pendingAttemptKey ? prev : null),
                ...patch,
            })),
        [pendingAttemptKey],
    );
    const setSbpPayment = useCallback(
        (payment: SbpPayment | null) => patchSbpView({ payment }),
        [patchSbpView],
    );
    const setSbpStatus = useCallback(
        (status: SbpStatus) => patchSbpView({ status }),
        [patchSbpView],
    );
    const setRemainingMs = useCallback(
        (value: number | null) => patchSbpView({ remainingMs: value }),
        [patchSbpView],
    );
    const setUnconfirmed = useCallback(
        (value: boolean) => patchSbpView({ unconfirmed: value }),
        [patchSbpView],
    );

    // Keyed-сеттеры: вызывающий код не меняется, «ключом» привязки к
    // попытке служит текущий pendingAttemptKey в момент вызова.
    const setManualChecking = (busy: boolean) =>
        setManualCheckingState({ key: pendingAttemptKey, busy });
    const manualChecking =
        manualCheckingState.key === pendingAttemptKey &&
        manualCheckingState.busy;
    const setCardCheckNotice = (
        notice: 'none' | 'unconfirmed' | 'request_error' | 'cooldown',
    ) => setCardCheckNoticeState({ key: pendingAttemptKey, notice });
    const cardCheckNotice =
        cardCheckNoticeState?.key === pendingAttemptKey
            ? cardCheckNoticeState.notice
            : 'none';
    const setAttemptPeriodMonths = (months: number | null) =>
        setAttemptPeriodState({ key: pendingAttemptKey, months });

    const inFlightRef = useRef(false);
    // Клик по «Проверить статус»: начать новый цикл немедленным запросом.
    const retryRequestedRef = useRef(false);
    // Синхронный guard повторного POST: состояние loading применяется
    // следующим рендером, ref — сразу в этом же клике.
    const submittingRef = useRef(false);
    // То же для ручной проверки: один POST на попытку, даже если два клика
    // уложились в один тик, до перерисовки кнопки.
    const manualCheckingRef = useRef(false);
    // Поколение контекста попытки: сброс checkout'а, новый paymentId и
    // unmount двигают счётчик — поздний ответ или ошибка чужого поколения
    // не меняют экран и не возвращают старое ожидание.
    const manualCheckEpochRef = useRef(0);

    /**
     * Ручная проверка устарела: сбросили checkout-попытку, сменили её
     * paymentId, страница уходит. Поздний ответ или ошибка чужого
     * поколения замолкают — экран остаётся тем, что видит пользователь.
     * Ref-guard снимается сразу; UI-флаг loading сбрасывает тот вызывающий,
     * чей экран продолжает жить (reset).
     */
    const invalidateManualStatusCheck = useCallback(() => {
        manualCheckEpochRef.current += 1;
        manualCheckingRef.current = false;
    }, []);

    // Unmount: поздний ответ не трогает размонтированный экран.
    useEffect(
        () => () => {
            invalidateManualStatusCheck();
        },
        [invalidateManualStatusCheck],
    );

    // Смена попытки в props (другой payment_id / другой карточный срок):
    // поколение ручной проверки двигается в эффекте — refs только там, —
    // поздний ответ старой попытки замолкает. Остальное per-attempt
    // состояние keyed-выводно (см. выше) и отдельного сброса не требует.
    useEffect(() => {
        invalidateManualStatusCheck();
    }, [pendingAttemptKey, invalidateManualStatusCheck]);

    // Абсолютный срок карточной ссылки ЭТОЙ попытки — только из props,
    // без копии в состоянии: reload/reuse продолжают исходный срок
    // попытки, а не начинают новый. null = срока нет (старый attempt) —
    // таймер не показывается и истечение никогда не наступает.
    const cardDeadline = parseDeadline(pendingAttempt?.card_expires_at ?? null);
    const cardRemainingMs =
        cardDeadline === null ? null : Math.max(0, cardDeadline - cardNow);

    // Карточный pending-экран активен, пока он и есть экран попытки:
    // форма после сброса и терминальный экран тикер не держат.
    const cardScreenActive =
        sbpPayment === null && resumedAttemptScreen && pendingAttempt !== null;

    // ── Card countdown: пересчёт от абсолютного карточного срока ──
    // Тик интервала и возврат во вкладку двигают только «сейчас» — часы
    // браузера никогда не меняют статус платежа и не трогают свежие
    // props попытки.
    useEffect(() => {
        if (cardDeadline === null || !cardScreenActive) {
            return;
        }

        const tick = () => setCardNow(Date.now());

        // Свежий пересчёт «сейчас» сразу после смены экрана/попытки —
        // макрозадача-колбэк, а не setState в body эффекта.
        const sync = window.setTimeout(tick, 0);
        const timer = window.setInterval(tick, 1000);
        const onVisibilityChange = () => tick();

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            window.clearTimeout(sync);
            window.clearInterval(timer);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [cardDeadline, cardScreenActive]);

    const isSbp = paymentMethod === 'sbp';
    const monthlyEquiv = Math.round(price.final / periodMonths);
    const saving = price.base - price.final;
    // Только отображение: часы браузера никогда не меняют статус платежа.
    const linkExpired = remainingMs !== null && remainingMs <= 0;
    // Карточный срок: тоже только отображение. Успех и отказ приходят
    // исключительно из подтверждённых ответов сервера, не из часов.
    const cardExpired = cardRemainingMs !== null && cardRemainingMs <= 0;

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
     * finally, а её поздний ответ гасится cleanup-флагом polling'а. Ручная
     * проверка в полёте инвалидируется: её поздний ответ не перекрашивает
     * уже сброшенную форму.
     */
    function resetToPaymentForm() {
        invalidateManualStatusCheck();
        setManualChecking(false);
        setSbpPayment(null);
        setSbpStatus('waiting');
        setRemainingMs(null);
        setUnconfirmed(false);
        setCheckoutError(null);
        setCardCheckNotice('none');
        retryRequestedRef.current = false;
        setPaymentMethod('sbp');
        setAutoRenew(false);
        setAttemptPeriodMonths(null);
        // Экран существующей попытки убран: форма выбора — вместо него.
        // Карточный срок остаётся в props, но тикер гаснет вместе с
        // карточным экраном (cardScreenActive); новый ключ попытки снова
        // откроет возобновление.
        setDismissedAttemptKey(pendingAttemptKey);
    }

    /**
     * Возобновление существующей попытки сервера (reload/back): тот же
     * paymentId уходит в существующий polling, без нового POST/Init. Если
     * сервер отдал сохранённый SBP-payload с исходным абсолютным дедлайном —
     * продолжаем ту же ссылку (QR и «Открыть банк»); payload null (карта
     * без сохранённой ссылки, неизвестный способ, released попытка) —
     * только проверка статуса.
     */
    function resumePendingAttempt() {
        const paymentId = pendingAttempt?.payment_id ?? null;

        if (paymentId === null) {
            return;
        }

        const payload = pendingAttempt?.sbp_payload ?? null;
        const deadline = parseDeadline(pendingAttempt?.sbp_expires_at ?? null);

        // Контекст попытки меняется: проверка, начатая раньше, чужая
        invalidateManualStatusCheck();
        retryRequestedRef.current = true;
        setAttemptPeriodMonths(pendingAttempt?.period_months ?? null);
        setSbpPayment({
            payload,
            paymentId,
            deadline,
        });
        // Остаток — от исходного дедлайна попытки; истёкшая ссылка сразу
        // показывается как истёкшая, без продления и без нового Init.
        setRemainingMs(
            deadline === null ? null : Math.max(0, deadline - Date.now()),
        );
    }

    /**
     * Продолжение карточной оплаты: сохранённый checkout_url ЭТОЙ же
     * попытки. Никакого POST /admin/checkout и нового Init — только переход
     * по той же защищённой странице T-Bank, которую банк выдал при Init.
     * Срок проверяется в момент клика по исходному значению из props:
     * после истечения кнопка не продолжает оплату, даже если таймер отстал.
     */
    function resumeCardCheckout() {
        const checkoutUrl = pendingAttempt?.checkout_url ?? null;

        if (checkoutUrl === null) {
            return;
        }

        const deadline = parseDeadline(pendingAttempt?.card_expires_at ?? null);

        if (deadline !== null && Date.now() >= deadline) {
            // Часы не отменяют платёж сами — экран переходит в
            // истёкшее состояние, но вердикт ждём от проверки
            setCardNow(Date.now());

            return;
        }

        window.location.href = checkoutUrl;
    }

    /**
     * «Проверить оплату» на карточном pending-экране: единственный запрос —
     * существующий POST /check той же попытки, без предварительных GET и
     * без нового Init. Экран на всё время запроса не меняется: кнопки
     * disabled, кнопка проверки показывает «Проверяем…». Ответ без
     * финального вердикта, ошибка сети и cooldown (checked=false) дают
     * подпись на этом же экране, а сохранённая ссылка продолжения оплаты
     * остаётся доступной. GET-polling не запускается ни во время, ни
     * после проверки.
     *
     * Финальный вердикт (успех, подтверждённый отказ, возвраты) применяется
     * независимо от checked и уводит на существующие терминальные экраны.
     * Терминальный экран убирает ссылку продолжения.
     *
     * Повторный клик гасит ref-guard до ре-рендера; сброс checkout'а и
     * unmount двигают поколение — поздний ответ чужой попытки замолкает.
     */
    async function startCardPendingStatusCheck() {
        const paymentId = pendingAttempt?.payment_id ?? null;

        // Ref-guard до invalidate: тот снимает manualCheckingRef, и двойной
        // клик до ре-рендера ушёл бы вторым POST
        if (paymentId === null || manualCheckingRef.current) {
            return;
        }

        // Контекст попытки меняется: проверка, начатая раньше, чужая
        invalidateManualStatusCheck();
        const epoch = manualCheckEpochRef.current;
        const isCurrent = () => manualCheckEpochRef.current === epoch;

        manualCheckingRef.current = true;
        setManualChecking(true);
        setCardCheckNotice('none');
        setAttemptPeriodMonths(pendingAttempt?.period_months ?? null);

        try {
            const res = await axios.post(
                `/admin/billing/payment-status/${paymentId}/check`,
            );

            if (!isCurrent()) {
                // Поздний ответ уже чужой попытки — экран не меняем
                return;
            }

            const status = res.data?.status;
            const checked = res.data?.checked !== false;
            const undefinedOutcome = res.data?.undefined_outcome === true;

            if (status === 'succeeded') {
                enterCardTerminalScreen(paymentId, 'succeeded');
            } else if (status === 'refunded') {
                enterCardTerminalScreen(paymentId, 'refunded');
            } else if (status === 'partially_refunded') {
                enterCardTerminalScreen(paymentId, 'partially_refunded');
            } else if (status === 'failed_terminal' && !undefinedOutcome) {
                // Подтверждённый отказ банка — терминальный экран; undefined
                // — локальный age-release, не вердикт, падает ниже
                enterCardTerminalScreen(paymentId, 'failed');
            } else if (!checked) {
                // Lock/cooldown пропустили провайдера: это не свежий ответ
                // банка, а просто перечитанный локальный статус
                setCardCheckNotice('cooldown');
            } else {
                // Вердикта нет (processing, undefined_outcome, unknown…):
                // экран и продолжение оплаты сохраняются
                setCardCheckNotice('unconfirmed');
            }
        } catch {
            if (!isCurrent()) {
                return;
            }

            // Сеть/5xx — ничего не утверждаем, экран остаётся с продолжением
            setCardCheckNotice('request_error');
        } finally {
            if (isCurrent()) {
                manualCheckingRef.current = false;
                setManualChecking(false);
            }
        }
    }

    /**
     * Карточный терминальный вердикт переиспользует существующие финальные
     * экраны ветки sbpPayment: payload null — QR и ссылок в банк нет,
     * deadline null — таймера нет, sbpStatus терминален — polling не
     * стартует. Ссылка продолжения на терминальных экранах не показывается.
     */
    function enterCardTerminalScreen(
        paymentId: string,
        status: Extract<
            SbpStatus,
            'succeeded' | 'failed' | 'refunded' | 'partially_refunded'
        >,
    ) {
        setSbpPayment({ payload: null, paymentId, deadline: null });
        setRemainingMs(null);
        setSbpStatus(status);
    }

    /**
     * Ручная проверка статуса (SBP-поток): POST /check спрашивает банк
     * (GetState) через общий lock+cooldown — в отличие от обычного polling
     * GET, который читает только локальный статус. Локальный результат
     * применяется теми же правилами, что и polling: только подтверждённый
     * отказ/успех меняют экран, всё остальное возвращает ожидание.
     *
     * Ответ без финального вердикта может нести sbp_payload/sbp_expires_at
     * ЭТОЙ же попытки (восстановленная ссылка, в том числе при
     * cooldown-skip): QR и таймер от исходного абсолютного срока
     * показываются без нового Init. Если экран возобновлённой попытки ещё
     * не перешёл в ожидание — переход происходит здесь.
     *
     * Повторный клик гасит синхронный ref-guard — manualChecking остаётся
     * только UI-флагом loading/disabled. Сброс checkout'а, смена paymentId
     * и unmount двигают поколение: поздний ответ или ошибка чужой попытки
     * не перекрашивают уже другой экран.
     */
    async function manualStatusCheck(paymentId: string | null) {
        if (paymentId === null || manualCheckingRef.current) {
            return;
        }

        const epoch = manualCheckEpochRef.current;
        const isCurrent = () => manualCheckEpochRef.current === epoch;

        manualCheckingRef.current = true;
        setManualChecking(true);

        try {
            const res = await axios.post(
                `/admin/billing/payment-status/${paymentId}/check`,
            );

            if (!isCurrent()) {
                // Поздний ответ уже чужой попытки — экран не меняем
                return;
            }

            const status = res.data?.status;
            const undefinedOutcome = res.data?.undefined_outcome === true;

            // Возобновлённая попытка ещё не в секции ожидания: все
            // вердикт-экраны живут в ней — входим в контекст той же
            // попытки (payload приложится ниже, если ответ его принёс)
            if (sbpPayment === null) {
                setSbpPayment({
                    payload: null,
                    paymentId,
                    deadline: null,
                });
            }

            if (status === 'succeeded') {
                setSbpStatus('succeeded');
            } else if (status === 'failed_terminal') {
                if (undefinedOutcome) {
                    setUnconfirmed(true);
                } else {
                    setSbpStatus('failed');
                }
            } else if (status === 'refunded') {
                setSbpStatus('refunded');
            } else if (status === 'partially_refunded') {
                setSbpStatus('partially_refunded');
            } else {
                // Вердикта нет — ждём дальше обычным polling'ом. Ответ мог
                // принести восстановленную ссылку этой же попытки: QR и
                // таймер от исходного срока, без нового Init.
                const responsePayload =
                    typeof res.data?.sbp_payload === 'string' &&
                    res.data.sbp_payload !== ''
                        ? res.data.sbp_payload
                        : null;

                if (responsePayload !== null) {
                    const deadline = parseDeadline(res.data?.sbp_expires_at);

                    setSbpPayment({
                        payload: responsePayload,
                        paymentId,
                        deadline,
                    });
                    setRemainingMs(
                        deadline === null
                            ? null
                            : Math.max(0, deadline - Date.now()),
                    );
                }

                retryRequestedRef.current = true;
                setUnconfirmed(false);
            }
        } catch {
            if (!isCurrent()) {
                return;
            }

            // Сеть/5xx — исход не подтверждён, ничего не утверждаем
            setUnconfirmed(true);
        } finally {
            // Своё поколение — снимаем guard и loading; чужой (сброс/umount)
            // уже сняты вызывающим, второй раз не трогаем.
            if (isCurrent()) {
                manualCheckingRef.current = false;
                setManualChecking(false);
            }
        }
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

                // Новая попытка = новый paymentId: проверка старой, если она
                // ещё висит, чужая и экран уже про неё не спрашивает
                invalidateManualStatusCheck();
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
    }, [sbpPayment?.deadline, setRemainingMs]);

    // ── SBP status polling: local Billing Core attempt only, never T-Bank ──
    useEffect(() => {
        if (!sbpPayment?.paymentId || sbpStatus !== 'waiting' || unconfirmed) {
            return;
        }

        const paymentId = sbpPayment.paymentId;
        let stopped = false;
        let timer: number | null = null;
        // Ожидание ограничено бюджетом, когда срок истёк ИЛИ платёжной
        // ссылки вовсе нет (payload === null: возобновлённая попытка без
        // SBP-payload — дедлайн не наступит и linkExpired не сработает).
        const budgetedWait = linkExpired || sbpPayment.payload === null;
        // Бюджет считает запросы только этого цикла; новый цикл
        // (истечение, исчерпание или «Проверить статус») — снова с нуля.
        let expiredChecks = 0;

        async function check() {
            // Тик интервала во время другого запроса не спрашивает статус
            // и не расходует бюджет цикла
            if (stopped || inFlightRef.current) {
                return;
            }

            if (budgetedWait) {
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
                } else if (budgetedWait) {
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
                // Свободное ожидание с платёжной ссылкой до её срока:
                // created / processing / failed_retryable / unknown →
                // ждём дальше, бюджет не нужен
            } catch {
                if (stopped) {
                    return;
                }

                if (budgetedWait) {
                    // Сеть недоступна в ограниченном ожидании — исход
                    // не подтверждён, статус по часам не угадываем.
                    setUnconfirmed(true);
                }
                // Свободное ожидание с ссылкой до срока: временный сбой
                // polling — оплата не «упала», ждём следующий тик
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
    }, [
        sbpPayment?.paymentId,
        sbpPayment?.payload,
        sbpStatus,
        linkExpired,
        unconfirmed,
        setSbpStatus,
        setUnconfirmed,
    ]);

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
                                                void manualStatusCheck(
                                                    sbpPayment?.paymentId ??
                                                        null,
                                                );
                                            }}
                                            disabled={manualChecking}
                                            className={`${PRIMARY_BTN_CLASS} disabled:cursor-not-allowed disabled:opacity-60`}
                                        >
                                            {manualChecking
                                                ? 'Проверка…'
                                                : 'Проверить статус'}
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
                                    pendingAttempt.method === 'card' ||
                                    pendingAttempt.checkout_url ? (
                                        /* Карточная попытка: один экран на
                                           всю жизнь возобновления —
                                           продолжение по сохранённой ссылке
                                           и ручная POST-проверка. Экран не
                                           меняется ни во время проверки, ни
                                           после ответа без вердикта; новые
                                           оплаты отсюда не стартуют. */
                                        <>
                                            {cardExpired ? (
                                                /* Срок истёк: продолжение
                                                   по ссылке закрыто, но
                                                   результат всё ещё ждём
                                                   подтверждённой проверки —
                                                   ни «не прошла», ни новой
                                                   оплаты по часам нет. */
                                                <>
                                                    <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                                        Срок ссылки на оплату
                                                        истёк
                                                    </div>
                                                    <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                        Продолжить оплату по
                                                        этой ссылке нельзя.
                                                        Проверьте результат
                                                        платежа.
                                                    </p>
                                                </>
                                            ) : (
                                                <>
                                                    <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                                        Оплата не завершена
                                                    </div>
                                                    {pendingAttempt.checkout_url ? (
                                                        <>
                                                            <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                                Продолжите
                                                                оплату на той же
                                                                защищённой
                                                                странице —
                                                                отдельный платёж
                                                                не создаётся.
                                                            </p>
                                                            <button
                                                                type="button"
                                                                onClick={
                                                                    resumeCardCheckout
                                                                }
                                                                disabled={
                                                                    manualChecking
                                                                }
                                                                className={`${PRIMARY_BTN_CLASS} disabled:cursor-not-allowed disabled:opacity-60`}
                                                            >
                                                                Продолжить
                                                                оплату
                                                            </button>
                                                            <p className="mt-3 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                                Уже оплатили?
                                                                Проверьте
                                                                статус, прежде
                                                                чем запускать
                                                                оплату заново.
                                                            </p>
                                                        </>
                                                    ) : (
                                                        <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                            Есть платёж,
                                                            статус которого ещё
                                                            не подтверждён.
                                                            Проверьте его,
                                                            прежде чем запускать
                                                            оплату заново.
                                                        </p>
                                                    )}
                                                    {/* Остаток от АБСОЛЮТНОГО
                                                        срока попытки: без
                                                        срока (старый attempt)
                                                        таймер не показывается
                                                        вовсе. */}
                                                    {cardRemainingMs !== null && (
                                                        <p
                                                            data-testid="card-countdown"
                                                            className="mt-1.5 text-[15px] leading-[21px] font-bold text-[var(--color-ink)]"
                                                        >
                                                            Осталось{' '}
                                                            {formatRemaining(
                                                                cardRemainingMs,
                                                            )}
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                            {/* Проверка — всегда POST /check
                                                той же попытки: ни во время
                                                запроса, ни после ответа без
                                                вердикта GET-цикла нет. После
                                                истечения — основное действие
                                                экрана (продолжение закрыто). */}
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    void startCardPendingStatusCheck();
                                                }}
                                                disabled={manualChecking}
                                                className={`${pendingAttempt.checkout_url && !cardExpired ? SECONDARY_BTN_CLASS : PRIMARY_BTN_CLASS} disabled:cursor-not-allowed disabled:opacity-60`}
                                            >
                                                {manualChecking
                                                    ? 'Проверяем…'
                                                    : 'Проверить оплату'}
                                            </button>
                                            {cardCheckNotice ===
                                                'unconfirmed' && (
                                                <>
                                                    <div className="mt-3 text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                                        Оплата пока не
                                                        подтверждена
                                                    </div>
                                                    <p className="mt-1.5 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                        Если вы уже оплачивали —
                                                        не запускайте оплату
                                                        повторно.
                                                    </p>
                                                </>
                                            )}
                                            {cardCheckNotice ===
                                                'request_error' && (
                                                <p className="mt-3 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                    Не удалось проверить оплату.
                                                    Попробуйте ещё раз
                                                </p>
                                            )}
                                            {cardCheckNotice === 'cooldown' && (
                                                <p className="mt-3 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                    Новая проверка пока
                                                    недоступна. Попробуйте через
                                                    минуту
                                                </p>
                                            )}
                                            {/* Текстовая ссылка, не кнопка:
                                                рамок и полноширинного вида не
                                                нужно, но фокус для клавиатуры
                                                остаётся заметным. */}
                                            <Link
                                                href="/admin/billing"
                                                className="mt-3 inline-block rounded-[4px] text-[13px] font-semibold text-[var(--color-graphite)] transition-colors hover:text-[var(--color-ink)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-orange)]"
                                            >
                                                Вернуться к тарифам
                                            </Link>
                                        </>
                                    ) : (
                                        <>
                                            <div className="text-[15px] leading-[21px] font-bold tracking-[-.015em] text-[var(--color-ink)]">
                                                Незавершённый платёж
                                            </div>
                                            {pendingAttempt.sbp_payload ? (
                                                /* Сохранённая SBP-ссылка этой же
                                                   попытки — продолжение без
                                                   нового Init и продления срока */
                                                <>
                                                    <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                        Продолжите оплату по той
                                                        же СБП-ссылке —
                                                        отдельный платёж не
                                                        создаётся.
                                                    </p>
                                                    <button
                                                        type="button"
                                                        onClick={
                                                            resumePendingAttempt
                                                        }
                                                        className={
                                                            PRIMARY_BTN_CLASS
                                                        }
                                                    >
                                                        Продолжить оплату
                                                    </button>
                                                </>
                                            ) : (
                                                <>
                                                    <p className="mt-2 text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                                        Есть платёж, статус
                                                        которого ещё не
                                                        подтверждён. Проверьте
                                                        его, прежде чем
                                                        запускать оплату заново.
                                                    </p>
                                                    {/* POST /check той же
                                                        попытки: проверка
                                                        статуса и точечное
                                                        обновление состояния —
                                                        ответ может принести
                                                        восстановленную
                                                        SBP-ссылку. Ответ без
                                                        вердикта продолжает
                                                        существующий
                                                        GET-polling этой же
                                                        попытки. */}
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setAttemptPeriodMonths(
                                                                pendingAttempt.period_months ??
                                                                    null,
                                                            );
                                                            void manualStatusCheck(
                                                                pendingAttempt.payment_id,
                                                            );
                                                        }}
                                                        disabled={
                                                            manualChecking
                                                        }
                                                        className={`${PRIMARY_BTN_CLASS} disabled:cursor-not-allowed disabled:opacity-60`}
                                                    >
                                                        {manualChecking
                                                            ? 'Проверка…'
                                                            : 'Проверить статус'}
                                                    </button>
                                                </>
                                            )}
                                            <Link
                                                href="/admin/billing"
                                                className={SECONDARY_BTN_CLASS}
                                            >
                                                Вернуться к тарифам
                                            </Link>
                                        </>
                                    )
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
