import { Head, router, usePage } from '@inertiajs/react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';

interface AuditEntry {
    id: string;
    action: string;
    target_type: string | null;
    target_id: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    super_admin: { id: string; name: string; email: string } | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Admin {
    id: string;
    name: string;
}

interface AuditProps {
    logs: Paginated<AuditEntry>;
    filters: {
        action?: string;
        super_admin?: string;
        date_from?: string;
        date_to?: string;
    };
    actions: string[];
    admins: Admin[];
}

const ACTION_LABELS: Record<string, string> = {
    'user.blocked': 'Пользователь заблокирован',
    'user.unblocked': 'Пользователь разблокирован',
    'subscription.extended': 'Подписка продлена',
    'impersonation.started': 'Вход от имени пользователя',
    'impersonation.ended': 'Выход из режима пользователя',
    'plan.start_limit_updated': 'Изменён лимит Start',
    'plan.pro_price_updated': 'Изменена цена Pro',
    'platform_admin.access_granted': 'Назначен администратор',
    'platform_admin.permissions_changed': 'Изменены права администратора',
    'platform_admin.activated': 'Доступ администратора включён',
    'platform_admin.deactivated': 'Доступ администратора отключён',
};

const DIFF_LABELS: Record<string, string> = {
    is_blocked: 'Блокировка',
    max_appointments_per_month: 'Лимит записей',
    price_monthly: 'Цена',
    expires_at: 'Срок действия',
    target_user_id: 'Пользователь',
    impersonated_user_id: 'Пользователь',
};

const UNITS: Record<string, string> = {
    max_appointments_per_month: 'записей',
    price_monthly: '₽',
};

export default function Audit() {
    const { logs, filters, actions, admins } = usePage().props as AuditProps;

    function applyFilters(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = v.toString();
        });
        router.get('/admin-root/audit', params, { preserveState: true });
    }

    function resetFilters() {
        router.get('/admin-root/audit');
    }

    function formatValue(key: string, value: unknown): string {
        if (key === 'is_blocked') return value ? 'Да' : 'Нет';
        if (key === 'price_monthly') return `${value} ₽`;
        if (key === 'max_appointments_per_month') return `${value} записей`;
        if (key === 'expires_at' && typeof value === 'string') {
            return new Date(value).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
        }
        return String(value);
    }

    function formatDiff(entry: AuditEntry): string {
        if (entry.metadata?.target_user_id) {
            return `Пользователь: ${String(entry.metadata.target_user_id).slice(0, 8)}`;
        }
        if (entry.metadata?.impersonated_user_id) {
            return `Пользователь: ${String(entry.metadata.impersonated_user_id).slice(0, 8)}`;
        }
        if (entry.before && entry.after) {
            return Object.keys(entry.after)
                .map((k) => {
                    const label = DIFF_LABELS[k] || k;
                    return `${label}: ${formatValue(k, entry.before![k])} → ${formatValue(k, entry.after![k])}`;
                })
                .join(', ');
        }
        return 'Данные изменены';
    }

    return (
        <>
            <Head title="Журнал действий — ИРСИ" />

            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-4xl px-4 py-10">
                    <h1 className="text-2xl font-bold tracking-tight text-[#181818]">Журнал действий</h1>
                    <p className="mt-1.5 text-sm text-[#62615F]">История изменений и административных действий</p>

                    <SuperAdminNav current="audit" />

                    {/* Filters */}
                    <form onSubmit={applyFilters} className="mt-6">
                        <div className="flex flex-wrap items-end gap-3">
                            <div>
                                <label className="block text-xs text-[#8E8A85]">Действие</label>
                                <select
                                    name="action"
                                    defaultValue={filters.action || ''}
                                    className="mt-1 rounded-xl border border-[#E7E4DF] bg-white px-3 py-2 text-sm text-[#181818] outline-none focus:border-[#FF5A1F]"
                                >
                                    <option value="">Все</option>
                                    {actions.map((a) => (
                                        <option key={a} value={a}>{ACTION_LABELS[a] || a}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-[#8E8A85]">Администратор</label>
                                <select
                                    name="super_admin"
                                    defaultValue={filters.super_admin || ''}
                                    className="mt-1 rounded-xl border border-[#E7E4DF] bg-white px-3 py-2 text-sm text-[#181818] outline-none focus:border-[#FF5A1F]"
                                >
                                    <option value="">Все</option>
                                    {admins.map((a) => (
                                        <option key={a.id} value={a.id}>{a.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-[#8E8A85]">Дата с</label>
                                <input
                                    type="date"
                                    name="date_from"
                                    defaultValue={filters.date_from || ''}
                                    className="mt-1 rounded-xl border border-[#E7E4DF] bg-white px-3 py-2 text-sm text-[#181818] outline-none focus:border-[#FF5A1F]"
                                />
                            </div>
                            <div>
                                <label className="block text-xs text-[#8E8A85]">Дата по</label>
                                <input
                                    type="date"
                                    name="date_to"
                                    defaultValue={filters.date_to || ''}
                                    className="mt-1 rounded-xl border border-[#E7E4DF] bg-white px-3 py-2 text-sm text-[#181818] outline-none focus:border-[#FF5A1F]"
                                />
                            </div>
                            <button
                                type="submit"
                                className="rounded-xl bg-[#FF5A1F] px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14]"
                            >
                                Применить
                            </button>
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-5 py-2 text-sm font-semibold text-[#181818] transition-colors hover:bg-[#F7F5F1]"
                            >
                                Сбросить
                            </button>
                        </div>
                    </form>

                    {/* Content */}
                    {logs.data.length === 0 ? (
                        <div className="mt-10 text-center">
                            <p className="text-sm font-semibold text-[#181818]">Журнал пока пуст</p>
                            <p className="mt-1 text-sm text-[#8E8A85]">
                                Здесь появятся изменения, выполненные из супер-админки.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="mt-6 overflow-x-auto rounded-2xl border border-[#E7E4DF] bg-white">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-[#F0EEEA] text-left">
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Дата</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Администратор</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Действие</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Объект</th>
                                            <th className="px-4 py-3 text-xs font-semibold text-[#8E8A85]">Изменение</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#F0EEEA]">
                                        {logs.data.map((entry) => (
                                            <tr key={entry.id} className="align-top">
                                                <td className="whitespace-nowrap px-4 py-3 text-[#62615F]">
                                                    {new Date(entry.created_at).toLocaleString('ru-RU', {
                                                        day: '2-digit',
                                                        month: '2-digit',
                                                        year: 'numeric',
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                    })}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-[#181818]">{entry.super_admin?.name ?? '—'}</p>
                                                    {entry.super_admin?.email && (
                                                        <p className="text-xs text-[#8E8A85]">{entry.super_admin.email}</p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-[#181818]">{ACTION_LABELS[entry.action] || entry.action}</p>
                                                    <p className="text-xs text-[#8E8A85]">{entry.action}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {entry.target_type && (
                                                        <>
                                                            <p className="text-[#181818]">{entry.target_type.replace('App\\Models\\', '')}</p>
                                                            {entry.target_id && (
                                                                <p
                                                                    className="text-xs text-[#8E8A85]"
                                                                    title={entry.target_id}
                                                                >
                                                                    {entry.target_id.slice(0, 8)}
                                                                </p>
                                                            )}
                                                        </>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-[#181818]">
                                                    {formatDiff(entry)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            <div className="mt-4 flex items-center justify-between text-sm">
                                <span className="text-[#8E8A85]">
                                    {logs.total} {logs.total === 1 ? 'запись' : logs.total < 5 ? 'записи' : 'записей'}
                                </span>
                                <div className="flex items-center gap-2">
                                    {logs.current_page > 1 && (
                                        <button
                                            onClick={() => router.get('/admin-root/audit', { ...filters, page: String(logs.current_page - 1) }, { preserveState: true })}
                                            className="rounded-lg border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm text-[#181818] hover:bg-[#F7F5F1]"
                                        >
                                            Назад
                                        </button>
                                    )}
                                    <span className="text-[#62615F]">
                                        {logs.current_page} / {logs.last_page}
                                    </span>
                                    {logs.current_page < logs.last_page && (
                                        <button
                                            onClick={() => router.get('/admin-root/audit', { ...filters, page: String(logs.current_page + 1) }, { preserveState: true })}
                                            className="rounded-lg border border-[#E7E4DF] bg-white px-3 py-1.5 text-sm text-[#181818] hover:bg-[#F7F5F1]"
                                        >
                                            Вперёд
                                        </button>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
