import { useState, useCallback, useRef, useEffect } from 'react';
import { Head, router, usePage, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    UsersIcon,
    XMarkIcon,
    PhoneIcon,
    PencilSquareIcon,
    NoSymbolIcon,
    ShieldCheckIcon,
    CalendarDaysIcon,
    EllipsisHorizontalIcon,
    ChevronDownIcon,
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
type SortType = 'last_visit_desc' | 'name_asc';

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

function getPageNumbers(current: number, last: number): (number | 'ellipsis')[] {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const pages: (number | 'ellipsis')[] = [1];
    if (current > 3) pages.push('ellipsis');
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) {
        pages.push(i);
    }
    if (current < last - 2) pages.push('ellipsis');
    pages.push(last);
    return pages;
}

/* ═══════════════ Client Card ═══════════════ */

function ClientCard({
    client,
    onOpen,
    onBook,
    onEdit,
    onToggleBlock,
}: {
    client: Client;
    onOpen: () => void;
    onBook: (e: React.MouseEvent) => void;
    onEdit: (e: React.MouseEvent) => void;
    onToggleBlock: (e: React.MouseEvent) => void;
}) {
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const initials = getInitials(client.name);

    return (
        <article
            className="group relative min-w-0 cursor-pointer overflow-visible rounded-[14px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-4 transition-colors hover:border-[var(--color-graphite)]/40"
            onClick={onOpen}
            tabIndex={0}
            onKeyDown={(e) => { if (e.key === 'Enter' && !(e.target as HTMLElement).closest('button')) onOpen(); }}
        >
            {/* Header */}
            <div className="grid grid-cols-[42px_minmax(0,1fr)_auto] items-center gap-[11px]">
                <Avatar className="size-[42px] shrink-0">
                    <AvatarImage src={client.avatar_url ?? undefined} alt={client.name} className="object-cover" />
                    <AvatarFallback className="bg-[var(--color-avatar)] text-xs font-bold text-[var(--color-ink)]">
                        {initials}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-[var(--color-ink)]">{client.name}</p>
                    {client.phone && (
                        <a
                            href={`tel:+${client.phone.replace(/\D/g, '')}`}
                            onClick={(e) => e.stopPropagation()}
                            className="mt-0.5 flex items-center gap-1 font-mono text-[11.5px] text-[var(--color-graphite)] hover:text-[var(--color-orange)]"
                        >
                            <PhoneIcon className="size-[13px] shrink-0" />
                            {formatPhone(client.phone)}
                        </a>
                    )}
                </div>
                {client.is_blocked && (
                    <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[var(--color-red-bg)] px-2 py-0.5 text-[10.5px] font-bold text-[var(--color-red)]">
                        <span className="size-1.5 rounded-full bg-[var(--color-red)]" />
                        Заблокирован
                    </span>
                )}
            </div>

            {/* Stats */}
            <div className="my-4 grid grid-cols-3 gap-0 border-y border-[var(--color-line-soft)] py-3">
                <div className="border-r border-[var(--color-line-soft)] px-3 first:pl-0 last:border-r-0 last:pr-0">
                    <p className="text-[10.5px] text-[var(--color-graphite)]">Визиты</p>
                    <p className="mt-0.5 text-[13px] font-bold tabular-nums text-[var(--color-ink)]">{client.completed_bookings ?? 0}</p>
                </div>
                <div className="border-r border-[var(--color-line-soft)] px-3 first:pl-0 last:border-r-0 last:pr-0">
                    <p className="text-[10.5px] text-[var(--color-graphite)]">LTV</p>
                    <p className="mt-0.5 text-[13px] font-bold tabular-nums text-[var(--color-ink)]">{formatCurrency(client.ltv ?? 0)}</p>
                </div>
                <div className="border-r border-[var(--color-line-soft)] px-3 first:pl-0 last:border-r-0 last:pr-0">
                    <p className="text-[10.5px] text-[var(--color-graphite)]">Последний визит</p>
                    <p className="mt-0.5 truncate text-[13px] font-bold tabular-nums text-[var(--color-ink)]">{formatDate(client.last_visit ?? null)}</p>
                </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-1.5">
                <button
                    onClick={onBook}
                    className="inline-flex h-[34px] items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-[var(--color-orange)] transition-colors hover:bg-[var(--color-orange-100)]"
                >
                    <CalendarDaysIcon className="size-4" />
                    Записать
                </button>
                <div className="relative" ref={menuRef}>
                    <button
                        onClick={(e) => { e.stopPropagation(); setMenuOpen(!menuOpen); }}
                        className={`inline-flex h-[34px] w-[34px] items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] ${menuOpen ? 'bg-[var(--color-surface-hover)]' : ''}`}
                        aria-label="Действия с клиентом"
                        aria-expanded={menuOpen}
                    >
                        <EllipsisHorizontalIcon className="size-4" />
                    </button>
                    {menuOpen && (
                        <>
                            <div className="fixed inset-0 z-30" onClick={(e) => { e.stopPropagation(); setMenuOpen(false); }} />
                            <div
                                className="absolute bottom-[40px] right-0 z-40 w-[190px] rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-1.5 shadow-[0_8px_24px_rgba(0,0,0,0.16)]"
                                onClick={(e) => e.stopPropagation()}
                                role="menu"
                            >
                                <button
                                    onClick={(e) => { setMenuOpen(false); onEdit(e); }}
                                    className="flex h-[38px] w-full items-center gap-2.5 rounded-lg px-2.5 text-[12.5px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)]"
                                    role="menuitem"
                                >
                                    <PencilSquareIcon className="size-[17px] text-[var(--color-graphite)]" />
                                    Редактировать
                                </button>
                                <button
                                    onClick={(e) => { setMenuOpen(false); onToggleBlock(e); }}
                                    className="flex h-[38px] w-full items-center gap-2.5 rounded-lg px-2.5 text-[12.5px] font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)]"
                                    role="menuitem"
                                >
                                    {client.is_blocked ? (
                                        <>
                                            <ShieldCheckIcon className="size-[17px] text-[var(--color-graphite)]" />
                                            Разблокировать
                                        </>
                                    ) : (
                                        <>
                                            <NoSymbolIcon className="size-[17px] text-[var(--color-graphite)]" />
                                            Заблокировать
                                        </>
                                    )}
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </article>
    );
}

/* ═══════════════ Main Clients Page ═══════════════ */

export default function ClientsPage() {
    const { clients: paginatedClients, auth } = usePage<PageProps & { clients: Paginated<Client> }>().props;

    const urlParams = new URLSearchParams(window.location.search);
    const [search, setSearch] = useState(() => urlParams.get('search') ?? '');
    const [filter, setFilter] = useState<FilterType>(() => (urlParams.get('filter') as FilterType) ?? 'all');
    const [sort, setSort] = useState<SortType>(() => (urlParams.get('sort') as SortType) ?? 'last_visit_desc');

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [drawerMode, setDrawerMode] = useState<'view' | 'create' | 'edit'>('create');
    const [selectedClient, setSelectedClient] = useState<Client | null>(null);
    const [formName, setFormName] = useState('');
    const [formPhone, setFormPhone] = useState('');
    const [formNotes, setFormNotes] = useState('');
    const [isProcessing, setIsProcessing] = useState(false);
    const [blockConfirmOpen, setBlockConfirmOpen] = useState(false);
    const [blockTarget, setBlockTarget] = useState<Client | null>(null);

    const clients = paginatedClients?.data ?? [];

    const queryParams = useCallback((overrides: Record<string, unknown> = {}) => ({
        search, filter, sort, ...overrides,
    }), [search, filter, sort]);

    // Debounced search → server
    useEffect(() => {
        const timer = setTimeout(() => {
            router.get('/admin/clients', { search, filter, sort }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => clearTimeout(timer);
    }, [search]);

    const openCreate = useCallback(() => {
        setSelectedClient(null);
        setFormName('');
        setFormPhone('');
        setFormNotes('');
        setDrawerMode('create');
        setDrawerOpen(true);
    }, []);

    const openView = useCallback((client: Client) => {
        setSelectedClient(client);
        setDrawerMode('view');
        setDrawerOpen(true);
    }, []);

    const openEdit = useCallback((client: Client) => {
        setSelectedClient(client);
        setFormName(client.name);
        setFormPhone(client.phone || '');
        setFormNotes(client.notes || '');
        setDrawerMode('edit');
        setDrawerOpen(true);
    }, []);

    const closeDrawer = useCallback(() => {
        setDrawerOpen(false);
        setTimeout(() => {
            setSelectedClient(null);
            setDrawerMode('create');
            setFormName('');
            setFormPhone('');
            setFormNotes('');
        }, 200);
    }, []);

    function handleBlockClick(client: Client) {
        setBlockTarget(client);
        setBlockConfirmOpen(true);
    }

    function handleToggleBlock() {
        if (!blockTarget || isProcessing) return;
        setIsProcessing(true);
        router.post(`/admin/clients/${blockTarget.id}/toggle-block`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(blockTarget.is_blocked
                    ? `${blockTarget.name} разблокирован`
                    : `${blockTarget.name} заблокирован`);
                setBlockConfirmOpen(false);
                setBlockTarget(null);
                if (selectedClient?.id === blockTarget.id) closeDrawer();
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
        const notes = formNotes.trim() || undefined;

        if (drawerMode === 'edit' && selectedClient) {
            router.put(`/admin/clients/${selectedClient.id}`, { name: formName, phone, notes }, {
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
            router.post('/admin/clients', { name: formName, phone, notes }, {
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

    const totalCount = paginatedClients?.total ?? 0;
    const hasActiveFilters = search.trim() !== '' || filter !== 'all';
    const isEmpty = totalCount === 0 && !hasActiveFilters;
    const noResults = totalCount === 0 && hasActiveFilters;
    const countLabel = `${totalCount} ${totalCount === 1 ? 'клиент' : totalCount % 10 >= 2 && totalCount % 10 <= 4 && (totalCount % 100 < 10 || totalCount % 100 >= 20) ? 'клиента' : 'клиентов'}`;

    return (
        <>
            <Head title="Клиенты — Вовремя" />

            <AdminLayout
                title="Клиенты"
                auth={auth}
                hideNewAppointment
                fullBleed
                headerActions={
                    <Button
                        onClick={openCreate}
                        className="h-10 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                    >
                        <PlusIcon className="size-4" />
                        <span className="hidden lg:inline">Добавить клиента</span>
                    </Button>
                }
            >
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                {/* ─── Toolbar ─── */}
                <div className="mb-3 flex min-h-[64px] flex-col gap-3 rounded-[14px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-[10px_12px] md:flex-row md:items-center">
                    <div className="relative min-w-0 flex-1">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 size-5 -translate-y-1/2 text-[var(--color-graphite)]" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Поиск по имени или телефону"
                            className="h-[42px] border-[var(--color-line)] bg-[var(--color-surface)] pl-[42px] pr-8 text-[13px] placeholder:text-[var(--color-graphite)]"
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
                    <div className="flex h-10 shrink-0 gap-0.5 rounded-xl bg-[var(--color-warm)] p-[3px]">
                        {FILTER_TABS.map((tab) => (
                            <button
                                key={tab.key}
                                onClick={() => {
                                    setFilter(tab.key);
                                    router.get('/admin/clients', queryParams({ filter: tab.key }), { preserveState: true, preserveScroll: true });
                                }}
                                className={`flex-1 cursor-pointer rounded-[9px] px-3 text-[13px] font-semibold transition-colors md:flex-none ${
                                    filter === tab.key
                                        ? 'bg-[var(--color-surface-elevated)] text-[var(--color-ink)] shadow-sm'
                                        : 'text-[var(--color-graphite)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-ink)]'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ─── Meta row ─── */}
                {!isEmpty && !noResults && (
                    <div className="flex items-center justify-between gap-5 px-1 pb-2 pt-3">
                        <p className="text-xs tabular-nums text-[var(--color-graphite)]">{countLabel}</p>
                        <button
                            onClick={() => {
                                const next: SortType = sort === 'last_visit_desc' ? 'name_asc' : 'last_visit_desc';
                                setSort(next);
                                router.get('/admin/clients', queryParams({ sort: next }), { preserveState: true, preserveScroll: true });
                            }}
                            className="h-[34px] rounded-lg px-2 text-xs font-semibold text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)]"
                        >
                            {sort === 'last_visit_desc' ? 'По последнему визиту ↓' : 'По имени А–Я'}
                        </button>
                    </div>
                )}

                {/* ─── Empty state: no clients at all ─── */}
                {isEmpty && (
                    <div className="mx-auto mt-8 flex min-h-[220px] max-w-[360px] flex-col items-center justify-center rounded-[16px] border border-dashed border-[var(--color-line)] bg-[var(--color-surface)]/70 px-6 text-center">
                        <div className="mb-3 flex size-12 items-center justify-center rounded-[14px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)]">
                            <UsersIcon className="size-[22px] text-[var(--color-graphite)]" />
                        </div>
                        <p className="text-[15px] font-semibold text-[var(--color-ink)]">Клиентов пока нет</p>
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

                {/* ─── Empty state: no search/filter results ─── */}
                {noResults && (
                    <div className="mx-auto mt-8 flex min-h-[220px] max-w-[360px] flex-col items-center justify-center rounded-[16px] border border-dashed border-[var(--color-line)] bg-[var(--color-surface)]/70 px-6 text-center">
                        <div className="mb-3 flex size-12 items-center justify-center rounded-[14px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)]">
                            <MagnifyingGlassIcon className="size-[22px] text-[var(--color-graphite)]" />
                        </div>
                        <p className="text-[15px] font-semibold text-[var(--color-ink)]">Ничего не найдено</p>
                        <p className="mt-1 text-xs text-[var(--color-graphite)]">Измените запрос или фильтр.</p>
                        <button
                            onClick={() => { setSearch(''); setFilter('all'); router.get('/admin/clients', {}, { preserveState: true }); }}
                            className="mt-3 text-xs font-semibold text-[var(--color-orange)] hover:underline"
                        >
                            Очистить фильтры
                        </button>
                    </div>
                )}

                {/* ─── Client cards grid ─── */}
                {!isEmpty && !noResults && (
                    <div className="grid grid-cols-1 gap-3 min-[1021px]:grid-cols-2 min-[1501px]:grid-cols-3">
                        {clients.map((client) => (
                            <ClientCard
                                key={client.id}
                                client={client}
                                onOpen={() => openView(client)}
                                onBook={(e) => { e.stopPropagation(); router.get('/admin/calendar', { client_id: client.id }); }}
                                onEdit={(e) => { e.stopPropagation(); openEdit(client); }}
                                onToggleBlock={(e) => { e.stopPropagation(); handleBlockClick(client); }}
                            />
                        ))}
                    </div>
                )}

                {/* ─── Pagination ─── */}
                {!isEmpty && !noResults && paginatedClients && paginatedClients.last_page > 1 && (
                    <div className="mt-[18px] flex min-h-[56px] flex-wrap items-center justify-between gap-4 border-t border-[var(--color-line-soft)] pt-3.5">
                        <p className="text-xs tabular-nums text-[var(--color-graphite)]">
                            {paginatedClients.from}–{paginatedClients.to} из {paginatedClients.total}
                        </p>
                        <div className="flex items-center gap-1">
                            <button
                                className="flex h-[34px] min-w-[34px] items-center justify-center rounded-lg px-2 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:opacity-40 disabled:hover:bg-transparent"
                                disabled={paginatedClients.current_page <= 1}
                                onClick={() => router.get('/admin/clients', queryParams({ page: paginatedClients.current_page - 1 }), { preserveState: true, preserveScroll: true })}
                            >
                                <ChevronDownIcon className="size-4 rotate-90" />
                            </button>
                            {getPageNumbers(paginatedClients.current_page, paginatedClients.last_page).map((p, i) =>
                                p === 'ellipsis' ? (
                                    <span key={`e${i}`} className="flex h-[34px] min-w-[34px] items-center justify-center text-xs text-[var(--color-graphite)]">…</span>
                                ) : (
                                    <button
                                        key={p}
                                        onClick={() => router.get('/admin/clients', queryParams({ page: p }), { preserveState: true, preserveScroll: true })}
                                        className={`flex h-[34px] min-w-[34px] items-center justify-center rounded-lg px-2 text-[13px] font-semibold transition-colors ${
                                            p === paginatedClients.current_page
                                                ? 'bg-[var(--color-orange-100)] text-[var(--color-orange)]'
                                                : 'text-[var(--color-graphite)] hover:bg-[var(--color-surface-hover)]'
                                        }`}
                                    >
                                        {p}
                                    </button>
                                ),
                            )}
                            <button
                                className="flex h-[34px] min-w-[34px] items-center justify-center rounded-lg px-2 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:opacity-40 disabled:hover:bg-transparent"
                                disabled={paginatedClients.current_page >= paginatedClients.last_page}
                                onClick={() => router.get('/admin/clients', queryParams({ page: paginatedClients.current_page + 1 }), { preserveState: true, preserveScroll: true })}
                            >
                                <ChevronDownIcon className="size-4 -rotate-90" />
                            </button>
                        </div>
                    </div>
                )}
                </div>
            </AdminLayout>

            {/* ─── Client Detail Drawer ─── */}
            <Drawer open={drawerOpen && drawerMode === 'view'} onOpenChange={(open) => { if (!open) closeDrawer(); }}>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>Клиент</DrawerTitle>
                    </DrawerHeader>
                    <DrawerBody>
                        {selectedClient && (
                            <div className="space-y-5">
                                {/* Identity */}
                                <div className="flex items-center gap-3 border-b border-[var(--color-line-soft)] pb-[18px]">
                                    <Avatar className="size-12 shrink-0">
                                        <AvatarImage src={selectedClient.avatar_url ?? undefined} alt={selectedClient.name} className="object-cover" />
                                        <AvatarFallback className="bg-[var(--color-avatar)] text-base font-bold text-[var(--color-ink)]">
                                            {getInitials(selectedClient.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <p className="text-[15px] font-bold text-[var(--color-ink)]">{selectedClient.name}</p>
                                        {selectedClient.phone && (
                                            <a
                                                href={`tel:+${selectedClient.phone.replace(/\D/g, '')}`}
                                                className="mt-0.5 flex items-center gap-1 text-xs text-[var(--color-graphite)] hover:text-[var(--color-orange)]"
                                            >
                                                <PhoneIcon className="size-3" />
                                                {formatPhone(selectedClient.phone)}
                                            </a>
                                        )}
                                    </div>
                                </div>

                                {/* Section: Клиент */}
                                <div>
                                    <p className="mb-2 text-[11px] font-bold uppercase tracking-[.07em] text-[var(--color-graphite)]">Клиент</p>
                                    <div className="divide-y divide-[var(--color-line-soft)]">
                                        <div className="grid min-h-[44px] grid-cols-[125px_minmax(0,1fr)] items-center text-[13px]">
                                            <dt className="text-[var(--color-graphite)]">Статус</dt>
                                            <dd className="font-semibold">
                                                {selectedClient.is_blocked ? (
                                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-red-bg)] px-2 py-0.5 text-[10.5px] font-bold text-[var(--color-red)]">
                                                        <span className="size-1.5 rounded-full bg-[var(--color-red)]" />
                                                        Заблокирован
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-green-bg)] px-2 py-0.5 text-[10.5px] font-bold text-[var(--color-green)]">
                                                        <span className="size-1.5 rounded-full bg-[var(--color-green)]" />
                                                        Активен
                                                    </span>
                                                )}
                                            </dd>
                                        </div>
                                        <div className="grid min-h-[44px] grid-cols-[125px_minmax(0,1fr)] items-center text-[13px]">
                                            <dt className="text-[var(--color-graphite)]">Визиты</dt>
                                            <dd className="font-semibold">{selectedClient.completed_bookings ?? 0}</dd>
                                        </div>
                                        <div className="grid min-h-[44px] grid-cols-[125px_minmax(0,1fr)] items-center text-[13px]">
                                            <dt className="text-[var(--color-graphite)]">LTV</dt>
                                            <dd className="font-semibold">{formatCurrency(selectedClient.ltv ?? 0)}</dd>
                                        </div>
                                        <div className="grid min-h-[44px] grid-cols-[125px_minmax(0,1fr)] items-center text-[13px]">
                                            <dt className="text-[var(--color-graphite)]">Последний визит</dt>
                                            <dd className="font-semibold">{formatDate(selectedClient.last_visit ?? null)}</dd>
                                        </div>
                                    </div>
                                </div>

                                {/* Section: Заметка */}
                                <div>
                                    <p className="mb-2 text-[11px] font-bold uppercase tracking-[.07em] text-[var(--color-graphite)]">Заметка</p>
                                    <div className="rounded-[10px] bg-[var(--color-warm)] p-3 text-[12.5px] leading-[19px] text-[var(--color-graphite)]">
                                        {selectedClient.notes || 'Нет заметки'}
                                    </div>
                                </div>
                            </div>
                        )}
                    </DrawerBody>
                    <DrawerFooter>
                        {selectedClient && (
                            <Button
                                onClick={() => router.get('/admin/calendar', { client_id: selectedClient.id })}
                                className="h-11 flex-1 gap-2 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                            >
                                <CalendarDaysIcon className="size-[18px]" />
                                Создать запись
                            </Button>
                        )}
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>

            {/* ─── Create / Edit Drawer ─── */}
            <Drawer open={drawerOpen && (drawerMode === 'create' || drawerMode === 'edit')} onOpenChange={(open) => { if (!open) closeDrawer(); }}>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>
                            {drawerMode === 'edit' ? 'Редактировать клиента' : 'Новый клиент'}
                        </DrawerTitle>
                    </DrawerHeader>
                    <DrawerBody>
                        <div className="space-y-4">
                            <div className="grid gap-[7px]">
                                <label className="text-[12px] font-semibold text-[var(--color-graphite)]">Имя</label>
                                <Input
                                    value={formName}
                                    onChange={(e) => setFormName(e.target.value)}
                                    placeholder="Например, Анна Смирнова"
                                    className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                    autoFocus
                                />
                            </div>
                            <div className="grid gap-[7px]">
                                <label className="text-[12px] font-semibold text-[var(--color-graphite)]">Телефон</label>
                                <PhoneInput
                                    value={formPhone}
                                    onChange={setFormPhone}
                                    placeholder="+7 (___) ___-__-__"
                                    className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                />
                            </div>
                            <div className="grid gap-[7px]">
                                <label className="text-[12px] font-semibold text-[var(--color-graphite)]">Заметка</label>
                                <Input
                                    value={formNotes}
                                    onChange={(e) => setFormNotes(e.target.value)}
                                    placeholder="Необязательно"
                                    className="h-11 border-[var(--color-line)] bg-[var(--color-surface)]"
                                />
                            </div>
                        </div>
                    </DrawerBody>
                    <DrawerFooter>
                        <div className="flex gap-2">
                            <Button
                                onClick={handleSubmit}
                                disabled={!formName.trim() || !formPhone.trim() || isProcessing}
                                className="h-11 flex-1 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)]"
                            >
                                {drawerMode === 'edit' ? 'Сохранить изменения' : 'Добавить клиента'}
                            </Button>
                            <Button
                                variant="outline"
                                onClick={closeDrawer}
                                className="h-11 text-sm font-semibold"
                            >
                                Отмена
                            </Button>
                        </div>
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>

            {/* ─── Block/Unblock confirmation ─── */}
            <AlertDialog open={blockConfirmOpen} onOpenChange={setBlockConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {blockTarget?.is_blocked ? 'Разблокировать клиента?' : 'Заблокировать клиента?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {blockTarget?.is_blocked
                                ? `${blockTarget?.name} снова сможет записываться на приём.`
                                : `${blockTarget?.name} не сможет записываться на приём. Активные записи будут отменены.`
                            }
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Отмена</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleToggleBlock}
                            className={blockTarget?.is_blocked
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                : 'bg-red-600 text-white hover:bg-red-700'
                            }
                        >
                            {blockTarget?.is_blocked ? 'Разблокировать' : 'Заблокировать'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
