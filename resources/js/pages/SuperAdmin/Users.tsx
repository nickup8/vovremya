import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface User {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    tariff: string;
    is_blocked: boolean;
    expires_at: string | null;
    created_at: string;
}

interface PaginatedUsers {
    data: User[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface UsersProps {
    users: PaginatedUsers;
    filters: {
        search?: string;
        tariff?: string;
        is_blocked?: boolean;
    };
}

export default function Users() {
    const { users, filters } = usePage().props as UsersProps;
    const [search, setSearch] = useState(filters.search || '');
    const [tariffFilter, setTariffFilter] = useState(filters.tariff || '');

    const handleSearch = () => {
        router.get(route('super_admin.users'), {
            search,
            tariff: tariffFilter,
        }, { preserveState: true });
    };

    const handleBlock = (userId: number) => {
        router.post(route('super_admin.block', userId), {}, { preserveState: true });
    };

    const handleExtend = (userId: number) => {
        const days = prompt('Количество дней для продления:', '30');
        if (days) {
            router.post(route('super_admin.extend', userId), { days: parseInt(days) }, { preserveState: true });
        }
    };

    const handleImpersonate = (userId: number) => {
        if (confirm('Войти как этот пользователь?')) {
            router.post(route('super_admin.impersonate', userId));
        }
    };

    return (
        <>
            <Head title="Super Admin — Пользователи" />

            <div className="min-h-screen bg-slate-50 dark:bg-zinc-950">
                <header className="border-b border-slate-200 bg-white px-6 py-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="flex items-center justify-between">
                        <h1 className="text-xl font-bold text-slate-900 dark:text-zinc-50">Пользователи</h1>
                        <a href="/admin-root" className="text-sm text-blue-600 hover:underline">← Dashboard</a>
                    </div>
                </header>

                <main className="mx-auto max-w-7xl px-6 py-8">
                    <div className="mb-6 flex flex-wrap gap-3">
                        <input
                            type="text"
                            placeholder="Поиск по имени, телефону, email..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <select
                            value={tariffFilter}
                            onChange={(e) => setTariffFilter(e.target.value)}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option value="">Все тарифы</option>
                            <option value="free">Free</option>
                            <option value="pro">Pro</option>
                            <option value="studio">Studio</option>
                        </select>
                        <button
                            onClick={handleSearch}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                        >
                            Найти
                        </button>
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 dark:border-zinc-800 dark:bg-zinc-900">
                                <tr>
                                    <th className="px-4 py-3 font-medium text-slate-600 dark:text-zinc-400">Имя</th>
                                    <th className="px-4 py-3 font-medium text-slate-600 dark:text-zinc-400">Email</th>
                                    <th className="px-4 py-3 font-medium text-slate-600 dark:text-zinc-400">Телефон</th>
                                    <th className="px-4 py-3 font-medium text-slate-600 dark:text-zinc-400">Тариф</th>
                                    <th className="px-4 py-3 font-medium text-slate-600 dark:text-zinc-400">Статус</th>
                                    <th className="px-4 py-3 font-medium text-slate-600 dark:text-zinc-400">Действия</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-zinc-800">
                                {users.data.map((user) => (
                                    <tr key={user.id} className="hover:bg-slate-50 dark:hover:bg-zinc-900/50">
                                        <td className="px-4 py-3 text-slate-900 dark:text-zinc-100">{user.name}</td>
                                        <td className="px-4 py-3 text-slate-500 dark:text-zinc-400">{user.email}</td>
                                        <td className="px-4 py-3 text-slate-500 dark:text-zinc-400">{user.phone || '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                                                user.tariff === 'studio' ? 'bg-emerald-100 text-emerald-700' :
                                                user.tariff === 'pro' ? 'bg-blue-100 text-blue-700' :
                                                'bg-slate-100 text-slate-700'
                                            }`}>
                                                {user.tariff}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                                                user.is_blocked ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'
                                            }`}>
                                                {user.is_blocked ? 'Заблокирован' : 'Активен'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex gap-2">
                                                <button
                                                    onClick={() => handleBlock(user.id)}
                                                    className="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                                                >
                                                    {user.is_blocked ? 'Разблокировать' : 'Блокировать'}
                                                </button>
                                                <button
                                                    onClick={() => handleExtend(user.id)}
                                                    className="rounded px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50"
                                                >
                                                    Продлить
                                                </button>
                                                <button
                                                    onClick={() => handleImpersonate(user.id)}
                                                    className="rounded px-2 py-1 text-xs font-medium text-emerald-600 hover:bg-emerald-50"
                                                >
                                                    Войти как
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {users.last_page > 1 && (
                        <div className="mt-4 flex justify-center gap-2">
                            {Array.from({ length: users.last_page }, (_, i) => i + 1).map((page) => (
                                <button
                                    key={page}
                                    onClick={() => router.get(route('super_admin.users'), { ...filters, page }, { preserveState: true })}
                                    className={`rounded px-3 py-1 text-sm ${
                                        page === users.current_page
                                            ? 'bg-blue-600 text-white'
                                            : 'bg-white text-slate-700 hover:bg-slate-100 dark:bg-zinc-800 dark:text-zinc-300'
                                    }`}
                                >
                                    {page}
                                </button>
                            ))}
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
