import { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Menu, Search, Plus, MoreHorizontal,
    Users, Phone, DollarSign, CalendarCheck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Sidebar from '@/components/admin/Sidebar';

/* ═══════════════ Types ═══════════════ */

interface Client {
    id: number;
    name: string;
    phone: string | null;
    avatar_url: string | null;
    total_bookings: number;
    completed_bookings: number;
    ltv: number;
    last_visit: string | null;
}

interface AuthUser {
    name: string;
    [key: string]: unknown;
}

interface PageProps {
    clients: Client[];
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

/* ═══════════════ Helpers ═══════════════ */

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function formatCurrency(value: number): string {
    return value.toLocaleString('ru-RU') + ' ₽';
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });
}

/* ═══════════════ Client Row ═══════════════ */

function ClientRow({ client }: { client: Client }) {
    const [menuOpen, setMenuOpen] = useState(false);
    const initials = getInitials(client.name);

    return (
        <tr className="group border-b border-slate-100 transition-colors hover:bg-slate-50/50 dark:border-zinc-800 dark:hover:bg-zinc-800/30">
            {/* Клиент */}
            <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-200 to-slate-300 text-xs font-bold text-slate-600 dark:from-zinc-700 dark:to-zinc-600 dark:text-zinc-200">
                        {initials}
                    </div>
                    <span className="text-sm font-medium text-slate-900 dark:text-zinc-100">
                        {client.name}
                    </span>
                </div>
            </td>

            {/* Телефон */}
            <td className="px-4 py-3">
                <div className="flex items-center gap-1.5 text-sm text-slate-500 dark:text-zinc-400">
                    <Phone className="size-3.5" />
                    {client.phone || '—'}
                </div>
            </td>

                            {/* Всего визитов */}
                            <td className="hidden px-4 py-3 text-center md:table-cell">
                <span className="text-sm font-medium text-slate-700 dark:text-zinc-300">
                    {client.total_bookings}
                </span>
            </td>

            {/* Успешных */}
            <td className="px-4 py-3 text-center">
                <span className="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                    {client.completed_bookings}
                </span>
            </td>

            {/* LTV */}
            <td className="px-4 py-3 text-right">
                <span className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                    {formatCurrency(client.ltv)}
                </span>
            </td>

            {/* Последний визит */}
            <td className="hidden px-4 py-3 text-sm text-slate-500 dark:text-zinc-400 md:table-cell">
                {formatDate(client.last_visit)}
            </td>

            {/* Действия */}
            <td className="px-4 py-3 text-right">
                <div className="relative inline-block">
                    <button
                        onClick={() => setMenuOpen(!menuOpen)}
                        className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                    >
                        <MoreHorizontal className="size-4" />
                    </button>
                    {menuOpen && (
                        <>
                            <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
                            <div className="absolute right-0 z-20 mt-1 w-40 rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                <button
                                    onClick={() => setMenuOpen(false)}
                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 transition-colors hover:bg-slate-50 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                >
                                    <CalendarCheck className="size-4" />
                                    История визитов
                                </button>
                                <button
                                    onClick={() => setMenuOpen(false)}
                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 transition-colors hover:bg-slate-50 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                >
                                    <DollarSign className="size-4" />
                                    Финансы
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </td>
        </tr>
    );
}

/* ═══════════════ Main Clients Page ═══════════════ */

export default function ClientsPage() {
    const { clients: initialClients, auth } = usePage<PageProps>().props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [search, setSearch] = useState('');

    const userName = auth?.user?.name || 'Мастер';
    const initials = userName
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const clients = useMemo(() => {
        if (!search.trim()) return initialClients;
        const q = search.toLowerCase();
        return initialClients.filter(
            (c) =>
                c.name.toLowerCase().includes(q) ||
                (c.phone && c.phone.includes(q)),
        );
    }, [initialClients, search]);

    return (
        <>
            <Head title="База клиентов — Вовремя" />

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
                                База клиентов
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
                        <div className="space-y-4">
                            {/* ─── Top Panel ─── */}
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="flex items-center gap-3">
                                    <h2 className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                                        Клиентская база
                                    </h2>
                                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        Всего: {clients.length}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400 dark:text-zinc-500" />
                                        <Input
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            placeholder="Поиск по имени или телефону..."
                                            className="w-64 pl-9 bg-slate-50 dark:bg-zinc-800 border-slate-200 dark:border-zinc-700"
                                        />
                                    </div>
                                    <Button className="bg-blue-600 text-white hover:bg-blue-700">
                                        <Plus className="size-4" />
                                        Добавить клиента
                                    </Button>
                                </div>
                            </div>

                            {/* ─── Clients Table ─── */}
                            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="overflow-x-auto">
                                    <table className="w-full">
                                        <thead>
                                            <tr className="border-b border-slate-200 bg-slate-50 dark:border-zinc-800 dark:bg-zinc-900/50">
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                                    Клиент
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                                    Телефон
                                                </th>
                                                <th className="hidden px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-zinc-400 md:table-cell">
                                                    Всего визитов
                                                </th>
                                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                                    Успешных
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                                    Общая сумма
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-zinc-400 md:table-cell">
                                                    Последний визит
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-zinc-400">
                                                    Действия
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {clients.length === 0 ? (
                                                <tr>
                                                    <td colSpan={7} className="px-4 py-16 text-center">
                                                        <Users className="mx-auto size-10 text-slate-300 dark:text-zinc-600" />
                                                        <p className="mt-3 text-sm text-slate-500 dark:text-zinc-400">
                                                            {search ? 'Клиенты не найдены' : 'Пока нет клиентов'}
                                                        </p>
                                                    </td>
                                                </tr>
                                            ) : (
                                                clients.map((client) => (
                                                    <ClientRow key={client.id} client={client} />
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        </>
    );
}
