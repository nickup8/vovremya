import { Head, router, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';

interface DashboardProps {
    mrr: number;
    arr: number;
    ltv: number;
    users_by_tariff: Record<string, number>;
    total_users: number;
    active_subscriptions: number;
}

function MetricCard({ title, value, subtitle }: { title: string; value: string | number; subtitle?: string }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
            <p className="text-sm font-medium text-slate-500 dark:text-zinc-400">{title}</p>
            <p className="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-zinc-50">{value}</p>
            {subtitle && (
                <p className="mt-1 text-xs text-slate-400 dark:text-zinc-500">{subtitle}</p>
            )}
        </div>
    );
}

export default function Dashboard() {
    const { mrr, arr, ltv, users_by_tariff, total_users, active_subscriptions } = usePage().props as DashboardProps;

    const formatCurrency = (value: number) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(value);

    return (
        <>
            <Head title="Super Admin — Dashboard" />

            <div className="min-h-screen bg-slate-50 dark:bg-zinc-950">
                <header className="border-b border-slate-200 bg-white px-6 py-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="flex items-center justify-between">
                        <h1 className="text-xl font-bold text-slate-900 dark:text-zinc-50">Super Admin Dashboard</h1>
                        <a
                            href="/admin-root/users"
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                        >
                            Управление пользователями
                        </a>
                    </div>
                </header>

                <main className="mx-auto max-w-7xl px-6 py-8">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <MetricCard title="MRR" value={formatCurrency(mrr)} subtitle="Monthly Recurring Revenue" />
                        <MetricCard title="ARR" value={formatCurrency(arr)} subtitle="Annual Recurring Revenue" />
                        <MetricCard title="LTV" value={formatCurrency(ltv)} subtitle="Lifetime Value на пользователя" />
                        <MetricCard title="Всего пользователей" value={total_users} />
                        <MetricCard title="Активные подписки" value={active_subscriptions} />
                    </div>

                    <div className="mt-8">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-zinc-50">Пользователи по тарифам</h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                                <p className="text-sm font-medium text-slate-500 dark:text-zinc-400">Free</p>
                                <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-zinc-50">{users_by_tariff.free ?? 0}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                                <p className="text-sm font-medium text-slate-500 dark:text-zinc-400">Pro</p>
                                <p className="mt-2 text-2xl font-bold text-blue-600 dark:text-blue-400">{users_by_tariff.pro ?? 0}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                                <p className="text-sm font-medium text-slate-500 dark:text-zinc-400">Studio</p>
                                <p className="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{users_by_tariff.studio ?? 0}</p>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
