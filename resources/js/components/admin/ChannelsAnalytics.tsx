import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Copy, Check, Plus, Pencil, Lock, Link2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

/* ═══════════════ Types ═══════════════ */

export interface ChannelSource {
    key: string;
    type: 'tracking' | 'manual' | 'direct';
    name: string;
    created_count: number;
    cancelled_count: number;
    completed_count: number;
    new_clients_count: number;
    returning_clients_count: number;
    revenue: number;
    average_check: number;
}

export interface TrackingLinkItem {
    id: string;
    name: string;
    is_active: boolean;
    url: string;
}

const fmt = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n));

/* ═══════════════ Upgrade CTA (locked state) ═══════════════ */

export function ChannelsUpgradeCard({ compact = false }: { compact?: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center dark:border-zinc-700 dark:bg-zinc-900/50">
            <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-950/50">
                <Lock className="size-6 text-blue-600 dark:text-blue-400" />
            </div>
            <h3 className="text-base font-semibold text-slate-900 dark:text-zinc-100">
                Аналитика каналов записи
            </h3>
            <p className="mt-1 max-w-md text-sm text-slate-500 dark:text-zinc-400">
                {compact
                    ? 'Узнайте, какие источники приносят записи, клиентов и деньги. Доступно на тарифе Профи.'
                    : 'Создавайте tracking-ссылки для Instagram, VK, 2GIS и партнёров. Смотрите записи, отмены, завершённые визиты, новых и возвращающихся клиентов и выручку по каждому источнику. Доступно на тарифе Профи.'}
            </p>
            <Button
                className="mt-4"
                onClick={() => router.visit('/admin/billing')}
            >
                Перейти на Профи
            </Button>
        </div>
    );
}

/* ═══════════════ TOP-5 block (overview tab) ═══════════════ */

export function TopChannelsBlock({
    channels,
    feature,
    onSeeAll,
}: {
    channels: ChannelSource[] | null;
    feature: boolean;
    onSeeAll: () => void;
}) {
    return (
        <div className="rounded-xl border border-slate-100 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-5 flex items-center justify-between">
                <div>
                    <h3 className="font-semibold text-slate-900 dark:text-zinc-100">ТОП-5 каналов записи по выручке</h3>
                    <p className="text-xs text-slate-500 dark:text-zinc-400">Источники, приносящие больше всего денег</p>
                </div>
                {feature && (
                    <button
                        onClick={onSeeAll}
                        className="text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400"
                    >
                        Смотреть все
                    </button>
                )}
            </div>

            {!feature ? (
                <ChannelsUpgradeCard compact />
            ) : !channels || channels.length === 0 ? (
                <p className="py-4 text-center text-sm text-slate-400 dark:text-zinc-500">
                    Нет данных за выбранный период
                </p>
            ) : (
                <div className="space-y-3">
                    {channels.map((c) => (
                        <div key={c.key} className="flex items-center justify-between gap-3">
                            <div className="flex min-w-0 items-center gap-2">
                                <Link2 className="size-4 shrink-0 text-slate-400" />
                                <span className="truncate text-sm font-medium text-slate-700 dark:text-zinc-300">{c.name}</span>
                            </div>
                            <span className="shrink-0 text-sm font-bold text-slate-900 dark:text-zinc-100">{fmt(c.revenue)} ₽</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ═══════════════ Tracking Links Management ═══════════════ */

function TrackingLinksManager({ links }: { links: TrackingLinkItem[] }) {
    const [creating, setCreating] = useState(false);
    const [newName, setNewName] = useState('');
    const [editingId, setEditingId] = useState<string | null>(null);
    const [editName, setEditName] = useState('');
    const [copiedId, setCopiedId] = useState<string | null>(null);

    function handleCreate() {
        if (!newName.trim()) {
            return;
        }

        router.post('/admin/tracking-links', { name: newName.trim() }, {
            preserveScroll: true,
            onSuccess: () => {
                setNewName('');
                setCreating(false);
            },
        });
    }

    function handleRename(id: string) {
        if (!editName.trim()) {
            return;
        }

        router.put(`/admin/tracking-links/${id}`, { name: editName.trim() }, {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    }

    function handleToggle(link: TrackingLinkItem) {
        router.patch(`/admin/tracking-links/${link.id}/active`, { is_active: !link.is_active }, {
            preserveScroll: true,
        });
    }

    function handleCopy(link: TrackingLinkItem) {
        navigator.clipboard.writeText(link.url).then(() => {
            setCopiedId(link.id);
            toast.success('Ссылка скопирована');
            setTimeout(() => setCopiedId(null), 2000);
        });
    }

    return (
        <div className="rounded-xl border border-slate-100 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h3 className="font-semibold text-slate-900 dark:text-zinc-100">Tracking-ссылки</h3>
                    <p className="text-xs text-slate-500 dark:text-zinc-400">Уникальные ссылки на виджет для каждого источника</p>
                </div>
                {!creating && (
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus className="size-4" /> Создать
                    </Button>
                )}
            </div>

            {creating && (
                <div className="mb-4 flex items-center gap-2">
                    <input
                        type="text"
                        value={newName}
                        onChange={(e) => setNewName(e.target.value)}
                        placeholder="Название (напр. Instagram Stories)"
                        className="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        autoFocus
                        onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
                    />
                    <Button size="sm" onClick={handleCreate} disabled={!newName.trim()}>Сохранить</Button>
                    <Button size="sm" variant="outline" onClick={() => {
                        setCreating(false); setNewName('');
                    }}>Отмена</Button>
                </div>
            )}

            {links.length === 0 && !creating ? (
                <p className="py-4 text-center text-sm text-slate-400 dark:text-zinc-500">
                    Пока нет ссылок. Создайте первую, чтобы отслеживать источник.
                </p>
            ) : (
                <div className="space-y-2">
                    {links.map((link) => (
                        <div
                            key={link.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 p-3 dark:border-zinc-800"
                        >
                            {editingId === link.id ? (
                                <div className="flex flex-1 items-center gap-2">
                                    <input
                                        type="text"
                                        value={editName}
                                        onChange={(e) => setEditName(e.target.value)}
                                        className="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                        autoFocus
                                        onKeyDown={(e) => e.key === 'Enter' && handleRename(link.id)}
                                    />
                                    <Button size="sm" onClick={() => handleRename(link.id)}>OK</Button>
                                    <Button size="sm" variant="outline" onClick={() => setEditingId(null)}>Отмена</Button>
                                </div>
                            ) : (
                                <>
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="truncate text-sm font-medium text-slate-800 dark:text-zinc-200">{link.name}</span>
                                        <span
                                            className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                link.is_active
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                                    : 'bg-slate-200 text-slate-500 dark:bg-zinc-700 dark:text-zinc-400'
                                            }`}
                                        >
                                            {link.is_active ? 'Активна' : 'Отключена'}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <button
                                            title="Копировать ссылку"
                                            onClick={() => handleCopy(link)}
                                            className="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-zinc-800"
                                        >
                                            {copiedId === link.id ? <Check className="size-4 text-emerald-500" /> : <Copy className="size-4" />}
                                        </button>
                                        <button
                                            title="Переименовать"
                                            onClick={() => {
                                                setEditingId(link.id); setEditName(link.name);
                                            }}
                                            className="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-zinc-800"
                                        >
                                            <Pencil className="size-4" />
                                        </button>
                                        <button
                                            onClick={() => handleToggle(link)}
                                            className="rounded-md px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                        >
                                            {link.is_active ? 'Отключить' : 'Включить'}
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ═══════════════ Channels Analytics Table ═══════════════ */

function ChannelsTable({ channels }: { channels: ChannelSource[] }) {
    return (
        <div className="overflow-x-auto rounded-xl border border-slate-100 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <table className="w-full min-w-[720px] text-sm">
                <thead>
                    <tr className="border-b border-slate-100 text-left text-xs text-slate-500 dark:border-zinc-800 dark:text-zinc-400">
                        <th className="px-4 py-3 font-medium">Источник</th>
                        <th className="px-4 py-3 text-right font-medium">Записи</th>
                        <th className="px-4 py-3 text-right font-medium">Отменённые</th>
                        <th className="px-4 py-3 text-right font-medium">Завершённые</th>
                        <th className="px-4 py-3 text-right font-medium">Новые</th>
                        <th className="px-4 py-3 text-right font-medium">Возвратные</th>
                        <th className="px-4 py-3 text-right font-medium">Выручка</th>
                        <th className="px-4 py-3 text-right font-medium">Средний чек</th>
                    </tr>
                </thead>
                <tbody>
                    {channels.length === 0 ? (
                        <tr>
                            <td colSpan={8} className="px-4 py-8 text-center text-slate-400 dark:text-zinc-500">
                                Нет данных за выбранный период
                            </td>
                        </tr>
                    ) : (
                        channels.map((c) => (
                            <tr key={c.key} className="border-b border-slate-50 last:border-0 dark:border-zinc-800/50">
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium text-slate-800 dark:text-zinc-200">{c.name}</span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 text-right text-slate-600 dark:text-zinc-300">{c.created_count}</td>
                                <td className="px-4 py-3 text-right text-slate-600 dark:text-zinc-300">{c.cancelled_count}</td>
                                <td className="px-4 py-3 text-right text-slate-600 dark:text-zinc-300">{c.completed_count}</td>
                                <td className="px-4 py-3 text-right text-slate-600 dark:text-zinc-300">{c.new_clients_count}</td>
                                <td className="px-4 py-3 text-right text-slate-600 dark:text-zinc-300">{c.returning_clients_count}</td>
                                <td className="px-4 py-3 text-right font-semibold text-slate-900 dark:text-zinc-100">{fmt(c.revenue)} ₽</td>
                                <td className="px-4 py-3 text-right text-slate-600 dark:text-zinc-300">{fmt(c.average_check)} ₽</td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}

/* ═══════════════ Channels Tab (main export) ═══════════════ */

export function ChannelsTab({
    feature,
    channels,
    trackingLinks,
}: {
    feature: boolean;
    channels: ChannelSource[] | null;
    trackingLinks: TrackingLinkItem[] | null;
}) {
    if (!feature) {
        return <ChannelsUpgradeCard />;
    }

    return (
        <div className="space-y-6">
            <TrackingLinksManager links={trackingLinks ?? []} />
            <ChannelsTable channels={channels ?? []} />
        </div>
    );
}
