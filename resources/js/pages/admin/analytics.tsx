import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Download, Calendar,
    Wallet, Gauge, TrendingDown, CalendarDays, AlertTriangle,
} from 'lucide-react';
import AdminLayout from '@/layouts/AdminLayout';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';

/* ═══════════════ Types ═══════════════ */

interface Metrics {
    revenue: number;
    total_visits: number;
    avg_check: number;
    attendance_rate: number;
    lost_revenue: number;
    cancelled_count: number;
    no_show_count: number;
    new_clients_count: number;
    returning_clients_count: number;
    first_visit_conversion: number | null;
    top_services: Array<{ name: string; count: number; percentage: number }>;
    utilization_percentage: number;
}

interface ChartPoint {
    label: string;
    value: number;
    count: number;
    percent: number;
}

interface ServiceStat {
    name: string;
    count: number;
    revenue: number;
    percent: number;
}

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface PageProps {
    metrics: Metrics;
    trends: Record<string, number>;
    prev_metrics: Record<string, number>;
    chartData: ChartPoint[];
    serviceStats: ServiceStat[];
    activePeriod: string;
    dateFrom: string | null;
    dateTo: string | null;
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

/* ═══════════════ Constants ═══════════════ */

const PERIOD_TABS: { key: string; label: string }[] = [
    { key: 'day', label: 'День' },
    { key: 'week', label: 'Неделя' },
    { key: 'month', label: 'Месяц' },
    { key: 'year', label: 'Год' },
    { key: 'custom', label: 'Период' },
];

/* ═══════════════ Stat Card ═══════════════ */

function StatCard({ icon: Icon, label, value, badge, subtitle, color }: {
    icon: React.ElementType;
    label: string;
    value: string;
    badge?: React.ReactNode;
    subtitle?: string;
    color: string;
}) {
    const colorMap: Record<string, { bg: string; text: string }> = {
        emerald: { bg: 'bg-emerald-50 dark:bg-emerald-950/40', text: 'text-emerald-600 dark:text-emerald-400' },
        blue: { bg: 'bg-blue-50 dark:bg-blue-950/40', text: 'text-blue-600 dark:text-blue-400' },
        red: { bg: 'bg-red-50 dark:bg-red-950/40', text: 'text-red-600 dark:text-red-400' },
        rose: { bg: 'bg-rose-50 dark:bg-rose-950/40', text: 'text-rose-600 dark:text-rose-400' },
    };
    const c = colorMap[color] || colorMap.blue;

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 transition-shadow hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-3">
                <div className={`flex size-10 items-center justify-center rounded-lg ${c.bg}`}>
                    <Icon className={`size-5 ${c.text}`} />
                </div>
            </div>
            <p className="mb-1 text-xs text-slate-500 dark:text-zinc-400">{label}</p>
            <div className="flex flex-col gap-1">
                <p className="text-2xl font-bold text-slate-900 dark:text-zinc-100">{value}</p>
                <div className="flex items-center">
                    {badge}
                    {subtitle && (
                        <span className="ml-2 text-xs text-slate-400 dark:text-zinc-500">{subtitle}</span>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Trend Badge ═══════════════ */

function TrendBadge({ value, prevValue, format = 'percent' }: { value: number; prevValue?: number; format?: 'currency' | 'percent' | 'number' }) {
    const suffix = format === 'currency' ? ' ₽' : format === 'percent' ? '%' : '';
    const tooltipText = prevValue !== undefined
        ? `\u0412 \u043F\u0440\u043E\u0448\u043B\u043E\u043C \u043F\u0435\u0440\u0438\u043E\u0434\u0435: ${prevValue.toLocaleString('ru-RU')}${suffix}`
        : '';

    if (value === 0) {
        return <span title={tooltipText} className="ml-2 rounded bg-slate-500/10 px-1.5 py-0.5 text-xs font-medium text-slate-500 dark:bg-slate-500/20 dark:text-slate-400">0{suffix}</span>;
    }
    const isPositive = value > 0;
    const colorClass = isPositive
        ? 'bg-emerald-500/15 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
        : 'bg-rose-500/15 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400';
    const arrow = isPositive ? '\u2191' : '\u2193';
    return (
        <span title={tooltipText} className={`ml-2 rounded px-1.5 py-0.5 text-xs font-medium ${colorClass}`}>
            {arrow} {Math.abs(value).toLocaleString('ru-RU')}{suffix}
        </span>
    );
}

/* ═══════════════ Main Analytics Page ═══════════════ */

export default function AnalyticsPage() {
    const props = usePage<PageProps>().props;
    const metrics = props.metrics || { revenue: 0, total_visits: 0, avg_check: 0, attendance_rate: 0, lost_revenue: 0, cancelled_count: 0, no_show_count: 0, new_clients_count: 0, returning_clients_count: 0, first_visit_conversion: null, top_services: [], utilization_percentage: 0 };
    const trends = props.trends || { revenue: 0, avg_check: 0, utilization: 0 };
    const prev_metrics = props.prev_metrics || { revenue: 0, avg_check: 0, utilization: 0 };
    const chartData = props.chartData || [];
    const serviceStats = props.serviceStats || [];
    const activePeriod = props.activePeriod || 'week';
    const auth = props.auth;

    const [activePoint, setActivePoint] = useState<ChartPoint | null>(null);
    const [dates, setDates] = useState({
        from: props.dateFrom || '',
        to: props.dateTo || '',
    });

    const totalValue = chartData.reduce((sum, point) => sum + point.value, 0);
    const totalCount = chartData.reduce((sum, point) => sum + point.count, 0);

    // Вычисления для карточки «Клиентская база»
    const totalClients = metrics.new_clients_count + metrics.returning_clients_count;
    const returningPct = totalClients > 0 ? Math.round((metrics.returning_clients_count / totalClients) * 100) : 0;
    const newPct = totalClients > 0 ? 100 - returningPct : 0;

    // Вычисления для карточки «Воронка визитов»
    const funnelTotal = metrics.total_visits + metrics.cancelled_count + metrics.no_show_count;
    const paidPct = funnelTotal > 0 ? Math.round((metrics.total_visits / funnelTotal) * 100) : 0;
    const cancelPct = funnelTotal > 0 ? Math.round((metrics.cancelled_count / funnelTotal) * 100) : 0;
    const noShowPct = funnelTotal > 0 ? Math.round((metrics.no_show_count / funnelTotal) * 100) : 0;

    function handlePeriodChange(period: string) {
        router.get('/admin/analytics', { period }, {
            preserveState: true,
            preserveScroll: true,
            only: ['metrics', 'trends', 'prev_metrics', 'chartData', 'serviceStats', 'activePeriod', 'dateFrom', 'dateTo'],
        });
    }

    function handleCustomApply() {
        if (! dates.from || ! dates.to) return;

        router.get('/admin/analytics', {
            period: 'custom',
            date_from: dates.from,
            date_to: dates.to,
        }, {
            preserveState: true,
            preserveScroll: true,
            only: ['metrics', 'trends', 'prev_metrics', 'chartData', 'serviceStats', 'activePeriod', 'dateFrom', 'dateTo'],
        });
    }

    const stats = [
        {
            icon: TrendingDown,
            label: 'Средний чек',
            value: Math.round(metrics.avg_check).toLocaleString('ru-RU') + ' ₽',
            badge: <TrendBadge value={trends.avg_check} prevValue={prev_metrics.avg_check} format="currency" />,
            color: 'red',
        },
        {
            icon: Gauge,
            label: 'Посещаемость',
            value: metrics.attendance_rate + '%',
            badge: null,
            color: 'blue',
        },
        {
            icon: CalendarDays,
            label: 'Заполняемость графика',
            value: `${metrics.utilization_percentage}%`,
            badge: <TrendBadge value={trends.utilization} prevValue={prev_metrics.utilization} format="percent" />,
            color: 'emerald',
        },
        {
            icon: AlertTriangle,
            label: 'Упущенная выгода',
            value: Math.round(metrics.lost_revenue).toLocaleString('ru-RU') + ' ₽',
            badge: null,
            subtitle: `${metrics.cancelled_count} отмен / ${metrics.no_show_count} неявок`,
            color: 'rose',
        },
    ];

    return (
        <>
            <Head title="Аналитика — Вовремя" />

            <AdminLayout title="Базовая аналитика" auth={auth}>
                <div className="space-y-6">
                            {/* ─── Period Selector + Export ─── */}
                            <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-zinc-800">
                                        {PERIOD_TABS.map(({ key, label }) => (
                                            <button
                                                key={key}
                                                onClick={() => handlePeriodChange(key)}
                                                className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                                    activePeriod === key
                                                        ? 'bg-white text-slate-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100'
                                                        : 'text-slate-600 hover:text-slate-900 dark:text-zinc-400 dark:hover:text-zinc-200'
                                                }`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                    <button className="flex items-center gap-1.5 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                        <Download className="size-3.5" />
                                        Экспорт
                                    </button>
                                </div>

                                {/* ─── Custom Date Range ─── */}
                                {activePeriod === 'custom' && (
                                    <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3 dark:border-zinc-800">
                                        <div className="flex items-center gap-2">
                                            <Calendar className="size-4 text-slate-400 dark:text-zinc-500" />
                                            <span className="text-xs font-medium text-slate-500 dark:text-zinc-400">С</span>
                                            <input
                                                type="date"
                                                value={dates.from}
                                                onChange={(e) => setDates((d) => ({ ...d, from: e.target.value }))}
                                                className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:focus:border-blue-400"
                                            />
                                        </div>
                                        <span className="text-xs text-slate-400 dark:text-zinc-500">—</span>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-medium text-slate-500 dark:text-zinc-400">По</span>
                                            <input
                                                type="date"
                                                value={dates.to}
                                                onChange={(e) => setDates((d) => ({ ...d, to: e.target.value }))}
                                                className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:focus:border-blue-400"
                                            />
                                        </div>
                                        <button
                                            onClick={handleCustomApply}
                                            disabled={!dates.from || !dates.to}
                                            className="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-blue-500 dark:hover:bg-blue-600"
                                        >
                                            Применить
                                        </button>
                                    </div>
                                )}
                            </div>

                            {/* ─── Stat Cards ─── */}
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                                {stats.map((stat) => (
                                    <StatCard key={stat.label} {...stat} />
                                ))}
                            </div>

                            {/* ─── Charts Row ─── */}
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {/* График загрузки */}
                                <div className="rounded-xl border border-slate-100 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="mb-5">
                                        <h3 className="font-semibold text-slate-900 dark:text-zinc-100">Выручка по периодам</h3>
                                        <p className="text-xs text-slate-500 dark:text-zinc-400">
                                            {activePeriod === 'day' && 'Почасовая выручка за сегодня'}
                                            {activePeriod === 'week' && 'Выручка по дням недели'}
                                            {activePeriod === 'month' && 'Ежедневная выручка за месяц'}
                                            {activePeriod === 'year' && 'Ежемесячная выручка за год'}
                                            {activePeriod === 'custom' && 'Выручка за выбранный период'}
                                        </p>
                                    </div>
                                    {/* Dynamic Aggregate Header */}
                                    <div className="mb-6 flex min-h-[4rem] flex-col items-start justify-end">
                                        <div className="flex items-center gap-3 transition-all duration-300">
                                            <div className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                                                {activePoint ? `${Math.round(activePoint.value).toLocaleString('ru-RU')} ₽` : `${Math.round(totalValue).toLocaleString('ru-RU')} ₽`}
                                            </div>
                                            {!activePoint && (
                                                <div className="flex items-center">
                                                    <TrendBadge value={trends.revenue} prevValue={prev_metrics.revenue} format="currency" />
                                                    <span className="ml-2 text-xs text-slate-500">к прошлому периоду</span>
                                                </div>
                                            )}
                                        </div>
                                        <div className="mt-1 text-sm font-medium text-slate-500 transition-all duration-300 dark:text-slate-400">
                                            {activePoint ? (
                                                <>{activePoint.label} <span className="mx-1.5 text-slate-300 dark:text-slate-600">&middot;</span> Записей: {activePoint.count}</>
                                            ) : (
                                                <>Итого за период <span className="mx-1.5 text-slate-300 dark:text-slate-600">&middot;</span> Записей: {totalCount}</>
                                            )}
                                        </div>
                                    </div>

                                    {Array.isArray(chartData) && chartData.length > 0 ? (
                                        <div className="relative w-full overflow-x-auto scrollbar-none">
                                            {/* Background grid */}
                                            <div className="pointer-events-none absolute inset-0 flex flex-col justify-between">
                                                {[...Array(4)].map((_, i) => (
                                                    <div key={i} className="w-full border-t border-slate-200 dark:border-slate-700/50" />
                                                ))}
                                            </div>
                                            <div
                                                className={`relative z-10 flex items-end gap-2 px-2 pb-8 pt-2 ${chartData.length > 15 ? 'min-w-[700px]' : ''}`}
                                                style={{ height: '220px' }}
                                                onMouseLeave={() => setActivePoint(null)}
                                            >
                                                {chartData.map((point, i) => (
                                                    <div key={i} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2">
                                                        <div
                                                            className={`w-full max-w-10 cursor-default rounded-t-md bg-gradient-to-t from-blue-600 to-blue-400 transition-opacity duration-300 dark:from-blue-500 dark:to-blue-400 ${
                                                                activePoint && activePoint.label !== point.label ? 'opacity-30' : 'opacity-100'
                                                            }`}
                                                            style={{ height: `${point.percent}%`, minHeight: point.percent > 0 ? '4px' : '0' }}
                                                            onMouseEnter={() => setActivePoint(point)}
                                                            onClick={() => setActivePoint(point)}
                                                        />
                                                        <span className="w-full truncate text-center text-[10px] font-medium text-slate-500 dark:text-zinc-400">
                                                            {point.label}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="flex h-48 items-center justify-center">
                                            <p className="text-sm text-slate-400 dark:text-zinc-500">Нет данных за период</p>
                                        </div>
                                    )}
                                </div>

                                {/* Рейтинг услуг */}
                                <div className="rounded-xl border border-slate-100 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="mb-5">
                                        <h3 className="font-semibold text-slate-900 dark:text-zinc-100">Рейтинг услуг</h3>
                                        <p className="text-xs text-slate-500 dark:text-zinc-400">Популярность процедур</p>
                                    </div>
                                    <div className="space-y-3">
                                        {metrics.top_services.length > 0 ? metrics.top_services.map((service, index) => (
                                            <div key={index} className="space-y-2">
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="font-medium text-slate-700 dark:text-zinc-300">{service.name}</span>
                                                    <span className="font-bold text-slate-900 dark:text-zinc-100">{service.percentage}% <span className="ml-1 font-normal text-slate-400 dark:text-zinc-500">({service.count})</span></span>
                                                </div>
                                                <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                                                    <div
                                                        className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 transition-all duration-500"
                                                        style={{ width: `${service.percentage}%` }}
                                                    />
                                                </div>
                                            </div>
                                        )) : (
                                            <p className="py-4 text-center text-sm text-slate-400 dark:text-zinc-500">
                                                Нет данных за выбранный период
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* ─── Client Analytics Row ─── */}
                            <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Клиентская база</CardTitle>
                                        <CardDescription>Новые и постоянные клиенты за период</CardDescription>
                                    </CardHeader>
                                    <CardContent className="flex flex-col items-center gap-8 sm:flex-row">
                                        {/* Donut Chart */}
                                        <div className="relative flex h-48 w-48 shrink-0 items-center justify-center rounded-full"
                                            style={{ background: `conic-gradient(#6366f1 ${returningPct}%, #60a5fa ${returningPct}% 100%)` }}>
                                            <div className="flex h-32 w-32 items-center justify-center rounded-full bg-white dark:bg-zinc-900">
                                                <div className="text-center">
                                                    <div className="text-2xl font-bold">{totalClients}</div>
                                                    <div className="text-xs text-slate-500">Всего</div>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Легенда */}
                                        <div className="w-full flex-1 space-y-4">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <div className="h-3 w-3 rounded-full bg-indigo-500"></div>
                                                    <span className="text-sm font-medium text-slate-700 dark:text-slate-300">Постоянные</span>
                                                </div>
                                                <div className="text-sm font-bold">
                                                    {returningPct}% <span className="ml-1 font-normal text-slate-400">({metrics.returning_clients_count})</span>
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <div className="h-3 w-3 rounded-full bg-blue-400"></div>
                                                    <span className="text-sm font-medium text-slate-700 dark:text-slate-300">Новые</span>
                                                </div>
                                                <div className="text-sm font-bold">
                                                    {newPct}% <span className="ml-1 font-normal text-slate-400">({metrics.new_clients_count})</span>
                                                </div>
                                            </div>

                                            {metrics.first_visit_conversion !== null && metrics.first_visit_conversion > 0 && (
                                                <div className="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/50">
                                                    <div className="text-xs text-slate-500">Конверсия первого визита</div>
                                                    <div className="mt-0.5 text-sm font-medium">{metrics.first_visit_conversion}% новых клиентов записываются повторно</div>
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Воронка визитов</CardTitle>
                                        <CardDescription>Статусы записей и упущенная выгода</CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Успешные визиты */}
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between text-sm">
                                                <div className="flex items-center gap-2 font-medium text-slate-700 dark:text-slate-300">
                                                    <div className="h-2 w-2 rounded-full bg-emerald-500"></div>
                                                    Успешно завершены
                                                </div>
                                                <span className="font-bold">{paidPct}% <span className="ml-1 font-normal text-slate-400">({metrics.total_visits})</span></span>
                                            </div>
                                            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                                <div className="h-full rounded-full bg-emerald-500" style={{ width: `${paidPct}%` }}></div>
                                            </div>
                                        </div>

                                        {/* Отмены */}
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between text-sm">
                                                <div className="flex items-center gap-2 font-medium text-slate-700 dark:text-slate-300">
                                                    <div className="h-2 w-2 rounded-full bg-amber-400"></div>
                                                    Отменены клиентом
                                                </div>
                                                <span className="font-bold">{cancelPct}% <span className="ml-1 font-normal text-slate-400">({metrics.cancelled_count})</span></span>
                                            </div>
                                            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                                <div className="h-full rounded-full bg-amber-400" style={{ width: `${cancelPct}%` }}></div>
                                            </div>
                                        </div>

                                        {/* Неявки */}
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between text-sm">
                                                <div className="flex items-center gap-2 font-medium text-slate-700 dark:text-slate-300">
                                                    <div className="h-2 w-2 rounded-full bg-rose-500"></div>
                                                    Неявки (No-show)
                                                </div>
                                                <span className="font-bold">{noShowPct}% <span className="ml-1 font-normal text-slate-400">({metrics.no_show_count})</span></span>
                                            </div>
                                            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                                <div className="h-full rounded-full bg-rose-500" style={{ width: `${noShowPct}%` }}></div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
            </AdminLayout>
        </>
    );
}
