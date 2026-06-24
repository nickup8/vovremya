import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Menu, Download,
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

interface AuthUser {
    name: string;
    [key: string]: unknown;
}

interface PageProps {
    metrics: Metrics;
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

const PERIOD_TABS = ['Неделя', 'Месяц', 'Квартал', 'Год'];

const WEEK_DATA = [65, 45, 80, 55, 90, 70, 40];
const WEEK_DAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

const SERVICE_DATA = [
    { name: 'Маникюр + гель-лак', value: 38 },
    { name: 'Педикюр', value: 22 },
    { name: 'Наращивание ресниц', value: 18 },
    { name: 'Коррекция', value: 14 },
    { name: 'Дизайн', value: 8 },
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
    const { metrics, auth } = usePage<PageProps>().props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [activePeriod, setActivePeriod] = useState('Месяц');

    const userName = auth?.user?.name || 'Мастер';
    const initials = userName
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const stats = [
        {
            icon: Wallet,
            label: 'Заработано за период',
            value: metrics.revenue.toLocaleString('ru-RU') + ' ₽',
            change: '+12.3%',
            positive: true,
            color: 'emerald',
        },
        {
            icon: Gauge,
            label: 'Процент посещаемости',
            value: metrics.attendance_rate + '%',
            change: '+5.2%',
            positive: true,
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
            change: '+' + metrics.total_visits.toString(),
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
                                <p className="text-xs text-slate-400 dark:text-zinc-500">Тариф: Профи</p>
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
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-zinc-800">
                                    {PERIOD_TABS.map((period) => (
                                        <button
                                            key={period}
                                            onClick={() => setActivePeriod(period)}
                                            className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                                activePeriod === period
                                                    ? 'bg-white text-slate-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100'
                                                    : 'text-slate-600 hover:text-slate-900 dark:text-zinc-400 dark:hover:text-zinc-200'
                                            }`}
                                        >
                                            {period}
                                        </button>
                                    ))}
                                </div>
                                <button className="flex items-center gap-1.5 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    <Download className="size-3.5" />
                                    Экспорт
                                </button>
                            </div>

                            {/* ─── Stat Cards ─── */}
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                                {stats.map((stat) => (
                                    <StatCard key={stat.label} {...stat} />
                                ))}
                            </div>

                            {/* ─── Charts Row ─── */}
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {/* Загрузка по дням */}
                                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="mb-4">
                                        <h3 className="font-semibold text-slate-900 dark:text-zinc-100">Загрузка по дням</h3>
                                        <p className="text-xs text-slate-500 dark:text-zinc-400">Количество записей за неделю</p>
                                    </div>
                                    <div className="flex h-48 items-end justify-between gap-2">
                                        {WEEK_DATA.map((val, i) => (
                                            <div key={i} className="flex flex-1 flex-col items-center gap-2">
                                                <div className="relative flex w-full flex-1 items-end rounded-t-md bg-slate-100 dark:bg-zinc-800">
                                                    <div
                                                        className="w-full rounded-t-md bg-gradient-to-t from-blue-600 to-blue-400 transition-all dark:from-blue-500 dark:to-blue-400"
                                                        style={{ height: `${val}%` }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium text-slate-500 dark:text-zinc-400">
                                                    {WEEK_DAYS[i]}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Рейтинг услуг */}
                                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="mb-4">
                                        <h3 className="font-semibold text-slate-900 dark:text-zinc-100">Рейтинг услуг</h3>
                                        <p className="text-xs text-slate-500 dark:text-zinc-400">Популярность процедур</p>
                                    </div>
                                    <div className="space-y-3">
                                        {SERVICE_DATA.map((s) => (
                                            <div key={s.name}>
                                                <div className="mb-1 flex items-center justify-between">
                                                    <span className="text-sm font-medium text-slate-700 dark:text-zinc-300">{s.name}</span>
                                                    <span className="text-sm font-bold text-slate-900 dark:text-zinc-100">{s.value}%</span>
                                                </div>
                                                <div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
                                                    <div
                                                        className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 transition-all"
                                                        style={{ width: `${s.value * 2.5}%` }}
                                                    />
                                                </div>
                                            </div>
                                        ))}
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
