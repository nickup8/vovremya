import { useState, useMemo, useEffect, useRef, useCallback } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    Search, Plus,
    Users, Phone, Send, MessageCircle,
    CalendarPlus, Pencil, ShieldCheck,
    ShieldOff, Shield, ChevronLeft, ChevronRight,
    RefreshCw, Loader2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import AdminLayout from '@/layouts/AdminLayout';
import { getInitials } from '@/lib/utils';
import { PhoneInput } from '@/components/PhoneInput';
import { formatPhone, stripPhoneMask } from '@/lib/phone';
import type { Client, Paginated, PageProps, ReactivationCandidate } from '@/types/app';

/* ═══════════════ Helpers ═══════════════ */

type FilterType = 'all' | 'active' | 'reactivation' | 'blocked';

function formatDate(dateStr: string | null): string {
    if (!dateStr) {
return '—';
}

    const months = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    const d = new Date(dateStr);

    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
}

function formatCurrency(value: number): string {
    return value.toLocaleString('ru-RU') + ' ₽';
}

function pluralDays(n: number): string {
    const abs = Math.abs(n) % 100;
    const last = abs % 10;
    if (abs > 10 && abs < 20) return 'дней';
    if (last === 1) return 'день';
    if (last >= 2 && last <= 4) return 'дня';
    return 'дней';
}

/* ═══════════════ Candidate State ═══════════════ */

type CandidateState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; data: ReactivationCandidate[] }
    | { status: 'error'; message: string };

/* ═══════════════ Client Card ═══════════════ */

function ClientCard({ client, onEdit, onToggleBlock, isProcessing, hasReactivationFeature, onToggleReactivation, reactivationProcessing }: {
    client: Client;
    onEdit: (c: Client) => void;
    onToggleBlock: (c: Client) => void;
    isProcessing: boolean;
    hasReactivationFeature: boolean;
    onToggleReactivation: (c: Client, nextEnabled: boolean) => void;
    reactivationProcessing: string | null;
}) {
    const initials = getInitials(client.name);

    return (
        <div className={`overflow-hidden rounded-lg border bg-white transition-shadow hover:shadow-md dark:bg-zinc-900 ${
            client.is_blocked
                ? 'border-red-200 dark:border-red-800/50'
                : 'border-slate-200 dark:border-zinc-700'
        }`}>
            {/* Header */}
            <div className="flex items-start justify-between border-b border-slate-100 p-4 dark:border-zinc-800">
                <div className="flex items-center gap-3">
                    <Avatar className="size-10">
                        <AvatarImage src={client.avatar_url ?? undefined} alt={client.name} className="object-cover" />
                        <AvatarFallback className="bg-gradient-to-br from-slate-200 to-slate-300 text-sm font-bold text-slate-600 dark:from-zinc-700 dark:to-zinc-600 dark:text-zinc-200">
                            {initials}
                        </AvatarFallback>
                    </Avatar>
                    <div>
                        <h3 className="text-sm font-bold text-slate-900 dark:text-zinc-100">
                            {client.name}
                        </h3>
                        <p className="flex items-center gap-1 font-mono text-xs text-slate-500 dark:text-zinc-400">
                            <Phone className="size-3" />
                            {client.phone ? (
                                <a href={`tel:+${client.phone.replace(/\D/g, '')}`} className="hover:text-blue-600 dark:hover:text-blue-400">
                                    {formatPhone(client.phone)}
                                </a>
                            ) : '—'}
                        </p>
                    </div>
                </div>
                {client.is_blocked ? (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-600 dark:border-red-800 dark:bg-red-950/40 dark:text-red-400">
                        <ShieldOff className="size-3" />
                        Заблокирован
                    </span>
                ) : (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400">
                        <ShieldCheck className="size-3" />
                        Активен
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
                        {client.completed_bookings} визитов
                    </span>
                </div>

                {/* Statistics */}
                <div className="grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-lg bg-slate-50 p-2 dark:bg-zinc-800/50">
                        <p className="text-slate-500 dark:text-zinc-400">LTV</p>
                        <p className="font-bold text-slate-900 dark:text-zinc-100">{formatCurrency(client.ltv)}</p>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-2 dark:bg-zinc-800/50">
                        <p className="text-slate-500 dark:text-zinc-400">Последний визит</p>
                        <p className="font-bold text-slate-900 dark:text-zinc-100">{formatDate(client.last_visit)}</p>
                    </div>
                </div>

                {/* Reactivation toggle */}
                {hasReactivationFeature && (
                    <div className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-zinc-800/50">
                        <RefreshCw className="size-3.5 text-slate-400 dark:text-zinc-500" />
                        <span className="flex-1 text-xs font-medium text-slate-600 dark:text-zinc-400">
                            Умный возврат
                        </span>
                        <Switch
                            checked={!client.disable_reactivation}
                            disabled={reactivationProcessing === client.id}
                            onCheckedChange={(enabled) => onToggleReactivation(client, enabled)}
                            className="scale-75"
                        />
                        <span className="text-xs text-slate-400 dark:text-zinc-500">
                            {!client.disable_reactivation ? 'Вкл' : 'Выкл'}
                        </span>
                    </div>
                )}

                {/* Action buttons */}
                <div className="flex gap-2 pt-2">
                    <button
                        onClick={() => router.get('/admin/calendar', { client_id: client.id })}
                        className="flex flex-1 items-center justify-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        <CalendarPlus className="size-3" />
                        Записать
                    </button>
                    <button
                        onClick={() => onEdit(client)}
                        className="flex flex-1 items-center justify-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        <Pencil className="size-3" />
                        Изменить
                    </button>
                    <button
                        onClick={() => onToggleBlock(client)}
                        disabled={isProcessing}
                        className={`flex items-center justify-center gap-1 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors disabled:opacity-50 ${
                            client.is_blocked
                                ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:hover:bg-emerald-900/50'
                                : 'bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50'
                        }`}
                    >
                        {client.is_blocked ? <Shield className="size-3" /> : <ShieldOff className="size-3" />}
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Candidate Card ═══════════════ */

function CandidateCard({ candidate }: { candidate: ReactivationCandidate }) {
    return (
        <div className="overflow-hidden rounded-lg border border-amber-200 bg-white transition-shadow hover:shadow-md dark:border-amber-800/50 dark:bg-zinc-900">
            <div className="border-b border-slate-100 p-4 dark:border-zinc-800">
                <div className="flex items-center justify-between">
                    <h3 className="text-sm font-bold text-slate-900 dark:text-zinc-100">
                        {candidate.client_name}
                    </h3>
                    <span className="inline-flex shrink-0 items-center gap-1 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-400">
                        <RefreshCw className="size-3" />
                        На возврат
                    </span>
                </div>
                <p className="mt-1 text-xs font-medium text-blue-600 dark:text-blue-400">
                    {candidate.service_name}
                </p>
            </div>

            <div className="space-y-3 p-4">
                <div className="grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-lg bg-slate-50 p-2 dark:bg-zinc-800/50">
                        <p className="text-slate-500 dark:text-zinc-400">Последний визит</p>
                        <p className="font-bold text-slate-900 dark:text-zinc-100">{formatDate(candidate.last_visit_at)}</p>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-2 dark:bg-zinc-800/50">
                        <p className="text-slate-500 dark:text-zinc-400">Цикл</p>
                        <p className="font-bold text-slate-900 dark:text-zinc-100">{candidate.reactivation_days} {pluralDays(candidate.reactivation_days)}</p>
                    </div>
                </div>

                <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                    {candidate.days_overdue === 0
                        ? 'Сегодня пора вернуть'
                        : `Пора вернуть: ${candidate.days_overdue} ${pluralDays(candidate.days_overdue)} назад`}
                </div>

                <button
                    onClick={() => router.get('/admin/calendar', { client_id: candidate.client_id })}
                    className="flex w-full items-center justify-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                >
                    <CalendarPlus className="size-3" />
                    Записать
                </button>
            </div>
        </div>
    );
}

/* ═══════════════ Main Clients Page ═══════════════ */

export default function ClientsPage() {
    const { clients: paginatedClients, auth, has_reactivation_feature } = usePage<PageProps & { clients: Paginated<Client>; has_reactivation_feature: boolean }>().props;
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<FilterType>('all');
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingClient, setEditingClient] = useState<Client | null>(null);
    const [formName, setFormName] = useState('');
    const [formPhone, setFormPhone] = useState('');
    const [isProcessing, setIsProcessing] = useState(false);
    const [reactivationProcessing, setReactivationProcessing] = useState<string | null>(null);

    // ── Candidate state ──
    const [candidateState, setCandidateState] = useState<CandidateState>({ status: 'idle' });
    const abortRef = useRef<AbortController | null>(null);

    const fetchCandidates = useCallback(() => {
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setCandidateState({ status: 'loading' });

        fetch('/admin/reactivation/candidates', {
            signal: controller.signal,
            headers: { 'Accept': 'application/json' },
        })
            .then((res) => {
                if (!res.ok) throw new Error(`${res.status}`);
                return res.json();
            })
            .then((data) => {
                if (!controller.signal.aborted) {
                    setCandidateState({ status: 'success', data });
                }
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                if (!controller.signal.aborted) {
                    setCandidateState({ status: 'error', message: 'Не удалось загрузить список' });
                }
            });
    }, []);

    useEffect(() => {
        if (filter === 'reactivation') {
            fetchCandidates();
        }
        return () => {
            abortRef.current?.abort();
        };
    }, [filter, fetchCandidates]);

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

        if (filter === 'active') {
            result = result.filter((c) => !c.is_blocked);
        } else if (filter === 'blocked') {
            result = result.filter((c) => c.is_blocked);
        }

        return result;
    }, [initialClients, search, filter]);

    const filteredCandidates = useMemo(() => {
        if (candidateState.status !== 'success') return [];
        if (!search.trim()) return candidateState.data;
        const q = search.toLowerCase();
        return candidateState.data.filter(
            (c) => c.client_name.toLowerCase().includes(q) || c.service_name.toLowerCase().includes(q),
        );
    }, [candidateState, search]);

    const filterTabs: { key: FilterType; label: string }[] = useMemo(() => {
        const tabs: { key: FilterType; label: string }[] = [
            { key: 'all', label: 'Все' },
            { key: 'active', label: 'Активные' },
        ];
        if (has_reactivation_feature) {
            tabs.push({ key: 'reactivation', label: 'На возврат' });
        }
        tabs.push({ key: 'blocked', label: 'Блок' });
        return tabs;
    }, [has_reactivation_feature]);

    function openCreate() {
        setEditingClient(null);
        setFormName('');
        setFormPhone('');
        setDialogOpen(true);
    }

    function openEdit(client: Client) {
        setEditingClient(client);
        setFormName(client.name);
        setFormPhone(client.phone || '');
        setDialogOpen(true);
    }

    function handleToggleBlock(client: Client) {
        if (isProcessing) {
return;
}

        setIsProcessing(true);
        router.post(`/admin/clients/${client.id}/toggle-block`, {}, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] || 'Не удалось изменить статус клиента');
            },
            onFinish: () => {
                setIsProcessing(false);
            },
        });
    }

    function handleToggleReactivation(client: Client, nextEnabled: boolean) {
        if (reactivationProcessing) {
return;
}

        setReactivationProcessing(client.id);
        router.patch(`/admin/clients/${client.id}/reactivation`, {
            disable_reactivation: !nextEnabled,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] || 'Не удалось изменить настройку');
            },
            onFinish: () => {
                setReactivationProcessing(null);
            },
        });
    }

    function handleSubmit() {
        if (!formName.trim() || !formPhone.trim() || isProcessing) {
return;
}

        setIsProcessing(true);

        const phone = stripPhoneMask(formPhone);

        if (editingClient) {
            router.put(`/admin/clients/${editingClient.id}`, {
                name: formName,
                phone,
            }, {
                preserveScroll: true,
                onError: (errors) => {
                    toast.error(Object.values(errors)[0] || 'Не удалось обновить клиента');
                    setIsProcessing(false);
                },
                onSuccess: () => {
                    setDialogOpen(false);
                    setEditingClient(null);
                    setFormName('');
                    setFormPhone('');
                    setIsProcessing(false);
                },
            });
        } else {
            router.post('/admin/clients', {
                name: formName,
                phone,
            }, {
                preserveScroll: true,
                onError: (errors) => {
                    toast.error(Object.values(errors)[0] || 'Не удалось добавить клиента');
                    setIsProcessing(false);
                },
                onSuccess: () => {
                    setDialogOpen(false);
                    setEditingClient(null);
                    setFormName('');
                    setFormPhone('');
                    setIsProcessing(false);
                },
            });
        }
    }

    return (
        <>
            <Head title="База клиентов — Вовремя" />

            <AdminLayout title="База клиентов" auth={auth}>
                        <div className="space-y-4">
                            {/* ─── Top Panel: Search + Filters + Add ─── */}
                            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                                <div className="relative min-w-[200px] flex-1">
                                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400 dark:text-zinc-500" />
                                    <Input
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder={filter === 'reactivation' ? 'Поиск по имени или услуге...' : 'Поиск по имени или телефону...'}
                                        className="border-slate-200 bg-slate-50 pl-9 dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                </div>
                                <div className="flex rounded-md bg-slate-100 p-1 dark:bg-zinc-800">
                                    {filterTabs.map((tab) => (
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
                                    onClick={openCreate}
                                >
                                    <Plus className="size-4" />
                                    Добавить
                                </Button>
                            </div>

                            {/* ─── Content ─── */}
                            {filter === 'reactivation' ? (
                                /* ─── Reactivation Candidates ─── */
                                candidateState.status === 'loading' ? (
                                    <div className="flex items-center justify-center rounded-lg border border-slate-200 bg-white p-12 dark:border-zinc-700 dark:bg-zinc-900" aria-busy="true">
                                        <Loader2 className="size-6 animate-spin text-slate-400 dark:text-zinc-500" />
                                        <p className="ml-3 text-sm text-slate-500 dark:text-zinc-400">Загружаем клиентов…</p>
                                    </div>
                                ) : candidateState.status === 'error' ? (
                                    <div className="rounded-lg border border-red-200 bg-white p-12 text-center dark:border-red-800/50 dark:bg-zinc-900" role="alert">
                                        <p className="text-sm text-red-600 dark:text-red-400">{candidateState.message}</p>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="mt-3"
                                            onClick={fetchCandidates}
                                        >
                                            Попробовать снова
                                        </Button>
                                    </div>
                                ) : filteredCandidates.length === 0 ? (
                                    <div className="rounded-lg border border-slate-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                                        <RefreshCw className="mx-auto size-12 text-slate-300 dark:text-zinc-600" />
                                        <p className="mt-3 text-sm text-slate-500 dark:text-zinc-400">
                                            {search ? 'Клиенты не найдены' : 'Сейчас возвращать никого не нужно'}
                                        </p>
                                        {!search && (
                                            <p className="mt-1 text-xs text-slate-400 dark:text-zinc-500">
                                                Здесь появятся клиенты, когда пройдёт заданный для услуги цикл.
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                        {filteredCandidates.map((c) => (
                                            <CandidateCard
                                                key={`${c.client_id}:${c.service_catalog_id}`}
                                                candidate={c}
                                            />
                                        ))}
                                    </div>
                                )
                            ) : (
                                /* ─── Ordinary Client Cards ─── */
                                clients.length === 0 ? (
                                    <div className="rounded-lg border border-slate-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                                        <Users className="mx-auto size-12 text-slate-300 dark:text-zinc-600" />
                                        <p className="mt-3 text-sm text-slate-500 dark:text-zinc-400">
                                            {search ? 'Клиенты не найдены' : 'Пока нет клиентов'}
                                        </p>
                                    </div>
                                ) : (
                                    <>
                                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                            {clients.map((client) => (
                                                <ClientCard
                                                    key={client.id}
                                                    client={client}
                                                    onEdit={openEdit}
                                                    onToggleBlock={handleToggleBlock}
                                                    isProcessing={isProcessing}
                                                    hasReactivationFeature={has_reactivation_feature}
                                                    onToggleReactivation={handleToggleReactivation}
                                                    reactivationProcessing={reactivationProcessing}
                                                />
                                            ))}
                                        </div>

                                        {/* ─── Pagination ─── */}
                                        {paginatedClients && paginatedClients.last_page > 1 && (
                                            <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                                                <p className="text-sm text-slate-500 dark:text-zinc-400">
                                                    {paginatedClients.from}–{paginatedClients.to} из {paginatedClients.total}
                                                </p>
                                                <div className="flex gap-1">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={paginatedClients.current_page <= 1}
                                                        onClick={() => router.get(`/admin/clients?page=${paginatedClients.current_page - 1}`)}
                                                    >
                                                        <ChevronLeft className="size-4" />
                                                    </Button>
                                                    {Array.from({ length: paginatedClients.last_page }, (_, i) => i + 1)
                                                        .filter((p) => Math.abs(p - paginatedClients.current_page) <= 2 || p === 1 || p === paginatedClients.last_page)
                                                        .map((p, idx, arr) => (
                                                            <span key={p} className="flex items-center">
                                                                {idx > 0 && arr[idx - 1] !== p - 1 && (
                                                                    <span className="px-1 text-slate-400">...</span>
                                                                )}
                                                                <Button
                                                                    variant={p === paginatedClients.current_page ? 'default' : 'outline'}
                                                                    size="sm"
                                                                    className={p === paginatedClients.current_page ? 'bg-blue-600 text-white hover:bg-blue-700' : ''}
                                                                    onClick={() => router.get(`/admin/clients?page=${p}`)}
                                                                >
                                                                    {p}
                                                                </Button>
                                                            </span>
                                                        ))}
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={paginatedClients.current_page >= paginatedClients.last_page}
                                                        onClick={() => router.get(`/admin/clients?page=${paginatedClients.current_page + 1}`)}
                                                    >
                                                        <ChevronRight className="size-4" />
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </>
                                )
                            )}
                        </div>
            </AdminLayout>

            {/* ─── Create / Edit Client Dialog ─── */}
            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingClient ? 'Редактировать клиента' : 'Новый клиент'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Имя *
                            </label>
                            <Input
                                value={formName}
                                onChange={(e) => setFormName(e.target.value)}
                                placeholder="Иван Иванов"
                                className="dark:bg-zinc-800"
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                Телефон *
                            </label>
                            <PhoneInput
                                value={formPhone}
                                onChange={setFormPhone}
                                placeholder="+7 (911) 123-45-67"
                                className="dark:bg-zinc-800"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDialogOpen(false)}>
                            Отмена
                        </Button>
                        <Button
                            onClick={handleSubmit}
                            disabled={!formName.trim() || !formPhone.trim() || isProcessing}
                            className="bg-blue-600 text-white hover:bg-blue-700"
                        >
                            {editingClient ? 'Сохранить' : 'Добавить'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
