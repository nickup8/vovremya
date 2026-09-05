import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
    ClipboardDocumentIcon, CheckIcon, PlusIcon, PencilSquareIcon, LockClosedIcon, LinkIcon,
} from '@heroicons/react/24/outline';
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
        <div className="flex min-h-[240px] flex-col items-center justify-center rounded-[16px] border border-dashed border-[var(--color-line)] bg-[var(--color-warm)] p-8 text-center">
            <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-[var(--color-orange-100)]">
                <LockClosedIcon className="size-6 text-[var(--color-orange)]" />
            </div>
            <h3 className="text-base font-bold text-[var(--color-ink)]">
                Аналитика каналов записи
            </h3>
            <p className="mt-1 max-w-md text-sm text-[var(--color-graphite)]">
                {compact
                    ? 'Узнайте, какие источники приносят записи, клиентов и деньги. Доступно на тарифе Профи.'
                    : 'Создавайте tracking-ссылки для Instagram, VK, 2GIS и партнёров. Смотрите записи, отмены, завершённые визиты, новых и возвращающихся клиентов и выручку по каждому источнику. Доступно на тарифе Профи.'}
            </p>
            <Button
                className="mt-4 h-10 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)] max-md:min-h-11"
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
        <article className="min-h-[140px] rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
            <header className="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 className="text-[15px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">ТОП-5 каналов записи по выручке</h2>
                    <p className="mt-[3px] text-[11.5px] leading-4 text-[var(--color-graphite)]">Источники, приносящие больше всего денег</p>
                </div>
                {feature && (
                    <button
                        onClick={onSeeAll}
                        className="shrink-0 rounded-[7px] px-1 py-1.5 text-[13px] font-semibold text-[var(--color-orange)] hover:underline max-md:inline-flex max-md:min-h-11 max-md:items-center"
                    >
                        Смотреть все
                    </button>
                )}
            </header>

            {!feature ? (
                <ChannelsUpgradeCard compact />
            ) : !channels || channels.length === 0 ? (
                <p className="py-4 text-center text-sm text-[var(--color-graphite)]">
                    Нет данных за выбранный период
                </p>
            ) : (
                <div className="grid gap-1">
                    {channels.map((c) => (
                        <div key={c.key} className="flex min-h-9 items-center justify-between gap-4 rounded-lg px-0.5 transition-colors hover:bg-[var(--color-surface-hover)]">
                            <span className="flex min-w-0 items-center gap-2 text-[12.5px] font-semibold text-[var(--color-ink)]">
                                <LinkIcon className="size-[15px] shrink-0 text-[var(--color-graphite)]" />
                                <span className="truncate">{c.name}</span>
                            </span>
                            <strong className="shrink-0 text-[12.5px] font-bold text-[var(--color-ink)]">{fmt(c.revenue)} ₽</strong>
                        </div>
                    ))}
                </div>
            )}
        </article>
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

    const inputCls = 'flex-1 rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-ink)] focus:border-[var(--color-orange)] focus:outline-none focus:ring-2 focus:ring-[var(--color-orange-100)]';

    return (
        <div className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
            <div className="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h3 className="text-[18px] font-bold leading-6 tracking-[-.02em] text-[var(--color-ink)]">Tracking-ссылки</h3>
                    <p className="mt-1 text-xs text-[var(--color-graphite)]">Уникальные ссылки на виджет для каждого источника</p>
                </div>
                {!creating && (
                    <Button size="sm" className="h-9 gap-1.5 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)] max-md:min-h-11" onClick={() => setCreating(true)}>
                        <PlusIcon className="size-4" /> Создать
                    </Button>
                )}
            </div>

            {creating && (
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <input
                        type="text"
                        value={newName}
                        onChange={(e) => setNewName(e.target.value)}
                        placeholder="Название (напр. Instagram Stories)"
                        className={inputCls}
                        autoFocus
                        onKeyDown={(e) => e.key === 'Enter' && handleCreate()}
                    />
                    <Button size="sm" className="h-9 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)] max-md:min-h-11" onClick={handleCreate} disabled={!newName.trim()}>Сохранить</Button>
                    <Button size="sm" variant="outline" className="h-9 text-sm font-semibold max-md:min-h-11" onClick={() => {
                        setCreating(false); setNewName('');
                    }}>Отмена</Button>
                </div>
            )}

            {links.length === 0 && !creating ? (
                <p className="py-4 text-center text-sm text-[var(--color-graphite)]">
                    Пока нет ссылок. Создайте первую, чтобы отслеживать источник.
                </p>
            ) : (
                <div className="grid gap-3">
                    {links.map((link) => (
                        <div
                            key={link.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-[14px] border border-[var(--color-line)] bg-[var(--color-warm)] p-3.5"
                        >
                            {editingId === link.id ? (
                                <div className="flex flex-1 items-center gap-2">
                                    <input
                                        type="text"
                                        value={editName}
                                        onChange={(e) => setEditName(e.target.value)}
                                        className={inputCls}
                                        autoFocus
                                        onKeyDown={(e) => e.key === 'Enter' && handleRename(link.id)}
                                    />
                                    <Button size="sm" className="h-9 bg-[var(--color-orange)] text-sm font-semibold text-white hover:bg-[var(--color-orange-600)] max-md:min-h-11 max-md:min-w-11" onClick={() => handleRename(link.id)}>OK</Button>
                                    <Button size="sm" variant="outline" className="h-9 text-sm font-semibold max-md:min-h-11" onClick={() => setEditingId(null)}>Отмена</Button>
                                </div>
                            ) : (
                                <>
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="truncate text-[15px] font-bold text-[var(--color-ink)]">{link.name}</span>
                                        <span
                                            className={`inline-flex h-[22px] shrink-0 items-center rounded-full px-2 text-[11px] font-bold ${
                                                link.is_active
                                                    ? 'bg-[var(--color-paid-bg)] text-[var(--color-paid)]'
                                                    : 'bg-[var(--color-surface-hover)] text-[var(--color-graphite)]'
                                            }`}
                                        >
                                            {link.is_active ? 'Активна' : 'Отключена'}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <button
                                            title="Копировать ссылку"
                                            onClick={() => handleCopy(link)}
                                            className="grid size-9 place-items-center rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] max-md:size-11"
                                        >
                                            {copiedId === link.id ? <CheckIcon className="size-[18px] text-[var(--color-paid)]" /> : <ClipboardDocumentIcon className="size-[18px]" />}
                                        </button>
                                        <button
                                            title="Переименовать"
                                            onClick={() => {
                                                setEditingId(link.id); setEditName(link.name);
                                            }}
                                            className="grid size-9 place-items-center rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] max-md:size-11"
                                        >
                                            <PencilSquareIcon className="size-[18px]" />
                                        </button>
                                        <button
                                            onClick={() => handleToggle(link)}
                                            className="rounded-[10px] px-2.5 py-2 text-xs font-semibold text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-ink)] max-md:inline-flex max-md:min-h-11 max-md:items-center"
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
        <div className="min-w-0 overflow-x-auto rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
            <table className="w-full min-w-[720px] border-collapse text-sm">
                <thead>
                    <tr className="text-left text-[11px] uppercase tracking-[.06em] text-[var(--color-graphite)]">
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 font-bold">Источник</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Записи</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Отменённые</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Завершённые</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Новые</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Возвратные</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Выручка</th>
                        <th className="border-b border-[var(--color-line-soft)] px-3 pb-3 text-right font-bold">Средний чек</th>
                    </tr>
                </thead>
                <tbody>
                    {channels.length === 0 ? (
                        <tr>
                            <td colSpan={8} className="px-3 py-8 text-center text-[var(--color-graphite)]">
                                Нет данных за выбранный период
                            </td>
                        </tr>
                    ) : (
                        channels.map((c) => (
                            <tr key={c.key} className="text-[13px]">
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5">
                                    <span className="font-semibold text-[var(--color-ink)]">{c.name}</span>
                                </td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right tabular-nums text-[var(--color-graphite)]">{c.created_count}</td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right tabular-nums text-[var(--color-graphite)]">{c.cancelled_count}</td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right tabular-nums text-[var(--color-graphite)]">{c.completed_count}</td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right tabular-nums text-[var(--color-graphite)]">{c.new_clients_count}</td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right tabular-nums text-[var(--color-graphite)]">{c.returning_clients_count}</td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right font-bold tabular-nums text-[var(--color-ink)]">{fmt(c.revenue)} ₽</td>
                                <td className="border-b border-[var(--color-line-soft)] px-3 py-3.5 text-right tabular-nums text-[var(--color-graphite)]">{fmt(c.average_check)} ₽</td>
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
        <div className="grid gap-4">
            <TrackingLinksManager links={trackingLinks ?? []} />
            <ChannelsTable channels={channels ?? []} />
        </div>
    );
}
