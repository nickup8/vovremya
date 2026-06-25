import { useState, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Menu, Search, Plus,
    Users, Phone, Send, MessageCircle,
    CalendarPlus, Pencil, AlertTriangle, ShieldCheck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Sidebar from '@/components/admin/Sidebar';

/* ═══════════════ Types ═══════════════ */

interface Client {
    id: number;
    name: string;
    phone: string | null;
    telegram_id: string | null;
    max_id: string | null;
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

type FilterType = 'all' | 'active' | 'blocked';

const FILTER_TABS: { key: FilterType; label: string }[] = [
    { key: 'all', label: 'Все' },
    { key: 'active', label: 'Активные' },
    { key: 'blocked', label: 'Блок' },
];

/* ═══════════════ Client Card ═══════════════ */

function ClientCard({ client }: { client: Client }) {
    const initials = getInitials(client.name);

    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
            {/* Header */}
            <div className="flex items-start justify-between border-b border-slate-100 p-4 dark:border-zinc-800">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-200 to-slate-300 text-sm font-bold text-slate-600 dark:from-zinc-700 dark:to-zinc-600 dark:text-zinc-200">
                        {initials}
                    </div>
                    <div>
                        <h3 className="text-sm font-bold text-slate-900 dark:text-zinc-100">
                            {client.name}
                        </h3>
                        <p className="flex items-center gap-1 font-mono text-xs text-slate-500 dark:text-zinc-400">
                            <Phone className="size-3" />
                            {client.phone || '—'}
                        </p>
                    </div>
                </div>
                {client.total_bookings > 5 ? (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400">
                        <ShieldCheck className="size-3" />
                        Активен
                    </span>
                ) : (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-600 dark:border-red-800 dark:bg-red-950/40 dark:text-red-400">
                        <AlertTriangle className="size-3" />
                        Блок
                    </span>
                )}
            </div>

            {/* Body */}
            <div className="space-y-3 p-4">
                {/* Tags */}
                <div className="flex flex-wrap gap-2">
                    {client.telegram_id && (
                        <span className="flex items-center gap-1 rounded-full border border-blue-100 bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-600 dark:border-blue-900/50 dark:bg-blue-950/40 dark:text-blue-400">
                            <Send className="size-3" />
                            Telegram
                        </span>
                    )}
                    {client.max_id && (
                        <span className="flex items-center gap-1 rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                            <MessageCircle className="size-3" />
                            Max
                        </span>
                    )}
                    <span className="rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                        {client.total_bookings} визитов
                    </span>
                </div>

                {/* Action buttons */}
                <div className="flex gap-2 pt-2">
                    <button className="flex flex-1 items-center justify-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                        <CalendarPlus className="size-3" />
                        Записать
                    </button>
                    <button className="flex flex-1 items-center justify-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                        <Pencil className="size-3" />
                        Изменить
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Main Clients Page ═══════════════ */

export default function ClientsPage() {
    const { clients: initialClients = [], auth } = usePage<PageProps>().props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<FilterType>('all');
    const [createDialogOpen, setCreateDialogOpen] = useState(false);

    const userName = auth?.user?.name || 'Мастер';
    const initials = userName
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const clients = useMemo(() => {
        let result = initialClients;

        if (search.trim()) {
            const q = search.toLowerCase();
            result = result.filter(
                (c) =>
                    c.name.toLowerCase().includes(q) ||
                    (c.phone && c.phone.includes(q)),
            );
        }

        if (filter === 'active') {
            result = result.filter((c) => c.total_bookings > 5);
        } else if (filter === 'blocked') {
            result = result.filter((c) => c.total_bookings <= 5);
        }

        return result;
    }, [initialClients, search, filter]);

    return (
        <>
            <Head title="База клиентов — Вовремя" />

            <div className="flex min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-zinc-900 dark:text-zinc-50">
                <Sidebar mobileOpen={mobileMenuOpen} onMobileClose={() => setMobileMenuOpen(false)} />

                <div className="flex min-w-0 flex-1 flex-col">
                    {/* Header */}
                    <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-xs md:px-6 dark:border-zinc-800 dark:bg-zinc-900/80">
                        <div className="flex items-center gap-4">
                            <button
                                onClick={() => setMobileMenuOpen(true)}
                                className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800 lg:hidden"
                            >
                                <Menu className="size-5 text-slate-700 dark:text-zinc-300" />
                            </button>
                            <h1 className="text-lg font-semibold text-slate-900 md:text-xl dark:text-zinc-100">
                                База клиентов
                            </h1>
                        </div>
                        <div className="flex items-center gap-4">
                            <div className="hidden text-right sm:block">
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
                            {/* ─── Top Panel: Search + Filters + Add ─── */}
                            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                                <div className="relative min-w-[200px] flex-1">
                                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400 dark:text-zinc-500" />
                                    <Input
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Поиск по имени или телефону..."
                                        className="border-slate-200 bg-slate-50 pl-9 dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                </div>
                                <div className="flex rounded-md bg-slate-100 p-1 dark:bg-zinc-800">
                                    {FILTER_TABS.map((tab) => (
                                        <button
                                            key={tab.key}
                                            onClick={() => setFilter(tab.key)}
                                            className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
                                                filter === tab.key
                                                    ? 'bg-white text-slate-900 shadow dark:bg-zinc-700 dark:text-zinc-100'
                                                    : 'text-slate-600 hover:text-slate-900 dark:text-zinc-400 dark:hover:text-zinc-200'
                                            }`}
                                        >
                                            {tab.label}
                                        </button>
                                    ))}
                                </div>
                                <Button
                                    className="bg-blue-600 text-white hover:bg-blue-700"
                                    onClick={() => setCreateDialogOpen(true)}
                                >
                                    <Plus className="size-4" />
                                    Добавить
                                </Button>
                            </div>

                            {/* ─── Client Cards Grid ─── */}
                            {clients.length === 0 ? (
                                <div className="rounded-lg border border-slate-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                                    <Users className="mx-auto size-12 text-slate-300 dark:text-zinc-600" />
                                    <p className="mt-3 text-sm text-slate-500 dark:text-zinc-400">
                                        {search ? 'Клиенты не найдены' : 'Пока нет клиентов'}
                                    </p>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {clients.map((client) => (
                                        <ClientCard key={client.id} client={client} />
                                    ))}
                                </div>
                            )}
                        </div>
                    </main>
                </div>
            </div>

            {/* ─── Create Client Dialog Placeholder ─── */}
            {createDialogOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center">
                    <div className="fixed inset-0 bg-black/50" onClick={() => setCreateDialogOpen(false)} />
                    <div className="relative z-10 w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                        <h3 className="text-lg font-semibold text-slate-900 dark:text-zinc-100">
                            Новый клиент
                        </h3>
                        <p className="mt-2 text-sm text-slate-500 dark:text-zinc-400">
                            Форма создания клиента будет доступна после реализации API.
                        </p>
                        <div className="mt-4 flex justify-end">
                            <Button variant="outline" onClick={() => setCreateDialogOpen(false)}>
                                Закрыть
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
