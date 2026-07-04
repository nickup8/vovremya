import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Menu, Download, Calendar,
    Wallet, Gauge, TrendingDown, CalendarDays, AlertTriangle,
} from 'lucide-react';
import Sidebar from '@/components/admin/Sidebar';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';

/* ═══════════════ Types ═══════════════ */

interface Metrics {
    revenue: number;
    total_visits: number;
    avg_check: number;
    attendance_rate: number;
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

/* ═══════════════ Main Analytics Page ═══════════════ */

export default function AnalyticsPage() {
    const props = usePage<PageProps>().props;
    const metrics = props.metrics || { revenue: 0, total_visits: 0, avg_check: 0, attendance_rate: 0 };
    const chartData = props.chartData || [];
    const serviceStats = props.serviceStats || [];
    const activePeriod = props.activePeriod || 'week';
    const auth = props.auth;

    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [activePoint, setActivePoint] = useState<ChartPoint | null>(null);
    const [dates, setDates] = useState({
        from: props.dateFrom || '',
        to: props.dateTo || '',
    });

    const userName = auth?.user?.name || 'Мастер';
    const totalValue = chartData.reduce((sum, point) => sum + point.value, 0);
    const totalCount = chartData.reduce((sum, point) => sum + point.count, 0);
    const initials = userName
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    function handlePeriodChange(period: string) {
        router.get('/admin/analytics', { period }, {
            preserveState: true,
            preserveScroll: true,
            only: ['metrics', 'chartData', 'serviceStats', 'activePeriod', 'dateFrom', 'dateTo'],
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
            only: ['metrics', 'chartData', 'serviceStats', 'activePeriod', 'dateFrom', 'dateTo'],
        });
    }

    const stats = [
        {
            icon: TrendingDown,
            label: 'Средний чек',
            value: Math.round(metrics.avg_check).toLocaleString('ru-RU') + ' ₽',
            badge: <span className="ml-2 rounded bg-slate-500/10 px-1.5 py-0.5 text-xs font-medium text-slate-500">0%</span>,
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
            value: '68%',
            badge: <span className="ml-2 rounded bg-emerald-500/10 px-1.5 py-0.5 text-xs font-medium text-emerald-600">↑ 5%</span>,
            color: 'emerald',
        },
        {
            icon: AlertTriangle,
            label: 'Упущенная выгода',
            value: '3 600 ₽',
            badge: <span className="ml-2 rounded bg-rose-500/10 px-1.5 py-0.5 text-xs font-medium text-rose-600">↓ Выше нормы</span>,
            subtitle: '3 отмены / неявки',
            color: 'rose',
        },
    ];

    return (
        <>
            <Head title="Аналитика — Вовремя" />

            <div className="flex min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-zinc-900 dark:text-zinc-50">
                <Sidebar mobileOpen={mobileMenuOpen} onMobileClose={() => setMobileMenuOpen(false)} />

                <div className="flex min-w-0 flex-1 flex-col">
                    {/* Header */}
                    <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/80">
                        <div className="flex items-center gap-4">
                            <button
                                onClick={() => setMobileMenuOpen(true)}
                                className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800 lg:hidden"
                            >
                                <Menu className="size-5 text-slate-700 dark:text-zinc-300" />
                            </button>
                            <h1 className="text-lg font-semibold text-slate-900 dark:text-zinc-100 md:text-xl">
                                Базовая аналитика
                            </h1>
                        </div>
                        <div className="flex items-center gap-4">
                            <div className="text-right hidden sm:block">
                                <p className="text-sm font-medium text-slate-700 dark:text-zinc-300">{userName}</p>
                                <p className="text-xs text-slate-400 dark:text-zinc-500">Тариф: {auth?.user?.tariff_name || 'Free'}</p>
                            </div>
                            <div className="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-bold text-white">
                                {initials}
                            </div>
                        </div>
                    </header>

                    {/* Content Area */}
                    <main className="flex-1 overflow-y-auto p-4 md:p-6">
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
                                                <span className="rounded-md bg-emerald-500/15 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                                    ↑ +12% к прошлой неделе
                                                </span>
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
                                        {Array.isArray(serviceStats) && serviceStats.length > 0 ? serviceStats.map((s) => (
                                            <div key={s.name}>
                                                <div className="mb-1 flex items-center justify-between">
                                                    <span className="text-sm font-medium text-slate-700 dark:text-zinc-300">{s.name}</span>
                                                    <span className="text-sm font-bold text-slate-900 dark:text-zinc-100">{s.percent}% <span className="text-xs font-normal text-slate-400 dark:text-zinc-500">({s.count})</span></span>
                                                </div>
                                                <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                                                    <div
                                                        className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 transition-all"
                                                        style={{ width: `${Math.min(s.percent * 2.5, 100)}%` }}
                                                    />
                                                </div>
                                            </div>
                                        )) : (
                                            <p className="py-4 text-center text-sm text-slate-400 dark:text-zinc-500">
                                                Нет данных за период
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
                                        {/* Плейсхолдер графика */}
                                        <div className="relative flex h-48 w-48 items-center justify-center rounded-full border-[16px] border-slate-50 dark:border-slate-800">
                                            <div className="absolute inset-0 rotate-45 rounded-full border-[16px] border-indigo-500 border-b-transparent border-r-transparent"></div>
                                            <div className="absolute inset-0 rotate-45 rounded-full border-[16px] border-blue-400 border-l-transparent border-t-transparent opacity-80"></div>
                                            <div className="text-center">
                                                <div className="text-2xl font-bold">32</div>
                                                <div className="text-xs text-slate-500">Всего</div>
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
                                                    75% <span className="ml-1 font-normal text-slate-400">(24)</span>
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <div className="h-3 w-3 rounded-full bg-blue-400"></div>
                                                    <span className="text-sm font-medium text-slate-700 dark:text-slate-300">Новые</span>
                                                </div>
                                                <div className="text-sm font-bold">
                                                    25% <span className="ml-1 font-normal text-slate-400">(8)</span>
                                                </div>
                                            </div>

                                            <div className="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/50">
                                                <div className="text-xs text-slate-500">Конверсия первого визита</div>
                                                <div className="mt-0.5 text-sm font-medium">33% новых клиентов записываются повторно</div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        </>
    );
}
