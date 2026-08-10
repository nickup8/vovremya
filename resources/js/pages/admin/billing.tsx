import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Check } from 'lucide-react';
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

/* ═══════════════ Constants ═══════════════ */

const FEATURE_LABELS: Record<string, string> = {
    calendar: 'Календарь записей',
    basic_client_management: 'Базовая база клиентов',
    unlimited_appointments: 'Безлимит записей',
    client_management: 'Полная база клиентов',
};

const PERIOD_LABELS: Record<number, string> = {
    1: '1 мес',
    3: '3 мес',
    6: '6 мес',
    12: '12 мес',
};

const fmt = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

/* ═══════════════ Main Page ═══════════════ */

export default function BillingPage() {
    const props = usePage<PageProps>().props;
    const auth = props.auth;
    const { plans, current } = props;
    const tariffLimits = props.tariff_limits;

    return (
        <>
            <Head title="Тарифы и оплата" />

            <AdminLayout title="Тарифы и оплата" auth={auth}>
                <div className="space-y-6">
                    {/* ─── Current State ─── */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-zinc-100">
                            Текущий тариф: {current.tariff_name ?? '—'}
                        </h2>
                        {current.is_paid && current.expires_at && (
                            <p className="text-sm text-slate-500 dark:text-zinc-400">
                                Оплачено до {new Date(current.expires_at).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })}
                                {typeof current.days_left === 'number' && (
                                    current.days_left > 0
                                        ? ` · осталось ${current.days_left} дн.`
                                        : ' · истекает сегодня'
                                )}
                            </p>
                        )}
                        {tariffLimits?.total !== null && tariffLimits?.total !== undefined ? (
                            <div className="mt-3 space-y-2">
                                <p className="text-sm text-slate-600 dark:text-zinc-400">
                                    Использовано записей: {tariffLimits.used} из {tariffLimits.total}
                                </p>
                                <div className="h-2 w-full max-w-xs overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                                    <div
                                        className={`h-full rounded-full transition-all ${
                                            tariffLimits.used >= tariffLimits.total
                                                ? 'bg-red-500'
                                                : 'bg-blue-500'
                                        }`}
                                        style={{
                                            width: `${Math.min(100, (tariffLimits.used / tariffLimits.total) * 100)}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        ) : (
                            <p className="mt-1 text-sm text-slate-600 dark:text-zinc-400">
                                Записей: без ограничений
                            </p>
                        )}
                    </div>

                    {/* ─── Tariff Cards ─── */}
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        {plans.map((plan) => (
                            <TariffCard
                                key={plan.id}
                                plan={plan}
                                currentCode={current.tariff}
                            />
                        ))}
                    </div>
                </div>
            </AdminLayout>
        </>
    );
}

/* ═══════════════ Tariff Card ═══════════════ */

function TariffCard({ plan, currentCode }: { plan: Plan; currentCode: string | null }) {
    const isCurrent = plan.code === currentCode;
    const isPaid = plan.price_monthly > 0;
    const [periodMonths, setPeriodMonths] = useState(1);
    const [loading, setLoading] = useState(false);

    const sel = isPaid
        ? plan.prices.find((p) => p.period_months === periodMonths)
        : undefined;

    async function handleCheckout() {
        if (!sel || loading) return;

        setLoading(true);
        try {
            const res = await axios.post('/admin/checkout', {
                tariff_plan_id: plan.id,
                period_months: periodMonths,
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

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-xl font-bold text-slate-900 dark:text-zinc-100">
                    {plan.name}
                </h3>
                {isCurrent && (
                    <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                        Текущий
                    </span>
                )}
            </div>

            {/* Price */}
            {isPaid && sel ? (
                <div className="mb-4">
                    <div className="flex items-baseline gap-2">
                        <span className="text-3xl font-bold text-slate-900 dark:text-zinc-100">
                            {fmt(sel.final)}
                        </span>
                        {sel.discount_percent > 0 && (
                            <>
                                <span className="text-lg text-slate-400 line-through dark:text-zinc-500">
                                    {fmt(sel.base)}
                                </span>
                                <span className="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-900/30 dark:text-rose-400">
                                    −{sel.discount_percent}%
                                </span>
                            </>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                        за {periodMonths} {periodMonths === 1 ? 'месяц' : periodMonths < 5 ? 'месяца' : 'месяцев'}
                    </p>
                </div>
            ) : (
                <div className="mb-4">
                    <span className="text-3xl font-bold text-slate-900 dark:text-zinc-100">
                        0 ₽ / мес
                    </span>
                    <p className="mt-1 text-sm text-emerald-600 dark:text-emerald-400">Бесплатно</p>
                </div>
            )}

            {/* Period selector (paid only) */}
            {isPaid && plan.prices.length > 0 && (
                <div className="mb-5 flex gap-2">
                    {[1, 3, 6, 12].map((m) => (
                        <Button
                            key={m}
                            variant={periodMonths === m ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setPeriodMonths(m)}
                        >
                            {PERIOD_LABELS[m]}
                        </Button>
                    ))}
                </div>
            )}

            {/* Features */}
            <ul className="mb-5 space-y-2">
                {plan.features.map((f) => (
                    <li key={f} className="flex items-start gap-2 text-sm text-slate-700 dark:text-zinc-300">
                        <Check className="mt-0.5 size-4 shrink-0 text-emerald-500" />
                        {FEATURE_LABELS[f] ?? f}
                    </li>
                ))}
            </ul>

            {/* Limit */}
            <p className="mb-5 text-sm text-slate-500 dark:text-zinc-400">
                {plan.max_appointments_per_month === null
                    ? 'Записи: безлимит'
                    : `Записи: ${plan.max_appointments_per_month} / мес`}
            </p>

            {/* CTA */}
            {isPaid && !isCurrent && (
                <Button
                    size="lg"
                    className="w-full"
                    disabled={loading}
                    onClick={handleCheckout}
                >
                    {loading ? 'Перенаправление…' : 'Оплатить'}
                </Button>
            )}
            {isCurrent && isPaid && (
                <Button
                    size="lg"
                    variant="outline"
                    className="w-full"
                    disabled={loading}
                    onClick={handleCheckout}
                >
                    {loading ? 'Перенаправление…' : 'Продлить'}
                </Button>
            )}
            {isCurrent && !isPaid && (
                <p className="text-center text-sm font-medium text-slate-500 dark:text-zinc-400">
                    Ваш текущий тариф
                </p>
            )}
        </div>
    );
}
