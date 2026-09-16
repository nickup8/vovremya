import { Head, usePage } from '@inertiajs/react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';

interface DashboardProps {
    mrr: number;
    arr: number;
    avg_mrr_per_pro: number;
    users_by_tariff: Record<string, number>;
    total_users: number;
    active_subscriptions: number;
    total_masters: number;
    new_masters_7d: number;
    new_masters_30d: number;
    total_workspaces: number;
    start_count: number;
    pro_count: number;
    appointments_30d: number;
    cancellations_30d: number;
    telegram_linked: number;
    max_linked: number;
    vk_linked: number;
}

export default function Dashboard() {
    const p = usePage().props as DashboardProps;
    const total = p.total_masters || 1;

    const formatCurrency = (v: number) =>
        new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(v);

    return (
        <>
            <Head title="Обзор платформы — ИРСИ" />

            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-3xl px-4 py-10">
                    {/* Header */}
                    <h1 className="text-2xl font-bold tracking-tight text-[#181818]">ИРСИ</h1>
                    <p className="mt-1.5 text-sm text-[#62615F]">Обзор платформы</p>

                    <SuperAdminNav current="dashboard" />

                    {/* Primary KPIs */}
                    <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="Мастера" value={p.total_masters} />
                        <KpiCard label="Workspaces" value={p.total_workspaces} />
                        <KpiCard label="Start" value={p.start_count} />
                        <KpiCard label="Pro" value={p.pro_count} accent />
                    </div>

                    {/* Activity */}
                    <h2 className="mt-8 text-sm font-semibold text-[#181818]">Активность</h2>
                    <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <StatCard label="Новые за 7 дней" value={p.new_masters_7d} />
                        <StatCard label="Новые за 30 дней" value={p.new_masters_30d} />
                        <StatCard label="Создано записей за 30 дней" value={p.appointments_30d} />
                        <StatCard label="Отменено за 30 дней" value={p.cancellations_30d} />
                    </div>

                    {/* Messengers */}
                    <h2 className="mt-8 text-sm font-semibold text-[#181818]">Мессенджеры</h2>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <MessengerCard name="Telegram" count={p.telegram_linked} total={total} />
                        <MessengerCard name="MAX" count={p.max_linked} total={total} />
                        <MessengerCard name="VK" count={p.vk_linked} total={total} />
                    </div>

                    {/* Financial */}
                    <h2 className="mt-8 text-sm font-semibold text-[#181818]">Подписки</h2>
                    <p className="mt-0.5 text-xs text-[#8E8A85]">Расчёт по данным подписок</p>
                    <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <StatCard label="MRR" value={formatCurrency(p.mrr)} />
                        <StatCard label="ARR" value={formatCurrency(p.arr)} />
                        <StatCard label="Средний MRR на Pro" value={formatCurrency(p.avg_mrr_per_pro)} />
                        <StatCard label="Активные подписки" value={p.active_subscriptions} />
                    </div>
                </div>
            </div>
        </>
    );
}

function KpiCard({ label, value, accent }: { label: string; value: number; accent?: boolean }) {
    return (
        <div className="rounded-2xl border border-[#E7E4DF] bg-white p-5">
            <p className="text-xs text-[#8E8A85]">{label}</p>
            <p className={`mt-1 text-2xl font-bold tracking-tight ${accent ? 'text-[#FF5A1F]' : 'text-[#181818]'}`}>
                {value}
            </p>
        </div>
    );
}

function StatCard({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-2xl border border-[#E7E4DF] bg-white p-5">
            <p className="text-xs text-[#8E8A85]">{label}</p>
            <p className="mt-1 text-xl font-semibold text-[#181818]">{value}</p>
        </div>
    );
}

function MessengerCard({ name, count, total }: { name: string; count: number; total: number }) {
    const pct = total > 0 ? Math.round((count / total) * 100) : 0;

    return (
        <div className="rounded-2xl border border-[#E7E4DF] bg-white p-5">
            <div className="flex items-center justify-between">
                <span className="text-sm font-semibold text-[#181818]">{name}</span>
                <span className="text-xs text-[#8E8A85]">{pct}%</span>
            </div>
            <p className="mt-1 text-xl font-semibold text-[#181818]">
                {count} <span className="text-sm font-normal text-[#8E8A85]">мастеров</span>
            </p>
        </div>
    );
}
