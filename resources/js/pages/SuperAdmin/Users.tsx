import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';
import { toast } from 'sonner';

interface User {
    id: string;
    name: string;
    email: string;
    phone: string | null;
    tariff: string;
    is_master: boolean;
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
    flash?: { success?: string; error?: string };
}

export default function Users() {
    const { users, filters, flash, auth } = usePage().props as UsersProps & { auth: { user?: { id: string } } };
    const currentUserId = auth?.user?.id;
    const [search, setSearch] = useState(filters.search || '');
    const [tariffFilter, setTariffFilter] = useState(filters.tariff || '');

    const [extendUser, setExtendUser] = useState<User | null>(null);
    const [extendDays, setExtendDays] = useState('30');
    const [extendLoading, setExtendLoading] = useState(false);
    const [extendError, setExtendError] = useState('');

    const [blockUser, setBlockUser] = useState<User | null>(null);

    const [notifyModal, setNotifyModal] = useState<{ recipientType: 'all' | 'user'; user?: User } | null>(null);
    const [notifyTitle, setNotifyTitle] = useState('');
    const [notifyBody, setNotifyBody] = useState('');
    const [notifyLoading, setNotifyLoading] = useState(false);
    const [notifyErrors, setNotifyErrors] = useState<Record<string, string>>({});

    const hasFilters = search || tariffFilter;

    function handleSearch() {
        router.get('/admin-root/users', { search, tariff: tariffFilter }, { preserveState: true });
    }

    function resetFilters() {
        setSearch('');
        setTariffFilter('');
        router.get('/admin-root/users');
    }

    function handleBlockConfirm() {
        if (!blockUser) return;
        router.post(`/admin-root/users/${blockUser.id}/block`, {}, {
            preserveState: true,
            onFinish: () => setBlockUser(null),
        });
    }

    function handleUnblock(userId: string) {
        router.post(`/admin-root/users/${userId}/block`, {}, { preserveState: true });
    }

    function handleExtendSubmit() {
        if (!extendUser) return;
        const days = parseInt(extendDays, 10);
        if (!days || days < 1) {
            setExtendError('Укажите целое число ≥ 1');
            return;
        }
        setExtendLoading(true);
        setExtendError('');
        router.post(`/admin-root/users/${extendUser.id}/extend`, { days }, {
            preserveState: true,
            onSuccess: () => {
                setExtendUser(null);
                setExtendDays('30');
            },
            onError: () => setExtendError('Ошибка сервера'),
            onFinish: () => setExtendLoading(false),
        });
    }

    function handleImpersonate(userId: string) {
        router.post(`/admin-root/users/${userId}/impersonate`);
    }

    function handleNotifySubmit() {
        if (!notifyModal) return;
        setNotifyLoading(true);
        setNotifyErrors({});
        router.post('/admin-root/notifications', {
            recipient_type: notifyModal.recipientType,
            user_id: notifyModal.user?.id,
            title: notifyTitle,
            body: notifyBody,
        }, {
            preserveState: true,
            onSuccess: () => {
                setNotifyModal(null);
                setNotifyTitle('');
                setNotifyBody('');
                toast.success('Уведомление отправлено');
            },
            onError: (errors: Record<string, string>) => {
                const mapped: Record<string, string> = {};
                for (const [k, v] of Object.entries(errors)) {
                    mapped[k] = Array.isArray(v) ? v[0] : String(v);
                }
                setNotifyErrors(mapped);
            },
            onFinish: () => setNotifyLoading(false),
        });
    }

    return (
        <>
            <Head title="Пользователи — ИРСИ" />

            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-4xl px-4 py-10">
                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-[#181818]">Пользователи</h1>
                            <p className="mt-1.5 text-sm text-[#62615F]">Мастера и их доступ к ИРСИ</p>
                        </div>
                        <button
                            onClick={() => {
                                setNotifyModal({ recipientType: 'all' });
                                setNotifyTitle('');
                                setNotifyBody('');
                                setNotifyErrors({});
                            }}
                            className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2.5 text-sm font-semibold text-[#181818] transition-colors hover:bg-[#F7F5F1]"
                        >
                            Отправить уведомление
                        </button>
                    </div>

                    <SuperAdminNav current="users" />

                    {/* Flash */}
                    {flash?.success && (
                        <div className="mt-5 rounded-xl border border-[#E7E4DF] bg-white px-4 py-3 text-sm font-medium text-[#16875E]">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="mt-5 rounded-xl border border-[#E7E4DF] bg-white px-4 py-3 text-sm font-medium text-[#C44351]">
                            {flash.error}
                        </div>
                    )}

                    {/* Filters */}
                    <div className="mt-6 rounded-2xl border border-[#E7E4DF] bg-white p-5">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="flex-1 min-w-[180px]">
                                <input
                                    type="text"
                                    placeholder="Поиск по имени или телефону"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    className="w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                                />
                            </div>
                            <div>
                                <select
                                    value={tariffFilter}
                                    onChange={(e) => setTariffFilter(e.target.value)}
                                    className="rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                                >
                                    <option value="">Все тарифы</option>
                                    <option value="start">Start</option>
                                    <option value="pro">Pro</option>
                                </select>
                            </div>
                            <button
                                onClick={handleSearch}
                                className="rounded-xl bg-[#FF5A1F] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14]"
                            >
                                Найти
                            </button>
                            {hasFilters && (
                                <button
                                    onClick={resetFilters}
                                    className="rounded-xl border border-[#E7E4DF] bg-white px-5 py-2.5 text-sm font-semibold text-[#181818] transition-colors hover:bg-[#F7F5F1]"
                                >
                                    Сбросить
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Table */}
                    {users.data.length === 0 ? (
                        <div className="mt-10 text-center">
                            <p className="text-sm font-semibold text-[#181818]">Пользователи не найдены</p>
                            <p className="mt-1 text-sm text-[#8E8A85]">
                                Попробуйте изменить параметры поиска.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="mt-6 overflow-x-auto rounded-2xl border border-[#E7E4DF] bg-white">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-[#F0EEEA] text-left">
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Имя</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Контакт</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Тариф</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Статус</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#F0EEEA]">
                                        {users.data.map((user) => (
                                            <tr key={user.id} className="align-top">
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-[#181818]">{user.name}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="text-[#181818]">{user.phone || '—'}</p>
                                                    {user.email && (
                                                        <p className="text-xs text-[#8E8A85]">{user.email}</p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-block rounded-lg px-2.5 py-1 text-xs font-semibold ${
                                                        user.tariff === 'pro'
                                                            ? 'bg-[#FF5A1F]/10 text-[#FF5A1F]'
                                                            : 'bg-[#F7F5F1] text-[#62615F]'
                                                    }`}>
                                                        {user.tariff}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-block rounded-lg px-2.5 py-1 text-xs font-semibold ${
                                                        user.is_blocked
                                                            ? 'bg-[#F7F5F1] text-[#C44351]'
                                                            : 'bg-[#F7F5F1] text-[#62615F]'
                                                    }`}>
                                                        {user.is_blocked ? 'Заблокирован' : 'Активен'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-wrap gap-1">
                                                        {user.id !== currentUserId && (
                                                            user.is_blocked ? (
                                                                <button
                                                                    onClick={() => handleUnblock(user.id)}
                                                                    className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#181818] hover:bg-[#F7F5F1]"
                                                                >
                                                                    Разблокировать
                                                                </button>
                                                            ) : (
                                                                <button
                                                                    onClick={() => setBlockUser(user)}
                                                                    className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#C44351] hover:bg-red-50"
                                                                >
                                                                    Заблокировать
                                                            </button>
                                                        )
                                                        )}
                                                        <button
                                                            onClick={() => {
                                                                setExtendUser(user);
                                                                setExtendDays('30');
                                                                setExtendError('');
                                                            }}
                                                            className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                        >
                                                            Продлить
                                                        </button>
                                                        <button
                                                            onClick={() => handleImpersonate(user.id)}
                                                            className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                        >
                                                            Войти как
                                                        </button>
                                                        {!user.is_blocked && user.is_master && (
                                                            <button
                                                                onClick={() => {
                                                                    setNotifyModal({ recipientType: 'user', user });
                                                                    setNotifyTitle('');
                                                                    setNotifyBody('');
                                                                    setNotifyErrors({});
                                                                }}
                                                                className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                            >
                                                                Уведомить
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {users.last_page > 1 && (
                                <div className="mt-4 flex items-center justify-between text-sm">
                                    <span className="text-[#8E8A85]">
                                        {users.total} {users.total % 10 === 1 && users.total !== 11 ? 'пользователь' : (users.total % 10 >= 2 && users.total % 10 <= 4 && (users.total < 12 || users.total > 14)) ? 'пользователя' : 'пользователей'}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        {users.current_page > 1 && (
                                            <button
                                                onClick={() => router.get('/admin-root/users', { ...filters, page: String(users.current_page - 1) }, { preserveState: true })}
                                                className="rounded-lg border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm text-[#181818] hover:bg-[#F7F5F1]"
                                            >
                                                Назад
                                            </button>
                                        )}
                                        <span className="text-[#62615F]">
                                            {users.current_page} / {users.last_page}
                                        </span>
                                        {users.current_page < users.last_page && (
                                            <button
                                                onClick={() => router.get('/admin-root/users', { ...filters, page: String(users.current_page + 1) }, { preserveState: true })}
                                                className="rounded-lg border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm text-[#181818] hover:bg-[#F7F5F1]"
                                            >
                                                Вперёд
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* Extend Modal */}
            {extendUser && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setExtendUser(null)}>
                    <div className="w-full max-w-sm rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h2 className="text-lg font-bold tracking-tight text-[#181818]">Продлить Pro</h2>
                        <p className="mt-1 text-sm text-[#62615F]">{extendUser.name}</p>

                        <label className="mt-4 block text-sm font-semibold text-[#181818]">
                            Количество дней
                        </label>
                        <input
                            type="number"
                            min="1"
                            value={extendDays}
                            onChange={(e) => setExtendDays(e.target.value)}
                            className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        {extendError && (
                            <p className="mt-1.5 text-xs text-[#C44351]">{extendError}</p>
                        )}

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                onClick={() => setExtendUser(null)}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                            >
                                Отмена
                            </button>
                            <button
                                onClick={handleExtendSubmit}
                                disabled={extendLoading}
                                className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                            >
                                {extendLoading ? 'Сохранение…' : 'Продлить'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Block Confirmation Modal */}
            {blockUser && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setBlockUser(null)}>
                    <div className="w-full max-w-sm rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h2 className="text-lg font-bold tracking-tight text-[#181818]">Заблокировать пользователя?</h2>
                        <p className="mt-1 text-sm text-[#62615F]">
                            {blockUser.name} потеряет доступ к ИРСИ.
                        </p>

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                onClick={() => setBlockUser(null)}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                            >
                                Отмена
                            </button>
                            <button
                                onClick={handleBlockConfirm}
                                className="rounded-xl bg-[#C44351] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#A83843]"
                            >
                                Заблокировать
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Notification Modal */}
            {notifyModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setNotifyModal(null)}>
                    <div className="w-full max-w-md rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h2 className="text-lg font-bold tracking-tight text-[#181818]">Отправить уведомление</h2>
                        <p className="mt-1 text-sm text-[#62615F]">
                            {notifyModal.recipientType === 'all'
                                ? 'Все активные мастера'
                                : notifyModal.user?.name
                            }
                        </p>

                        <label className="mt-4 block text-sm font-semibold text-[#181818]">
                            Заголовок
                        </label>
                        <input
                            type="text"
                            value={notifyTitle}
                            onChange={(e) => setNotifyTitle(e.target.value)}
                            placeholder="Заголовок уведомления"
                            className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        {notifyErrors.title && (
                            <p className="mt-1.5 text-xs text-[#C44351]">{notifyErrors.title}</p>
                        )}

                        <label className="mt-4 block text-sm font-semibold text-[#181818]">
                            Сообщение
                        </label>
                        <textarea
                            value={notifyBody}
                            onChange={(e) => setNotifyBody(e.target.value)}
                            placeholder="Текст уведомления"
                            rows={4}
                            className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none transition-colors focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                        />
                        {notifyErrors.body && (
                            <p className="mt-1.5 text-xs text-[#C44351]">{notifyErrors.body}</p>
                        )}

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                onClick={() => setNotifyModal(null)}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                            >
                                Отмена
                            </button>
                            <button
                                onClick={handleNotifySubmit}
                                disabled={notifyLoading || !notifyTitle.trim() || !notifyBody.trim()}
                                className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                            >
                                {notifyLoading ? 'Отправка…' : 'Отправить'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
