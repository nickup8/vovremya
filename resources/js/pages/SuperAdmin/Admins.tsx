import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import SuperAdminNav from '@/components/super-admin/SuperAdminNav';

interface PlatformUser {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
}

interface AdminAccess {
    id: string;
    user_id: string;
    user: PlatformUser;
    permissions: string[];
    is_active: boolean;
    granted_by: string | null;
    granted_by_user: PlatformUser | null;
    created_at: string;
    updated_at: string;
}

interface PermissionOption {
    value: string;
    label: string;
}

interface AdminsProps {
    roots: PlatformUser[];
    accesses: AdminAccess[];
    allPermissions: PermissionOption[];
    flash?: { success?: string; error?: string };
    platformAdmin: { isRoot: boolean; permissions: string[] };
}

const PERMISSION_LABELS: Record<string, string> = {
    'dashboard.view': 'Обзор',
    'users.view': 'Пользователи',
    'users.block': 'Блокировка пользователей',
    'subscriptions.extend': 'Продление Pro',
    'impersonation.use': 'Вход от имени пользователя',
    'plans.view': 'Просмотр тарифов',
    'plans.update': 'Изменение тарифов',
    'audit.view': 'Журнал действий',
    'platform_admins.manage': 'Управление администраторами',
};

export default function Admins() {
    const { roots, accesses, allPermissions, flash, platformAdmin } = usePage().props as AdminsProps;
    const actorId = (usePage().props as Record<string, unknown>).auth as { user?: { id?: string } } | undefined;
    const currentUserId = actorId?.user?.id;

    const [showAdd, setShowAdd] = useState(false);
    const [editAccess, setEditAccess] = useState<AdminAccess | null>(null);
    const [confirmDeactivate, setConfirmDeactivate] = useState<AdminAccess | null>(null);

    return (
        <>
            <Head title="Администраторы — ИРСИ" />

            <div className="min-h-screen bg-[#F7F5F1]">
                <div className="mx-auto max-w-3xl px-4 py-10">
                    <h1 className="text-2xl font-bold tracking-tight text-[#181818]">Администраторы</h1>
                    <p className="mt-1.5 text-sm text-[#62615F]">Доступ к управлению платформой ИРСИ</p>

                    <SuperAdminNav current="admins" />

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

                    {/* ROOT section */}
                    <h2 className="mt-8 text-sm font-semibold text-[#181818]">Владелец платформы</h2>
                    <div className="mt-3 space-y-3">
                        {roots.map((root) => (
                            <div key={root.id} className="rounded-2xl border border-[#E7E4DF] bg-white p-5">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="font-medium text-[#181818]">{root.name}</p>
                                        <p className="text-xs text-[#8E8A85]">ROOT · Полный доступ</p>
                                    </div>
                                    <span className="rounded-lg bg-[#FF5A1F]/10 px-2.5 py-1 text-xs font-semibold text-[#FF5A1F]">ROOT</span>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Limited admins section */}
                    <div className="mt-8 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-[#181818]">Ограниченные администраторы</h2>
                        <button
                            onClick={() => setShowAdd(true)}
                            className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14]"
                        >
                            Добавить администратора
                        </button>
                    </div>

                    {accesses.length === 0 ? (
                        <div className="mt-6 text-center">
                            <p className="text-sm text-[#8E8A85]">Пока нет ограниченных администраторов.</p>
                        </div>
                    ) : (
                        <div className="mt-3 space-y-3">
                            {accesses.map((access) => (
                                <div key={access.id} className={`rounded-2xl border border-[#E7E4DF] bg-white p-5 ${!access.is_active ? 'opacity-60' : ''}`}>
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <p className="font-medium text-[#181818]">{access.user.name}</p>
                                                <span className={`rounded-lg px-2 py-0.5 text-xs font-semibold ${
                                                    access.is_active ? 'bg-[#F7F5F1] text-[#62615F]' : 'bg-[#F7F5F1] text-[#C44351]'
                                                }`}>
                                                    {access.is_active ? 'Активен' : 'Отключён'}
                                                </span>
                                            </div>
                                            {access.user.phone && <p className="text-xs text-[#8E8A85]">{access.user.phone}</p>}
                                            {access.user.email && <p className="text-xs text-[#8E8A85]">{access.user.email}</p>}
                                            <div className="mt-2 flex flex-wrap gap-1">
                                                {access.permissions.map((p) => (
                                                    <span key={p} className="rounded-lg bg-[#F7F5F1] px-2 py-0.5 text-xs text-[#62615F]">
                                                        {PERMISSION_LABELS[p] || p}
                                                    </span>
                                                ))}
                                            </div>
                                            {access.granted_by_user && (
                                                <p className="mt-1.5 text-xs text-[#8E8A85]">
                                                    Назначил: {access.granted_by_user.name}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 flex-col gap-1">
                                            {currentUserId !== access.user_id && (
                                                <>
                                                    <button
                                                        onClick={() => setEditAccess(access)}
                                                        className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                    >
                                                        Настроить права
                                                    </button>
                                                    {access.is_active ? (
                                                        <button
                                                            onClick={() => setConfirmDeactivate(access)}
                                                            className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#C44351] hover:bg-red-50"
                                                        >
                                                            Отключить доступ
                                                        </button>
                                                    ) : (
                                                        <button
                                                            onClick={() => router.patch(`/admin-root/admins/${access.id}/active`)}
                                                            className="rounded-lg px-2.5 py-1 text-xs font-medium text-[#62615F] hover:bg-[#F7F5F1]"
                                                        >
                                                            Включить доступ
                                                        </button>
                                                    )}
                                                </>
                                            )}
                                            {currentUserId === access.user_id && (
                                                <p className="text-xs text-[#8E8A85]">Это вы</p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Add admin modal */}
            {showAdd && (
                <AddAdminModal
                    allPermissions={allPermissions}
                    onClose={() => setShowAdd(false)}
                />
            )}

            {/* Edit permissions modal */}
            {editAccess && (
                <EditPermissionsModal
                    access={editAccess}
                    allPermissions={allPermissions}
                    onClose={() => setEditAccess(null)}
                />
            )}

            {/* Deactivate confirmation */}
            {confirmDeactivate && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={() => setConfirmDeactivate(null)}>
                    <div className="w-full max-w-sm rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h2 className="text-lg font-bold tracking-tight text-[#181818]">Отключить доступ администратора?</h2>
                        <p className="mt-1 text-sm text-[#62615F]">
                            {confirmDeactivate.user.name} сразу потеряет доступ к административной части ИРСИ.
                        </p>
                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                onClick={() => setConfirmDeactivate(null)}
                                className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]"
                            >
                                Отмена
                            </button>
                            <button
                                onClick={() => {
                                    router.patch(`/admin-root/admins/${confirmDeactivate.id}/active`);
                                    setConfirmDeactivate(null);
                                }}
                                className="rounded-xl bg-[#C44351] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#A83843]"
                            >
                                Отключить
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function AddAdminModal({ allPermissions, onClose }: { allPermissions: PermissionOption[]; onClose: () => void }) {
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<{ id: string; name: string; email: string | null; phone: string | null; already_admin: boolean }[]>([]);
    const [selectedUser, setSelectedUser] = useState<{ id: string; name: string } | null>(null);
    const [selectedPerms, setSelectedPerms] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    function handleSearch(q: string) {
        setSearchQuery(q);
        if (q.length < 2) { setSearchResults([]); return; }
        fetch(`/admin-root/admins/users/search?q=${encodeURIComponent(q)}`)
            .then((r) => r.json())
            .then(setSearchResults)
            .catch(() => setSearchResults([]));
    }

    function handleSubmit() {
        if (!selectedUser) { setError('Выберите пользователя'); return; }
        if (selectedPerms.length === 0) { setError('Выберите хотя бы одно право'); return; }
        setLoading(true);
        setError('');
        router.post('/admin-root/admins', {
            user_id: selectedUser.id,
            permissions: selectedPerms,
        }, {
            onSuccess: () => onClose(),
            onError: (errs) => setError(Object.values(errs).flat().join(', ') || 'Ошибка'),
            onFinish: () => setLoading(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                <h2 className="text-lg font-bold tracking-tight text-[#181818]">Добавить администратора</h2>

                {/* Step 1: Search user */}
                <label className="mt-4 block text-sm font-semibold text-[#181818]">Найти пользователя</label>
                <input
                    type="text"
                    placeholder="Имя, телефон или email"
                    value={selectedUser ? selectedUser.name : searchQuery}
                    onChange={(e) => { setSelectedUser(null); handleSearch(e.target.value); }}
                    className="mt-1.5 w-full rounded-xl border border-[#E7E4DF] bg-white px-3.5 py-2.5 text-sm text-[#181818] outline-none focus:border-[#FF5A1F] focus:ring-1 focus:ring-[#FF5A1F]"
                />
                {!selectedUser && searchResults.length > 0 && (
                    <div className="mt-1 max-h-40 overflow-y-auto rounded-xl border border-[#E7E4DF] bg-white">
                        {searchResults.map((u) => (
                            <button
                                key={u.id}
                                onClick={() => { if (!u.already_admin) { setSelectedUser(u); setSearchQuery(''); setSearchResults([]); } }}
                                className={`flex w-full items-center justify-between px-3.5 py-2.5 text-left text-sm hover:bg-[#F7F5F1] ${u.already_admin ? 'cursor-not-allowed opacity-50' : ''}`}
                            >
                                <div>
                                    <p className="font-medium text-[#181818]">{u.name}</p>
                                    <p className="text-xs text-[#8E8A85]">{u.phone || u.email || '—'}</p>
                                </div>
                                {u.already_admin && <span className="text-xs text-[#8E8A85]">Уже назначен</span>}
                            </button>
                        ))}
                    </div>
                )}

                {/* Step 2: Permissions */}
                <label className="mt-4 block text-sm font-semibold text-[#181818]">Права доступа</label>
                <div className="mt-2 space-y-2">
                    {allPermissions.map((p) => (
                        <label key={p.value} className="flex items-center gap-2 text-sm text-[#181818]">
                            <input
                                type="checkbox"
                                checked={selectedPerms.includes(p.value)}
                                onChange={(e) => {
                                    if (e.target.checked) {
                                        setSelectedPerms([...selectedPerms, p.value]);
                                    } else {
                                        setSelectedPerms(selectedPerms.filter((v) => v !== p.value));
                                    }
                                }}
                                className="rounded border-[#E7E4DF]"
                            />
                            {p.label}
                        </label>
                    ))}
                </div>

                {error && <p className="mt-3 text-xs text-[#C44351]">{error}</p>}

                <div className="mt-5 flex justify-end gap-3">
                    <button onClick={onClose} className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]">Отмена</button>
                    <button
                        onClick={handleSubmit}
                        disabled={loading}
                        className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                    >
                        {loading ? 'Сохранение…' : 'Назначить'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function EditPermissionsModal({ access, allPermissions, onClose }: { access: AdminAccess; allPermissions: PermissionOption[]; onClose: () => void }) {
    const [selectedPerms, setSelectedPerms] = useState<string[]>(access.permissions);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    function handleSubmit() {
        if (selectedPerms.length === 0) { setError('Выберите хотя бы одно право'); return; }
        setLoading(true);
        setError('');
        router.put(`/admin-root/admins/${access.id}`, {
            permissions: selectedPerms,
        }, {
            onSuccess: () => onClose(),
            onError: (errs) => setError(Object.values(errs).flat().join(', ') || 'Ошибка'),
            onFinish: () => setLoading(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl border border-[#E7E4DF] bg-white p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                <h2 className="text-lg font-bold tracking-tight text-[#181818]">Настроить права</h2>
                <p className="mt-1 text-sm text-[#62615F]">{access.user.name}</p>

                <div className="mt-4 space-y-2">
                    {allPermissions.map((p) => (
                        <label key={p.value} className="flex items-center gap-2 text-sm text-[#181818]">
                            <input
                                type="checkbox"
                                checked={selectedPerms.includes(p.value)}
                                onChange={(e) => {
                                    if (e.target.checked) {
                                        setSelectedPerms([...selectedPerms, p.value]);
                                    } else {
                                        setSelectedPerms(selectedPerms.filter((v) => v !== p.value));
                                    }
                                }}
                                className="rounded border-[#E7E4DF]"
                            />
                            {p.label}
                        </label>
                    ))}
                </div>

                {error && <p className="mt-3 text-xs text-[#C44351]">{error}</p>}

                <div className="mt-5 flex justify-end gap-3">
                    <button onClick={onClose} className="rounded-xl border border-[#E7E4DF] bg-white px-4 py-2 text-sm font-semibold text-[#181818] hover:bg-[#F7F5F1]">Отмена</button>
                    <button
                        onClick={handleSubmit}
                        disabled={loading}
                        className="rounded-xl bg-[#FF5A1F] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#E94D14] disabled:opacity-50"
                    >
                        {loading ? 'Сохранение…' : 'Сохранить'}
                    </button>
                </div>
            </div>
        </div>
    );
}
