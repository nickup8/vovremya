import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
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
    };
    auth?: { user?: AuthUser };
    tariff_limits?: { total: number | null; used: number } | null;
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
};

const BENEFIT_FEATURES = ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'];

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

/* ═══════════════ Main Page ═══════════════ */

export default function BillingPage() {
    const props = usePage<PageProps>().props;
    const auth = props.auth;
    const { plans, current } = props;
    const tariffLimits = props.tariff_limits;

    const proPlan = plans.find((p) => p.code === 'pro');
    const startPlan = plans.find((p) => p.code === 'start');

    const [selectedPeriod, setSelectedPeriod] = useState(3);
    const [loading, setLoading] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [compareOpen, setCompareOpen] = useState(false);

    const selectedPrice = proPlan?.prices.find((p) => p.period_months === selectedPeriod);
    const monthlyEquiv = selectedPrice ? Math.round(selectedPrice.final / selectedPeriod) : 0;
    const saving = selectedPrice ? selectedPrice.base - selectedPrice.final : 0;

    const isPaid = current.is_paid && current.tariff === 'pro';

    async function handleCheckout() {
        if (!proPlan || !selectedPrice || loading) return;

        setLoading(true);
        setModalOpen(false);
        try {
            const res = await axios.post('/admin/checkout', {
                tariff_plan_id: proPlan.id,
                period_months: selectedPeriod,
            });
            const url = res.data?.checkout_url;
            if (url) {
                window.location.href = url;
            } else {
                toast.error('Не удалось получить ссылку на оплату');
            }
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                const errors = err.response.data?.errors ?? {};
                const first = Object.values(errors)[0];
                toast.error(Array.isArray(first) ? first[0] : 'Ошибка валидации');
            } else if (axios.isAxiosError(err) && err.response?.status === 403) {
                toast.error('Недостаточно прав');
            } else {
                toast.error('Ошибка при создании платежа');
            }
        } finally {
            setLoading(false);
        }
    }

    if (!proPlan) return null;

    return (
        <>
            <Head title="Тарифы и оплата" />

            <AdminLayout title="Тарифы и оплата" auth={auth} fullBleed>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="w-full space-y-4 pb-10">

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
                                            ? `До ${tariffLimits?.total ?? '—'} записей в месяц`
                                            : ''}
                                </div>
                            </div>
                            <span className="shrink-0 rounded-[7px] border border-[var(--color-line)] bg-[var(--color-surface-hover)] px-2.5 py-0.5 text-[11px] font-semibold text-[var(--color-graphite)] max-md:justify-self-start">
                                Текущий тариф
                            </span>
                        </div>
                    </section>

                    {/* ─── 2. Renewal Section ─── */}
                    <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
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
                        <div className="grid grid-cols-4 gap-2.5 max-[1100px]:grid-cols-2 max-[600px]:grid-cols-1">
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

                        {/* Divider */}
                        <div className="my-5 border-t border-[var(--color-line-soft)]" />

                        {/* Summary + CTA */}
                        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-6 max-md:grid-cols-1 max-md:gap-4">
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
                            <div className="text-right max-md:text-left">
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
                                disabled={loading}
                                onClick={() => setModalOpen(true)}
                                className="h-10 cursor-pointer rounded-[10px] border-0 bg-[var(--color-orange)] px-5 text-[14px] font-semibold text-white transition-colors hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {loading ? 'Перенаправление…' : `Продлить на ${pluralizePeriod(selectedPeriod)} — ${selectedPrice ? fmt(selectedPrice.final) : ''}`}
                            </button>
                        </div>
                    </section>

                    {/* ─── 3. Benefits + Comparison ─── */}
                    <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-5 py-5">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
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
                            </div>
                            {startPlan && (
                                <button
                                    type="button"
                                    onClick={() => setCompareOpen((v) => !v)}
                                    className="cursor-pointer border-0 bg-transparent p-0 text-[12px] font-semibold text-[var(--color-orange)] underline decoration-[var(--color-orange)]/30 underline-offset-[3px] transition-colors hover:text-[var(--color-orange-hover)]"
                                >
                                    Сравнить со Старт
                                </button>
                            )}
                        </div>

                        {startPlan && compareOpen && (
                            <div className="mt-4">
                                <table className="w-full border-collapse text-[12px]">
                                    <tbody>
                                        <tr className="border-t border-[var(--color-line-soft)]">
                                            <td className="py-2.5 text-[var(--color-graphite)]">Записи в месяц</td>
                                            <td className="py-2.5 text-right font-semibold text-[var(--color-ink)]">
                                                {startPlan.max_appointments_per_month ?? 'Безлимит'} → Профи: безлимит
                                            </td>
                                        </tr>
                                        <tr className="border-t border-[var(--color-line-soft)]">
                                            <td className="py-2.5 text-[var(--color-graphite)]">База клиентов</td>
                                            <td className="py-2.5 text-right font-semibold text-[var(--color-ink)]">Полная</td>
                                        </tr>
                                        <tr className="border-t border-[var(--color-line-soft)]">
                                            <td className="py-2.5 text-[var(--color-graphite)]">Стоимость Старт</td>
                                            <td className="py-2.5 text-right font-semibold text-[var(--color-ink)]">
                                                {fmt(startPlan.price_monthly)} / мес
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                    </div>
                </div>

                {/* ─── 4. Payment Confirmation Modal ─── */}
                {modalOpen && (
                    <>
                        <div
                            className="fixed inset-0 z-[110] bg-[var(--color-ink)]/25"
                            onClick={() => setModalOpen(false)}
                        />
                        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4">
                            <div className="w-full max-w-[460px] rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface)] p-6 shadow-[0_12px_32px_rgba(24,24,24,0.1)]">
                                <div className="text-[18px] font-bold leading-[23px] text-[var(--color-ink)]">
                                    Переход к оплате
                                </div>
                                <div className="mt-[14px] text-[13px] leading-[18px] text-[var(--color-graphite)]">
                                    После продолжения вы перейдёте на страницу платёжного шлюза. Текущий оплаченный срок сохранится, новый период добавится после него.
                                </div>
                                <div className="mt-5 flex justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setModalOpen(false)}
                                        className="h-10 cursor-pointer rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-4 text-[13px] font-semibold text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)]"
                                    >
                                        Отмена
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleCheckout}
                                        disabled={loading}
                                        className="h-10 cursor-pointer rounded-[10px] border-0 bg-[var(--color-orange)] px-4 text-[13px] font-semibold text-white transition-colors hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Продолжить
                                    </button>
                                </div>
                            </div>
                        </div>
                    </>
                )}
            </AdminLayout>
        </>
    );
}
