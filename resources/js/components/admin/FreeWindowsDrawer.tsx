import { useState, useEffect, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { CalendarDays, Copy, Share2, ChevronRight, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { buildFreeWindowsTextService, buildFreeWindowsTextAll } from '@/components/admin/freeWindowsTextBuilder';

/* ═══════════════ Types ═══════════════ */

interface ServiceOption {
    id: string;
    title: string;
    master_id?: string;
}

interface FreeWindowsDay {
    date: string;
    starts?: string[];
    ranges?: { start: string; end: string }[];
}

interface FreeWindowsResponse {
    mode: 'service' | 'all';
    timezone: string;
    date_from: string;
    date_to: string;
    booking_url: string;
    days: FreeWindowsDay[];
}

interface FreeWindowsDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    isPro: boolean;
    services: ServiceOption[];
}

/* ═══════════════ Constants ═══════════════ */

const PERIOD_OPTIONS = [
    { label: 'Сегодня', days: 1 },
    { label: 'Завтра', days: 1 },
    { label: '3 дня', days: 3 },
    { label: '7 дней', days: 7 },
] as const;

const FULL_MONTHS_RU = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

/* ═══════════════ Hidden state keys ═══════════════ */

function dayKey(date: string): string {
    return `day:${date}`;
}

function startItemKey(date: string, time: string): string {
    return `item:${date}|${time}`;
}

function rangeItemKey(date: string, start: string, end: string): string {
    return `item:${date}|${start}–${end}`;
}

/* ═══════════════ Helpers ═══════════════ */

function formatDayHeader(dateStr: string): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    return `${d} ${FULL_MONTHS_RU[m - 1]}`;
}

function getDateRange(days: number, offset: number = 0): { date_from: string; date_to: string } {
    const start = new Date();
    start.setDate(start.getDate() + offset);
    const end = new Date(start);
    end.setDate(end.getDate() + days - 1);

    const fmt = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return { date_from: fmt(start), date_to: fmt(end) };
}

function hasVisibleItems(day: FreeWindowsDay, hiddenKeys: Set<string>, mode: 'service' | 'all'): boolean {
    if (day.starts && mode === 'service') {
        return day.starts.some(t => !hiddenKeys.has(startItemKey(day.date, t)));
    }
    if (day.ranges && mode === 'all') {
        return day.ranges.some(r => !hiddenKeys.has(rangeItemKey(day.date, r.start, r.end)));
    }
    return false;
}

function deriveVisibleDays(days: FreeWindowsDay[], hiddenKeys: Set<string>, mode: 'service' | 'all'): FreeWindowsDay[] {
    const result: FreeWindowsDay[] = [];

    for (const day of days) {
        if (hiddenKeys.has(dayKey(day.date))) {
            continue;
        }

        if (mode === 'service' && day.starts) {
            const visibleStarts = day.starts.filter(t => !hiddenKeys.has(startItemKey(day.date, t)));
            if (visibleStarts.length > 0) {
                result.push({ ...day, starts: visibleStarts });
            }
        } else if (mode === 'all' && day.ranges) {
            const visibleRanges = day.ranges.filter(r => !hiddenKeys.has(rangeItemKey(day.date, r.start, r.end)));
            if (visibleRanges.length > 0) {
                result.push({ ...day, ranges: visibleRanges });
            }
        }
    }

    return result;
}

/* ═══════════════ Component ═══════════════ */

export default function FreeWindowsDrawer({ open, onOpenChange, isPro, services }: FreeWindowsDrawerProps) {
    const { master_slug } = usePage().props as { master_slug?: string };

    // State
    const [selectedServiceId, setSelectedServiceId] = useState<string>('all');
    const [periodIndex, setPeriodIndex] = useState<number>(0);
    const [customFrom, setCustomFrom] = useState('');
    const [customTo, setCustomTo] = useState('');
    const [showCustom, setShowCustom] = useState(false);
    const [result, setResult] = useState<FreeWindowsResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [hiddenKeys, setHiddenKeys] = useState<Set<string>>(new Set());

    // Reset on close
    useEffect(() => {
        if (!open) {
            setResult(null);
            setError(null);
            setHiddenKeys(new Set());
        }
    }, [open]);

    // Derive visible days
    const visibleDays = useMemo(() => {
        if (!result) return [];
        return deriveVisibleDays(result.days, hiddenKeys, result.mode);
    }, [result, hiddenKeys]);

    const hasAnyBackendDays = result !== null && result.days.length > 0;
    const visibleCount = visibleDays.reduce((sum, d) => {
        if (result?.mode === 'service' && d.starts) return sum + d.starts.length;
        if (result?.mode === 'all' && d.ranges) return sum + d.ranges.length;
        return sum;
    }, 0);
    const allHidden = hasAnyBackendDays && visibleCount === 0;

    // Compute date range
    function getDateParams(): { date_from: string; date_to: string } {
        if (showCustom && customFrom && customTo) {
            return { date_from: customFrom, date_to: customTo };
        }

        const opt = PERIOD_OPTIONS[periodIndex];
        if (periodIndex === 0) return getDateRange(1, 0);
        if (periodIndex === 1) return getDateRange(1, 1);
        return getDateRange(opt.days, 0);
    }

    // Fetch free windows
    async function handleGenerate() {
        setLoading(true);
        setError(null);
        setResult(null);
        setHiddenKeys(new Set());

        const params = getDateParams();
        const queryParams = new URLSearchParams(params);
        if (selectedServiceId !== 'all') {
            queryParams.set('service_id', selectedServiceId);
        }

        try {
            const res = await fetch(`/admin/free-windows?${queryParams.toString()}`, {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
            });

            if (!res.ok) {
                if (res.status === 403) {
                    setError('Функция доступна на тарифе Профи');
                } else {
                    const err = await res.json().catch(() => null);
                    setError(err?.message ?? 'Не удалось получить данные');
                }
                return;
            }

            const data: FreeWindowsResponse = await res.json();
            setResult(data);
        } catch {
            setError('Не удалось получить актуальное расписание. Попробуйте ещё раз.');
        } finally {
            setLoading(false);
        }
    }

    // Toggle day hide/restore
    function toggleDay(date: string) {
        setHiddenKeys(prev => {
            const next = new Set(prev);
            const key = dayKey(date);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    // Toggle item (start or range)
    function toggleItem(key: string) {
        setHiddenKeys(prev => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    // Build text for copy/share using visibleDays
    function buildPublicationText(): string | null {
        if (!result) return null;
        if (visibleDays.length === 0) return null;

        if (result.mode === 'service') {
            const serviceTitle = services.find(s => s.id === selectedServiceId)?.title ?? '';
            return buildFreeWindowsTextService(serviceTitle, visibleDays, result.booking_url);
        }
        return buildFreeWindowsTextAll(visibleDays, result.booking_url);
    }

    // Copy text
    function handleCopyText() {
        const text = buildPublicationText();
        if (!text) return;

        navigator.clipboard.writeText(text).then(
            () => toast.success('Текст скопирован'),
            () => toast.error('Не удалось скопировать'),
        );
    }

    // Copy link
    function handleCopyLink() {
        if (!result) return;

        navigator.clipboard.writeText(result.booking_url).then(
            () => toast.success('Ссылка скопирована'),
            () => toast.error('Не удалось скопировать'),
        );
    }

    // Share
    function handleShare() {
        const text = buildPublicationText();
        if (!text || !navigator.share) return;

        navigator.share({ text }).catch(() => {});
    }

    if (!isPro) {
        return (
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Свободные окна</DialogTitle>
                    </DialogHeader>
                    <div className="py-6 text-center">
                        <CalendarDays className="mx-auto size-10 text-[var(--color-graphite)] opacity-40" />
                        <p className="mt-3 text-[14px] font-medium text-[var(--color-ink)]">
                            Свободные окна
                        </p>
                        <p className="mt-1 text-[13px] text-[var(--color-graphite)]">
                            Просмотр свободных окон и копирование расписания для клиентов
                        </p>
                        <div className="mt-4 inline-flex items-center gap-1.5 rounded-full bg-[var(--color-orange)]/10 px-3 py-1 text-[12px] font-semibold text-[var(--color-orange)]">
                            Профи
                        </div>
                        <div className="mt-4">
                            <Button
                                onClick={() => window.location.href = '/admin/billing'}
                                className="rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                            >
                                Подключить Профи
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto overflow-x-hidden p-4 sm:p-6">
                <DialogHeader>
                    <DialogTitle>Свободные окна</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    {/* Service selector */}
                    <div>
                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                            Услуга
                        </label>
                        <Select value={selectedServiceId} onValueChange={setSelectedServiceId}>
                            <SelectTrigger className="h-[42px] w-full rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Все услуги</SelectItem>
                                {services.map(s => (
                                    <SelectItem key={s.id} value={s.id}>{s.title}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Period controls */}
                    <div>
                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                            Период
                        </label>
                        <div className="flex flex-wrap gap-2">
                            {PERIOD_OPTIONS.map((opt, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => { setPeriodIndex(i); setShowCustom(false); }}
                                    className={`rounded-[8px] border px-3 py-1.5 text-[12px] font-medium transition-colors ${
                                        !showCustom && periodIndex === i
                                            ? 'border-[var(--color-orange)] bg-[var(--color-orange)] text-white'
                                            : 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]'
                                    }`}
                                >
                                    {opt.label}
                                </button>
                            ))}
                            <button
                                type="button"
                                onClick={() => setShowCustom(true)}
                                className={`rounded-[8px] border px-3 py-1.5 text-[12px] font-medium transition-colors ${
                                    showCustom
                                        ? 'border-[var(--color-orange)] bg-[var(--color-orange)] text-white'
                                        : 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]'
                                }`}
                            >
                                Свой период
                            </button>
                        </div>
                        {showCustom && (
                            <div className="mt-2 grid grid-cols-2 gap-2">
                                <div>
                                    <label className="mb-1 block text-[11px] text-[var(--color-graphite)]">С</label>
                                    <input
                                        type="date"
                                        value={customFrom}
                                        onChange={(e) => setCustomFrom(e.target.value)}
                                        max={customTo || undefined}
                                        className="h-[36px] w-full rounded-[8px] border border-[var(--color-line)] bg-[var(--color-surface)] px-2 text-[12px] text-[var(--color-ink)]"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-[11px] text-[var(--color-graphite)]">По</label>
                                    <input
                                        type="date"
                                        value={customTo}
                                        onChange={(e) => setCustomTo(e.target.value)}
                                        min={customFrom || undefined}
                                        className="h-[36px] w-full rounded-[8px] border border-[var(--color-line)] bg-[var(--color-surface)] px-2 text-[12px] text-[var(--color-ink)]"
                                    />
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Generate button */}
                    <Button
                        onClick={handleGenerate}
                        disabled={loading}
                        className="w-full rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)] disabled:opacity-50"
                    >
                        {loading ? (
                            <><Loader2 className="mr-2 size-4 animate-spin" /> Загрузка…</>
                        ) : (
                            'Показать'
                        )}
                    </Button>

                    {/* Error */}
                    {error && (
                        <div className="rounded-[10px] bg-[var(--color-red-bg)] px-4 py-3 text-[13px] text-[var(--color-red)]">
                            {error}
                        </div>
                    )}

                    {/* Result */}
                    {result && !error && (
                        <div className="space-y-3">
                            {result.days.length === 0 ? (
                                <div className="rounded-[10px] bg-[var(--color-warm)] px-4 py-6 text-center">
                                    <p className="text-[13px] text-[var(--color-graphite)]">
                                        На выбранный период свободного времени нет
                                    </p>
                                </div>
                            ) : allHidden ? (
                                <div className="rounded-[10px] bg-[var(--color-warm)] px-4 py-6 text-center">
                                    <p className="text-[13px] font-medium text-[var(--color-ink)]">
                                        Вы скрыли всё свободное время
                                    </p>
                                    <p className="mt-1 text-[12px] text-[var(--color-graphite)]">
                                        Верните хотя бы одно время или день, чтобы поделиться публикацией.
                                    </p>
                                </div>
                            ) : (
                                <div className="rounded-[10px] bg-[var(--color-warm)] px-4 py-3">
                                    {result.days.map(day => {
                                        const isDayHidden = hiddenKeys.has(dayKey(day.date));

                                        return (
                                            <div key={day.date} className="py-2 border-b border-[var(--color-line-soft)] last:border-0">
                                                {/* Day header */}
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className={`text-[13px] font-semibold text-left ${isDayHidden ? 'text-[var(--color-graphite)]' : 'text-[var(--color-ink)]'}`}>
                                                        {formatDayHeader(day.date)}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleDay(day.date)}
                                                        className="shrink-0 text-[12px] text-[var(--color-graphite)] hover:text-[var(--color-ink)] transition-colors"
                                                        aria-label={isDayHidden ? `Вернуть день ${formatDayHeader(day.date)}` : `Скрыть день ${formatDayHeader(day.date)}`}
                                                    >
                                                        {isDayHidden ? 'Вернуть день' : 'Скрыть день'}
                                                    </button>
                                                </div>

                                                {isDayHidden ? (
                                                    <p className="mt-1 text-[11px] text-[var(--color-graphite)] italic">
                                                        День скрыт из публикации
                                                    </p>
                                                ) : (
                                                    /* Chips */
                                                    <div className="mt-1.5 flex flex-wrap gap-2" data-testid="chips-container">
                                                        {result.mode === 'service' && day.starts?.map(time => {
                                                            const key = startItemKey(day.date, time);
                                                            const isHidden = hiddenKeys.has(key);
                                                            return (
                                                                <button
                                                                    key={time}
                                                                    type="button"
                                                                    onClick={() => toggleItem(key)}
                                                                    disabled={isDayHidden}
                                                                    aria-pressed={!isHidden}
                                                                    aria-label={isHidden ? `Вернуть время ${time}` : `Скрыть время ${time}`}
                                                                    className={`rounded-[8px] border px-3 py-1.5 text-[12px] font-medium transition-colors ${
                                                                        isHidden
                                                                            ? 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-graphite)] line-through opacity-60'
                                                                            : 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]'
                                                                    }`}
                                                                >
                                                                    {time}
                                                                </button>
                                                            );
                                                        })}

                                                        {result.mode === 'all' && day.ranges?.map(range => {
                                                            const key = rangeItemKey(day.date, range.start, range.end);
                                                            const isHidden = hiddenKeys.has(key);
                                                            const label = `${range.start}–${range.end}`;
                                                            return (
                                                                <button
                                                                    key={label}
                                                                    type="button"
                                                                    onClick={() => toggleItem(key)}
                                                                    disabled={isDayHidden}
                                                                    aria-pressed={!isHidden}
                                                                    aria-label={isHidden ? `Вернуть диапазон ${label}` : `Скрыть диапазон ${label}`}
                                                                    className={`rounded-[8px] border px-3 py-1.5 text-[12px] font-medium transition-colors ${
                                                                        isHidden
                                                                            ? 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-graphite)] line-through opacity-60'
                                                                            : 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]'
                                                                    }`}
                                                                >
                                                                    {label}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Actions */}
                            {hasAnyBackendDays && (
                                <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-row">
                                    <Button
                                        variant="outline"
                                        onClick={handleCopyText}
                                        disabled={allHidden}
                                        className="w-full sm:flex-1 rounded-[10px] border-[var(--color-line)] text-[12px] font-semibold"
                                    >
                                        <Copy className="mr-1.5 size-3.5" />
                                        Скопировать текст
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={handleCopyLink}
                                        disabled={allHidden}
                                        className="w-full sm:flex-1 rounded-[10px] border-[var(--color-line)] text-[12px] font-semibold"
                                    >
                                        <ChevronRight className="mr-1.5 size-3.5" />
                                        Скопировать ссылку
                                    </Button>
                                    {typeof navigator !== 'undefined' && 'share' in navigator && (
                                        <Button
                                            variant="outline"
                                            onClick={handleShare}
                                            disabled={allHidden}
                                            className="w-full sm:w-auto rounded-[10px] border-[var(--color-line)] px-3 text-[12px] font-semibold"
                                        >
                                            <Share2 className="size-3.5" />
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
