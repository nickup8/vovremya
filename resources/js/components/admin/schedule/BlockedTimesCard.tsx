import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2, Repeat, Pause, Play, Lock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

// ── Types ──

interface RecurringException {
    id: string;
    occurrence_date: string;
    type: 'skip' | 'override';
    override_start_time: string | null;
    override_end_time: string | null;
}

export interface BlockedTime {
    id: string;
    start_datetime: string;
    end_datetime: string;
    reason: string;
}

export interface RecurringSeries {
    id: string;
    title: string;
    reason: string | null;
    start_date: string;
    start_time: string;
    end_time: string;
    recurrence_type: 'daily' | 'weekly';
    interval: number;
    weekdays: number[] | null;
    ends_at: string | null;
    timezone: string;
    status: 'active' | 'paused' | 'cancelled';
    exceptions: RecurringException[];
}

// ── Constants ──

const BLOCKED_REASONS = [
    'Отпуск',
    'Больничный',
    'Обед',
    'Личное время',
    'Другое',
];

const WEEKDAY_NAMES = [
    { value: 1, label: 'Пн' },
    { value: 2, label: 'Вт' },
    { value: 3, label: 'Ср' },
    { value: 4, label: 'Чт' },
    { value: 5, label: 'Пт' },
    { value: 6, label: 'Сб' },
    { value: 7, label: 'Вс' },
];

const WEEKDAY_SHORT: Record<number, string> = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

// ── Helpers ──

function formatBlockedTimeRange(startIso: string, endIso: string, timezone: string): string {
    const opts: Intl.DateTimeFormatOptions = { timeZone: timezone, hour12: false };
    const start = new Date(startIso);
    const end = new Date(endIso);
    const dateStr = (d: Date) => d.toLocaleDateString('ru-RU', { ...opts, day: '2-digit', month: '2-digit', year: 'numeric' });
    const timeStr = (d: Date) => d.toLocaleTimeString('ru-RU', { ...opts, hour: '2-digit', minute: '2-digit' });
    const startDay = dateStr(start);
    const endDay = dateStr(end);
    if (startDay === endDay) return `${startDay} · ${timeStr(start)}–${timeStr(end)}`;
    return `${startDay} ${timeStr(start)} — ${endDay} ${timeStr(end)}`;
}

function describeRecurrence(s: RecurringSeries): string {
    const parts: string[] = [];
    const days = (s.weekdays || []).map(d => WEEKDAY_SHORT[d] || '').filter(Boolean);
    const time = `${s.start_time.slice(0, 5)}–${s.end_time.slice(0, 5)}`;

    if (s.recurrence_type === 'daily') {
        parts.push(s.interval === 1 ? 'Каждый день' : `Каждые ${s.interval} дня`);
    } else {
        if (s.interval === 1 && days.length >= 5 && days.includes('Пн') && days.includes('Пт')) {
            parts.push('Пн–Пт');
        } else if (s.interval === 1 && days.length === 1) {
            parts.push(`Каждый ${days[0].toLowerCase() === 'вт' ? 'вторник' : days[0]}`);
        } else if (s.interval === 1) {
            parts.push(days.join(', '));
        } else {
            parts.push(`Каждые ${s.interval} нед.`);
            parts.push(days.join(', '));
        }
    }

    parts.push(time);

    if (s.ends_at) {
        parts.push(`до ${new Date(s.ends_at).toLocaleDateString('ru-RU')}`);
    }

    return parts.join(' · ');
}

type DisplayRecurrence = 'daily' | 'weekly' | 'n_weekly';

// ── Component ──

export default function BlockedTimesCard({ masterId, timezone }: { masterId?: string; timezone: string }) {
    const { blockedTimes: rawBlocked, recurringSeries: rawRecurring, hasRecurringFeature } = usePage<{
        blockedTimes: BlockedTime[];
        recurringSeries: RecurringSeries[];
        hasRecurringFeature: boolean;
    }>().props;

    const blockedTimes = rawBlocked || [];
    const recurringSeries = (rawRecurring || []).filter(s => s.status !== 'cancelled');

    const [dialogOpen, setDialogOpen] = useState(false);

    // Form state
    const [title, setTitle] = useState('');
    const [repeat, setRepeat] = useState(false);
    const [displayRecurrence, setDisplayRecurrence] = useState<DisplayRecurrence>('weekly');
    const [interval, setInterval] = useState(1);
    const [weekdays, setWeekdays] = useState<number[]>([1, 2, 3, 4, 5]);
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [startTime, setStartTime] = useState('09:00');
    const [endTime, setEndTime] = useState('10:00');
    const [neverEnds, setNeverEnds] = useState(true);
    const [reason, setReason] = useState('Другое');

    function resetForm() {
        setTitle('');
        setRepeat(false);
        setDisplayRecurrence('weekly');
        setInterval(1);
        setWeekdays([1, 2, 3, 4, 5]);
        setStartDate('');
        setEndDate('');
        setStartTime('09:00');
        setEndTime('10:00');
        setNeverEnds(true);
        setReason('Другое');
    }

    function handleSubmit() {
        if (repeat) {
            handleAddRecurring();
        } else {
            handleAddOneOff();
        }
    }

    function handleAddOneOff() {
        if (!startDate || !endDate) return;
        const payload: Record<string, unknown> = {
            start_datetime: startDate,
            end_datetime: endDate,
            reason: title || reason,
        };
        if (masterId) payload.master_id = masterId;

        router.post('/admin/blocked-times', payload, {
            preserveScroll: true,
            onSuccess: () => { setDialogOpen(false); resetForm(); },
        });
    }

    function handleAddRecurring() {
        if (!title || !startDate || !startTime || !endTime) return;
        const isDaily = displayRecurrence === 'daily';
        const payload: Record<string, unknown> = {
            title,
            reason: title,
            start_date: startDate,
            start_time: startTime,
            end_time: endTime,
            recurrence_type: isDaily ? 'daily' : 'weekly',
            interval: isDaily ? interval : (displayRecurrence === 'n_weekly' ? interval : 1),
            weekdays: isDaily ? null : weekdays,
            ends_at: neverEnds ? null : endDate || null,
        };
        if (masterId) payload.master_id = masterId;

        router.post('/admin/recurring-blocked-times', payload, {
            preserveScroll: true,
            onSuccess: () => { setDialogOpen(false); resetForm(); },
        });
    }

    function handleDeleteOneOff(id: string) {
        if (confirm('Удалить блокировку?')) {
            router.delete(`/admin/blocked-times/${id}`, { preserveScroll: true });
        }
    }

    function handleTogglePause(s: RecurringSeries) {
        router.patch(`/admin/recurring-blocked-times/${s.id}`, {
            status: s.status === 'active' ? 'paused' : 'active',
        }, { preserveScroll: true });
    }

    function handleDeleteRecurring(s: RecurringSeries) {
        if (confirm('Отменить серию блокировок?')) {
            router.delete(`/admin/recurring-blocked-times/${s.id}`, { preserveScroll: true });
        }
    }

    function toggleWeekday(day: number) {
        setWeekdays(prev =>
            prev.includes(day) ? prev.filter(d => d !== day) : [...prev, day].sort()
        );
    }

    // Merge both lists for display
    const hasItems = blockedTimes.length > 0 || recurringSeries.length > 0;

    return (
        <>
            <div className="flex items-center justify-between gap-4">
                <div>
                    <p className="text-[15px] font-semibold text-[var(--color-ink)]">
                        Недоступное время
                    </p>
                    <p className="text-[12px] text-[var(--color-graphite)]">
                        Блокировки отпуска, обедов и прочего
                    </p>
                </div>
                <Button
                    type="button"
                    className="h-9 shrink-0 rounded-[10px] bg-[var(--color-orange)] px-3.5 text-[12px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                    onClick={() => setDialogOpen(true)}
                >
                    <Plus className="size-3.5" />
                    Добавить
                </Button>
            </div>

            {!hasItems ? (
                <p className="py-4 text-center text-[13px] text-[var(--color-graphite)]">
                    Нет активных блокировок
                </p>
            ) : (
                <div className="mt-3 space-y-2">
                    {/* One-off blocked times */}
                    {blockedTimes.map((bt) => (
                        <div
                            key={`bt-${bt.id}`}
                            className="flex min-h-[54px] items-center justify-between rounded-[10px] bg-[var(--color-warm)] px-3 py-2.5"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-[14px] font-semibold text-[var(--color-ink)]">
                                    {bt.reason}
                                </p>
                                <p className="text-[12px] text-[var(--color-graphite)]">
                                    {formatBlockedTimeRange(bt.start_datetime, bt.end_datetime, timezone)}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => handleDeleteOneOff(bt.id)}
                                className="shrink-0 rounded-[8px] p-1.5 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-red-bg)] hover:text-[var(--color-red)]"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </div>
                    ))}

                    {/* Recurring series */}
                    {recurringSeries.map((s) => (
                        <div
                            key={`rs-${s.id}`}
                            className={`flex min-h-[54px] items-center justify-between rounded-[10px] px-3 py-2.5 ${
                                s.status === 'paused'
                                    ? 'bg-[var(--color-surface)] opacity-60'
                                    : 'bg-[var(--color-warm)]'
                            }`}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-[14px] font-semibold text-[var(--color-ink)] flex items-center gap-1.5">
                                    {s.title}
                                    <Repeat className="size-3 text-[var(--color-graphite)]" />
                                    {s.status === 'paused' && (
                                        <span className="text-[11px] font-normal text-[var(--color-graphite)]">(пауза)</span>
                                    )}
                                </p>
                                <p className="text-[12px] text-[var(--color-graphite)]">
                                    {describeRecurrence(s)}
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    onClick={() => handleTogglePause(s)}
                                    className="rounded-[8px] p-1.5 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)]"
                                    title={s.status === 'active' ? 'Пауза' : 'Возобновить'}
                                >
                                    {s.status === 'active' ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleDeleteRecurring(s)}
                                    className="rounded-[8px] p-1.5 text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-red-bg)] hover:text-[var(--color-red)]"
                                    title="Отменить серию"
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* ── Unified creation dialog ── */}
            <Dialog open={dialogOpen} onOpenChange={(open) => { setDialogOpen(open); if (!open) resetForm(); }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Новое недоступное время</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4 max-h-[60vh] overflow-y-auto">
                        {/* Title / reason */}
                        <div>
                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                Название
                            </label>
                            <input
                                type="text"
                                value={title}
                                onChange={e => setTitle(e.target.value)}
                                placeholder="Обед, Забрать ребёнка…"
                                className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                            />
                        </div>

                        {/* Date + time */}
                        {!repeat ? (
                            <>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                            С
                                        </label>
                                        <input
                                            type="datetime-local"
                                            value={startDate}
                                            onChange={e => setStartDate(e.target.value)}
                                            className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                            По
                                        </label>
                                        <input
                                            type="datetime-local"
                                            value={endDate}
                                            onChange={e => setEndDate(e.target.value)}
                                            className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                        Причина
                                    </label>
                                    <Select value={reason} onValueChange={setReason}>
                                        <SelectTrigger className="h-[42px] w-full rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px]">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {BLOCKED_REASONS.map(r => (
                                                <SelectItem key={r} value={r}>{r}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </>
                        ) : (
                            <>
                                <div>
                                    <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                        Дата начала
                                    </label>
                                    <input
                                        type="date"
                                        value={startDate}
                                        onChange={e => setStartDate(e.target.value)}
                                        className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                            Время с
                                        </label>
                                        <input
                                            type="time"
                                            value={startTime}
                                            onChange={e => setStartTime(e.target.value)}
                                            className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                            Время по
                                        </label>
                                        <input
                                            type="time"
                                            value={endTime}
                                            onChange={e => setEndTime(e.target.value)}
                                            className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                        />
                                    </div>
                                </div>

                                {/* Recurrence type */}
                                <div>
                                    <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                        Повтор
                                    </label>
                                    <Select value={displayRecurrence} onValueChange={(v) => {
                                        const val = v as DisplayRecurrence;
                                        setDisplayRecurrence(val);
                                        if (val === 'weekly') setInterval(1);
                                        if (val === 'n_weekly' && interval < 2) setInterval(2);
                                    }}>
                                        <SelectTrigger className="h-[42px] w-full rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px]">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="daily">Каждый день</SelectItem>
                                            <SelectItem value="weekly">Каждую неделю</SelectItem>
                                            <SelectItem value="n_weekly">Каждые N недель</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {displayRecurrence === 'n_weekly' && (
                                    <div>
                                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                            Каждые N недель
                                        </label>
                                        <input
                                            type="number"
                                            min={2}
                                            max={12}
                                            value={interval}
                                            onChange={e => setInterval(Math.max(2, parseInt(e.target.value) || 2))}
                                            className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                        />
                                    </div>
                                )}

                                {(displayRecurrence === 'weekly' || displayRecurrence === 'n_weekly') && (
                                    <div>
                                        <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                            Дни недели
                                        </label>
                                        <div className="flex gap-1.5">
                                            {WEEKDAY_NAMES.map(day => (
                                                <button
                                                    key={day.value}
                                                    type="button"
                                                    onClick={() => toggleWeekday(day.value)}
                                                    className={`flex h-9 w-9 items-center justify-center rounded-[8px] text-[12px] font-medium transition-colors ${
                                                        weekdays.includes(day.value)
                                                            ? 'bg-[var(--color-orange)] text-white'
                                                            : 'bg-[var(--color-surface)] text-[var(--color-graphite)] border border-[var(--color-line)]'
                                                    }`}
                                                >
                                                    {day.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div>
                                    <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                        Окончание
                                    </label>
                                    <div className="flex items-center gap-3">
                                        <label className="flex items-center gap-1.5 text-[13px] text-[var(--color-ink)]">
                                            <input type="radio" checked={neverEnds} onChange={() => setNeverEnds(true)}
                                                className="accent-[var(--color-orange)]" />
                                            Никогда
                                        </label>
                                        <label className="flex items-center gap-1.5 text-[13px] text-[var(--color-ink)]">
                                            <input type="radio" checked={!neverEnds} onChange={() => setNeverEnds(false)}
                                                className="accent-[var(--color-orange)]" />
                                            До даты
                                        </label>
                                    </div>
                                    {!neverEnds && (
                                        <input
                                            type="date"
                                            value={endDate}
                                            onChange={e => setEndDate(e.target.value)}
                                            className="mt-2 h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                        />
                                    )}
                                </div>
                            </>
                        )}

                        {/* ── Repeat toggle ── */}
                        <div className="flex items-center justify-between rounded-[10px] border border-[var(--color-line)] px-3 py-3">
                            <label className="flex items-center gap-2 text-[13px] font-medium text-[var(--color-ink)]">
                                <Repeat className="size-4" />
                                Повторять
                            </label>
                            {hasRecurringFeature ? (
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={repeat}
                                    onClick={() => {
                                        setRepeat(!repeat);
                                        if (!repeat) {
                                            // Switching to recurring: set sensible defaults
                                            setTitle(title || reason || '');
                                        }
                                    }}
                                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                                        repeat ? 'bg-[var(--color-orange)]' : 'bg-[var(--color-line)]'
                                    }`}
                                >
                                    <span className={`inline-block h-4 w-4 rounded-full bg-white transition-transform ${
                                        repeat ? 'translate-x-6' : 'translate-x-1'
                                    }`} />
                                </button>
                            ) : (
                                <span className="flex items-center gap-1 text-[12px] text-[var(--color-graphite)]">
                                    <Lock className="size-3" />
                                    Профи
                                </span>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-10 rounded-[10px] border-[var(--color-line)] px-4 text-[13px] font-semibold text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]"
                            onClick={() => { setDialogOpen(false); resetForm(); }}
                        >
                            Отмена
                        </Button>
                        <Button
                            type="button"
                            onClick={handleSubmit}
                            disabled={
                                repeat
                                    ? !title || !startDate || !startTime || !endTime
                                    : !startDate || !endDate
                            }
                            className="h-10 rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                        >
                            Добавить
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}