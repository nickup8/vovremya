import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Menu, Download, Calendar,
    Wallet, Gauge, TrendingDown, CalendarCheck,
} from 'lucide-react';
import Sidebar from '@/components/admin/Sidebar';

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

function StatCard({ icon: Icon, label, value, change, positive, color }: {
    icon: React.ElementType;
    label: string;
    value: string;
    change: string;
    positive: boolean;
    color: string;
}) {
    const colorMap: Record<string, { bg: string; text: string }> = {
        emerald: { bg: 'bg-emerald-50 dark:bg-emerald-950/40', text: 'text-emerald-600 dark:text-emerald-400' },
        blue: { bg: 'bg-blue-50 dark:bg-blue-950/40', text: 'text-blue-600 dark:text-blue-400' },
        red: { bg: 'bg-red-50 dark:bg-red-950/40', text: 'text-red-600 dark:text-red-400' },
        purple: { bg: 'bg-purple-50 dark:bg-purple-950/40', text: 'text-purple-600 dark:text-purple-400' },
    };
    const c = colorMap[color] || colorMap.blue;

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 transition-shadow hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-3 flex items-start justify-between">
                <div className={`flex size-10 items-center justify-center rounded-lg ${c.bg}`}>
                    <Icon className={`size-5 ${c.text}`} />
                </div>
                <span className={`rounded px-2 py-0.5 text-xs font-semibold ${positive ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400' : 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-400'}`}>
                    {change}
                </span>
            </div>
            <p className="mb-1 text-xs text-slate-500 dark:text-zinc-400">{label}</p>
            <p className="text-2xl font-bold text-slate-900 dark:text-zinc-100">{value}</p>
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
    const [dates, setDates] = useState({
        from: props.dateFrom || '',
        to: props.dateTo || '',
    });

    const userName = auth?.user?.name || 'Мастер';
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
            icon: Wallet,
            label: 'Заработано за период',
            value: metrics.revenue.toLocaleString('ru-RU') + ' ₽',
            change: metrics.total_visits > 0 ? `${metrics.total_visits} визитов` : 'Нет данных',
            positive: metrics.revenue > 0,
            color: 'emerald',
        },
        {
            icon: Gauge,
            label: 'Процент посещаемости',
            value: metrics.attendance_rate + '%',
            change: metrics.attendance_rate >= 80 ? 'Хорошо' : 'Ниже нормы',
            positive: metrics.attendance_rate >= 80,
            color: 'blue',
        },
        {
            icon: TrendingDown,
            label: 'Средний чек',
            value: metrics.avg_check.toLocaleString('ru-RU') + ' ₽',
            change: metrics.avg_check > 0 ? 'Стабильно' : 'Нет данных',
            positive: true,
            color: 'red',
        },
        {
            icon: CalendarCheck,
            label: 'Всего визитов',
            value: String(metrics.total_visits),
            change: `${metrics.total_visits} за период`,
            positive: true,
            color: 'purple',
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
                                    {Array.isArray(chartData) && chartData.length > 0 ? (
                                        <div className="w-full overflow-x-auto scrollbar-none">
                                            <div
                                                className={`flex items-end gap-2 px-2 pb-8 pt-2 ${chartData.length > 15 ? 'min-w-[700px]' : ''}`}
                                                style={{ height: '220px' }}
                                            >
                                                {chartData.map((point, i) => (
                                                    <div key={i} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2">
                                                        <div className="w-full max-w-10 rounded-t-md bg-gradient-to-t from-blue-600 to-blue-400 transition-all dark:from-blue-500 dark:to-blue-400" style={{ height: `${point.percent}%`, minHeight: point.percent > 0 ? '4px' : '0' }} />
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
                                                    <span className="text-sm font-bold text-slate-900 dark:text-zinc-100">{s.percent}%</span>
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
                        </div>
                    </main>
                </div>
            </div>
        </>
    );
}
