import { useState, useMemo, useCallback } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    UsersIcon,
    XMarkIcon,
    PhoneIcon,
    PencilIcon,
    ShieldCheckIcon,
    NoSymbolIcon,
    CalendarDaysIcon,
} from '@heroicons/react/24/outline';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerTitle, DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogAction, AlertDialogCancel,
} from '@/components/ui/alert-dialog';
import AdminLayout from '@/layouts/AdminLayout';
import { getInitials } from '@/lib/utils';
import { PhoneInput } from '@/components/PhoneInput';
import { formatPhone, stripPhoneMask } from '@/lib/phone';
import type { Client, Paginated, PageProps } from '@/types/app';

/* ═══════════════ Helpers ═══════════════ */

type FilterType = 'all' | 'active' | 'blocked';

const FILTER_TABS: { key: FilterType; label: string }[] = [
    { key: 'all', label: 'Все' },
    { key: 'active', label: 'Активные' },
    { key: 'blocked', label: 'Заблокированные' },
];

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    const months = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    const d = new Date(dateStr);
    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
}

function formatCurrency(value: number): string {
    return value.toLocaleString('ru-RU') + ' ₽';
}

/* ═══════════════ Main Clients Page ═══════════════ */

export default function ClientsPage() {
    const { clients: paginatedClients, auth } = usePage<PageProps & { clients: Paginated<Client> }>().props;
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<FilterType>('all');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [drawerMode, setDrawerMode] = useState<'view' | 'create' | 'edit'>('create');
    const [selectedClient, setSelectedClient] = useState<Client | null>(null);
    const [formName, setFormName] = useState('');
    const [formPhone, setFormPhone] = useState('');
    const [isProcessing, setIsProcessing] = useState(false);
    const [blockConfirmOpen, setBlockConfirmOpen] = useState(false);

    const initialClients = paginatedClients?.data ?? [];

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
        if (filter === 'active') result = result.filter((c) => !c.is_blocked);
        else if (filter === 'blocked') result = result.filter((c) => c.is_blocked);
        return result;
    }, [initialClients, search, filter]);

    const openCreate = useCallback(() => {
        setSelectedClient(null);
        setFormName('');
        setFormPhone('');
        setDrawerMode('create');
        setDrawerOpen(true);
    }, []);

    const openView = useCallback((client: Client) => {
        setSelectedClient(client);
        setDrawerMode('view');
        setDrawerOpen(true);
    }, []);

    const openEdit = useCallback(() => {
        if (!selectedClient) return;
        setFormName(selectedClient.name);
        setFormPhone(selectedClient.phone || '');
        setDrawerMode('edit');
    }, [selectedClient]);

    const closeDrawer = useCallback(() => {
        setDrawerOpen(false);
        setTimeout(() => {
            setSelectedClient(null);
            setDrawerMode('create');
            setFormName('');
            setFormPhone('');
        }, 200);
    }, []);

    function handleToggleBlock() {
        if (!selectedClient || isProcessing) return;
        setIsProcessing(true);
        router.post(`/admin/clients/${selectedClient.id}/toggle-block`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(selectedClient.is_blocked
                    ? `${selectedClient.name} разблокирован`
                    : `${selectedClient.name} заблокирован`);
                closeDrawer();
            },
            onError: (errors) => {
                toast.error(Object.values(errors)[0] || 'Не удалось изменить статус');
            },
            onFinish: () => setIsProcessing(false),
        });
    }

    function handleSubmit() {
        if (!formName.trim() || !formPhone.trim() || isProcessing) return;
        setIsProcessing(true);
        const phone = stripPhoneMask(formPhone);

        if (drawerMode === 'edit' && selectedClient) {
            router.put(`/admin/clients/${selectedClient.id}`, { name: formName, phone }, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Данные клиента обновлены');
                    closeDrawer();
                },
                onError: (errors) => {
                    toast.error(Object.values(errors)[0] || 'Не удалось обновить клиента');
                    setIsProcessing(false);
                },
            });
        } else {
            router.post('/admin/clients', { name: formName, phone }, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Клиент добавлен');
                    closeDrawer();
                },
                onError: (errors) => {
                    toast.error(Object.values(errors)[0] || 'Не удалось добавить клиента');
                    setIsProcessing(false);
                },
            });
        }
    }

    const isEmpty = initialClients.length === 0;
    const noResults = !isEmpty && clients.length === 0;

    return (
        <>
            <Head title="Клиенты — Вовремя" />

            <AdminLayout title="Клиенты" auth={auth}>
                {/* ─── Toolbar ─── */}
                <div className="mb-5 flex flex-wrap items-center gap-3">
                    <div className="relative max-w-[360px] flex-1">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 size-[18px] -translate-y-1/2 text-[var(--color-graphite)]" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Поиск по имени или телефону"
                            className="h-10 border-[var(--color-line)] bg-[var(--color-warm)] pl-9 pr-8 text-sm placeholder:text-[var(--color-graphite)]"
                        />
                        {search && (
                            <button
                                onClick={() => setSearch('')}
                                className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-[var(--color-graphite)] transition-colors hover:text-[var(--color-ink)]"
                            >
                                <XMarkIcon className="size-4" />
                            </button>
                        )}
                    </div>
                    <div className="flex rounded-[10px] bg-[var(--color-warm)] p-1">
                        {FILTER_TABS.map((tab) => (
                            <button
                                key={tab.key}
                                onClick={() => setFilter(tab.key)}
                                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                                    filter === tab.key
                                        ? 'bg-white text-[var(--color-ink)] shadow-sm dark:bg-zinc-700'
                                        : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    <div className="ml-auto">
                        <Button
                            onClick={openCreate}
                            className="h-10 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                        >
                            <PlusIcon className="size-4" />
                            Новый клиент
                        </Button>
                    </div>
                </div>

                {/* ─── Empty state: no clients at all ─── */}
                {isEmpty && (
                    <div className="flex flex-col items-center justify-center py-20 text-center">
                        <div className="flex size-14 items-center justify-center rounded-2xl bg-[var(--color-warm)]">
                            <UsersIcon className="size-7 text-[var(--color-graphite)]" />
                        </div>
                        <p className="mt-4 text-sm font-semibold text-[var(--color-ink)]">Клиентов пока нет</p>
                        <p className="mt-1 text-xs text-[var(--color-graphite)]">Добавьте первого клиента, чтобы начать работу</p>
                        <Button
                            onClick={openCreate}
                            className="mt-4 h-10 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                        >
                            <PlusIcon className="size-4" />
                            Добавить клиента
                        </Button>
                    </div>
                )}

                {/* ─── Empty state: no search results ─── */}
                {noResults && (
                    <div className="flex flex-col items-center justify-center py-20 text-center">
                        <div className="flex size-14 items-center justify-center rounded-2xl bg-[var(--color-warm)]">
                            <MagnifyingGlassIcon className="size-7 text-[var(--color-graphite)]" />
                        </div>
                        <p className="mt-4 text-sm font-semibold text-[var(--color-ink)]">Ничего не найдено</p>
                        <p className="mt-1 text-xs text-[var(--color-graphite)]">Попробуйте другой запрос или очистите поиск</p>
                        <button
                            onClick={() => setSearch('')}
                            className="mt-3 text-xs font-semibold text-[var(--color-orange)] hover:underline"
                        >
                            Очистить поиск
                        </button>
                    </div>
                )}

                {/* ─── Desktop table ─── */}
                {!isEmpty && !noResults && (
                    <>
                        {/* Desktop table */}
                        <div className="hidden overflow-hidden rounded-xl border border-[var(--color-line)] lg:block">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b border-[var(--color-line)] bg-[var(--color-warm)]">
                                        <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-[var(--color-graphite)]">Клиент</th>
                                        <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-[var(--color-graphite)]">Телефон</th>
                                        <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-[var(--color-graphite)]">Визитов</th>
                                        <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-[var(--color-graphite)]">Последний визит</th>
                                        <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-[var(--color-graphite)]">LTV</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {clients.map((client) => {
                                        const initials = getInitials(client.name);
                                        return (
                                            <tr
                                                key={client.id}
                                                onClick={() => openView(client)}
                                                className="cursor-pointer border-b border-[var(--color-line-soft)] transition-colors last:border-b-0 hover:bg-[var(--color-warm)]"
                                            >
                                                <td className="px-4 py-3.5">
                                                    <div className="flex items-center gap-3">
                                                        <Avatar className="size-9 shrink-0">
                                                            <AvatarImage src={client.avatar_url ?? undefined} alt={client.name} className="object-cover" />
                                                            <AvatarFallback className="bg-[var(--color-line)] text-xs font-bold text-[var(--color-ink)]">
                                                                {initials}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div className="min-w-0">
                                                            <p className="truncate text-sm font-semibold text-[var(--color-ink)]">{client.name}</p>
                                                            {client.is_blocked && (
                                                                <span className="text-[11px] font-medium text-red-500">Заблокирован</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    {client.phone ? (
                                                        <a
                                                            href={`tel:+${client.phone.replace(/\D/g, '')}`}
                                                            onClick={(e) => e.stopPropagation()}
                                                            className="font-mono text-sm text-[var(--color-graphite)] hover:text-[var(--color-orange)]"
                                                        >
                                                            {formatPhone(client.phone)}
                                                        </a>
                                                    ) : (
                                                        <span className="text-sm text-[var(--color-graphite)]/50">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3.5 text-sm text-[var(--color-graphite)]">
                                                    {client.completed_bookings ?? 0}
                                                </td>
                                                <td className="px-4 py-3.5 text-sm text-[var(--color-graphite)]">
                                                    {formatDate(client.last_visit ?? null)}
                                                </td>
                                                <td className="px-4 py-3.5 text-right text-sm font-semibold text-[var(--color-ink)]">
                                                    {formatCurrency(client.ltv ?? 0)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile list */}
                        <div className="flex flex-col gap-0.5 lg:hidden">
                            {clients.map((client) => {
                                const initials = getInitials(client.name);
                                return (
                                    <button
                                        key={client.id}
                                        onClick={() => openView(client)}
                                        className="flex items-center gap-3 rounded-xl px-3 py-3 text-left transition-colors hover:bg-[var(--color-warm)] active:bg-[var(--color-line-soft)]"
                                    >
                                        <Avatar className="size-10 shrink-0">
                                            <AvatarImage src={client.avatar_url ?? undefined} alt={client.name} className="object-cover" />
                                            <AvatarFallback className="bg-[var(--color-line)] text-sm font-bold text-[var(--color-ink)]">
                                                {initials}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-[var(--color-ink)]">{client.name}</p>
                                            <p className="mt-0.5 font-mono text-xs text-[var(--color-graphite)]">
                                                {client.phone ? formatPhone(client.phone) : '—'}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-xs text-[var(--color-graphite)]">{client.completed_bookings ?? 0} визитов</p>
                                            {client.is_blocked && (
                                                <span className="text-[11px] font-medium text-red-500">Заблокирован</span>
                                            )}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Pagination */}
                        {paginatedClients && paginatedClients.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between">
                                <p className="text-xs text-[var(--color-graphite)]">
                                    {paginatedClients.from}–{paginatedClients.to} из {paginatedClients.total}
                                </p>
                                <div className="flex gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={paginatedClients.current_page <= 1}
                                        onClick={() => router.get(`/admin/clients?page=${paginatedClients.current_page - 1}`)}
                                    >
                                        Назад
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={paginatedClients.current_page >= paginatedClients.last_page}
                                        onClick={() => router.get(`/admin/clients?page=${paginatedClients.current_page + 1}`)}
                                    >
                                        Далее
                                    </Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </AdminLayout>

            {/* ─── Client Drawer (view / create / edit) ─── */}
            <Drawer open={drawerOpen} onOpenChange={(open) => { if (!open) closeDrawer(); }}>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>
                            {drawerMode === 'create' && 'Новый клиент'}
                            {drawerMode === 'edit' && 'Редактировать клиента'}
                            {drawerMode === 'view' && 'Клиент'}
                        </DrawerTitle>
                    </DrawerHeader>

                    <DrawerBody>
                        {/* VIEW MODE */}
                        {drawerMode === 'view' && selectedClient && (
                            <div className="space-y-5">
                                <div className="flex items-center gap-3">
                                    <Avatar className="size-12 shrink-0">
                                        <AvatarImage src={selectedClient.avatar_url ?? undefined} alt={selectedClient.name} className="object-cover" />
                                        <AvatarFallback className="bg-[var(--color-line)] text-base font-bold text-[var(--color-ink)]">
                                            {getInitials(selectedClient.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <p className="text-base font-semibold text-[var(--color-ink)]">{selectedClient.name}</p>
                                        {selectedClient.is_blocked && (
                                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-red-500">
                                                <NoSymbolIcon className="size-3" />
                                                Заблокирован
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    {selectedClient.phone && (
                                        <div className="flex items-center gap-3">
                                            <PhoneIcon className="size-[18px] shrink-0 text-[var(--color-graphite)]" />
                                            <a
                                                href={`tel:+${selectedClient.phone.replace(/\D/g, '')}`}
                                                className="font-mono text-sm text-[var(--color-ink)] hover:text-[var(--color-orange)]"
                                            >
                                                {formatPhone(selectedClient.phone)}
                                            </a>
                                        </div>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-xl bg-[var(--color-warm)] p-3">
                                        <p className="text-[11px] text-[var(--color-graphite)]">Визитов</p>
                                        <p className="mt-0.5 text-lg font-bold text-[var(--color-ink)]">{selectedClient.completed_bookings ?? 0}</p>
                                    </div>
                                    <div className="rounded-xl bg-[var(--color-warm)] p-3">
                                        <p className="text-[11px] text-[var(--color-graphite)]">LTV</p>
                                        <p className="mt-0.5 text-lg font-bold text-[var(--color-ink)]">{formatCurrency(selectedClient.ltv ?? 0)}</p>
                                    </div>
                                    <div className="col-span-2 rounded-xl bg-[var(--color-warm)] p-3">
                                        <p className="text-[11px] text-[var(--color-graphite)]">Последний визит</p>
                                        <p className="mt-0.5 text-sm font-semibold text-[var(--color-ink)]">{formatDate(selectedClient.last_visit ?? null)}</p>
                                    </div>
                                </div>

                                <button
                                    onClick={() => router.get('/admin/calendar', { client_id: selectedClient.id })}
                                    className="flex w-full items-center gap-2 rounded-xl border border-[var(--color-line)] px-4 py-3 text-sm font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-warm)]"
                                >
                                    <CalendarDaysIcon className="size-5 text-[var(--color-graphite)]" />
                                    Записать на приём
                                </button>
                            </div>
                        )}

                        {/* CREATE / EDIT MODE */}
                        {(drawerMode === 'create' || drawerMode === 'edit') && (
                            <div className="space-y-4">
                                <div>
                                    <label className="mb-1.5 block text-[12px] font-semibold text-[var(--color-graphite)]">
                                        Имя
                                    </label>
                                    <Input
                                        value={formName}
                                        onChange={(e) => setFormName(e.target.value)}
                                        placeholder="Имя клиента"
                                        className="h-11 border-[var(--color-line)] bg-[var(--color-warm)]"
                                        autoFocus
                                    />
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-[12px] font-semibold text-[var(--color-graphite)]">
                                        Телефон
                                    </label>
                                    <PhoneInput
                                        value={formPhone}
                                        onChange={setFormPhone}
                                        placeholder="+7 (911) 123-45-67"
                                        className="h-11 border-[var(--color-line)] bg-[var(--color-warm)]"
                                    />
                                </div>
                            </div>
                        )}
                    </DrawerBody>

                    <DrawerFooter>
                        {drawerMode === 'view' && selectedClient && (
                            <div className="flex gap-2">
                                <Button
                                    onClick={openEdit}
                                    variant="outline"
                                    className="h-11 flex-1 gap-2 text-sm font-semibold"
                                >
                                    <PencilIcon className="size-4" />
                                    Редактировать
                                </Button>
                                <Button
                                    onClick={() => setBlockConfirmOpen(true)}
                                    variant="outline"
                                    className={`h-11 gap-2 text-sm font-semibold ${
                                        selectedClient.is_blocked
                                            ? 'border-emerald-200 text-emerald-600 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-400'
                                            : 'border-red-200 text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400'
                                    }`}
                                >
                                    {selectedClient.is_blocked ? (
                                        <>
                                            <ShieldCheckIcon className="size-4" />
                                            Разблокировать
                                        </>
                                    ) : (
                                        <>
                                            <NoSymbolIcon className="size-4" />
                                            Заблокировать
                                        </>
                                    )}
                                </Button>
                            </div>
                        )}

                        {(drawerMode === 'create' || drawerMode === 'edit') && (
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => { if (selectedClient) { setDrawerMode('view'); } else { closeDrawer(); } }}
                                    className="h-11 flex-1 text-sm font-semibold"
                                >
                                    Отмена
                                </Button>
                                <Button
                                    onClick={handleSubmit}
                                    disabled={!formName.trim() || !formPhone.trim() || isProcessing}
                                    className="h-11 flex-1 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                                >
                                    {drawerMode === 'edit' ? 'Сохранить' : 'Создать клиента'}
                                </Button>
                            </div>
                        )}
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>

            {/* ─── Block/Unblock confirmation ─── */}
            <AlertDialog open={blockConfirmOpen} onOpenChange={setBlockConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {selectedClient?.is_blocked ? 'Разблокировать клиента?' : 'Заблокировать клиента?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {selectedClient?.is_blocked
                                ? `${selectedClient?.name} снова сможет записываться на приём.`
                                : `${selectedClient?.name} не сможет записываться на приём. Активные записи будут отменены.`
                            }
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Отмена</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleToggleBlock}
                            className={selectedClient?.is_blocked
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                : 'bg-red-600 text-white hover:bg-red-700'
                            }
                        >
                            {selectedClient?.is_blocked ? 'Разблокировать' : 'Заблокировать'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
