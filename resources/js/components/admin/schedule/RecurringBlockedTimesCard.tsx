import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2, Repeat, Pause, Play, Lock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

interface RecurringException {
    id: string;
    occurrence_date: string;
    type: 'skip' | 'override';
    override_start_time: string | null;
    override_end_time: string | null;
}

export interface RecurringSeries {
    id: string;
    title: string;
    reason: string | null;
    start_date: string;
    start_time: string;
    end_time: string;
    recurrence_type: 'daily' | 'weekly' | 'custom_weekly';
    interval: number;
    weekdays: number[] | null;
    ends_at: string | null;
    timezone: string;
    status: 'active' | 'paused' | 'cancelled';
    exceptions: RecurringException[];
}

const RECURRENCE_LABELS: Record<string, string> = {
    daily: 'Каждый день',
    weekly: 'Каждую неделю',
    custom_weekly: 'Каждые N недель',
};

const WEEKDAY_NAMES = [
    { value: 1, label: 'Пн' },
    { value: 2, label: 'Вт' },
    { value: 3, label: 'Ср' },
    { value: 4, label: 'Чт' },
    { value: 5, label: 'Пт' },
    { value: 6, label: 'Сб' },
    { value: 7, label: 'Вс' },
];

function describeRecurrence(series: RecurringSeries): string {
    const parts: string[] = [];

    if (series.recurrence_type === 'daily') {
        parts.push(series.interval === 1 ? 'Каждый день' : `Каждые ${series.interval} дня`);
    } else if (series.recurrence_type === 'weekly') {
        parts.push(series.interval === 1 ? 'Каждую неделю' : `Каждые ${series.interval} недели`);
    } else if (series.recurrence_type === 'custom_weekly') {
        const dayLabels = (series.weekdays || []).map(d => WEEKDAY_NAMES.find(w => w.value === d)?.label || '').filter(Boolean);
        parts.push(series.interval === 1 ? 'Каждую неделю' : `Каждые ${series.interval} недели`);
        if (dayLabels.length > 0) {
            parts.push(dayLabels.join(', '));
        }
    }

    parts.push(`${series.start_time}–${series.end_time}`);

    if (series.ends_at) {
        parts.push(`до ${new Date(series.ends_at).toLocaleDateString('ru-RU')}`);
    } else {
        parts.push('бессрочно');
    }

    return parts.join(' · ');
}

export default function RecurringBlockedTimesCard({ masterId }: { masterId?: string }) {
    const { recurringSeries: rawSeries, hasRecurringFeature } = usePage<{
        recurringSeries: RecurringSeries[];
        hasRecurringFeature: boolean;
    }>().props;

    const series = rawSeries || [];
    const [dialogOpen, setDialogOpen] = useState(false);

    // Form state
    const [title, setTitle] = useState('');
    const [reason, setReason] = useState('');
    const [recurrenceType, setRecurrenceType] = useState<'daily' | 'weekly' | 'custom_weekly'>('weekly');
    const [interval, setInterval] = useState(1);
    const [weekdays, setWeekdays] = useState<number[]>([1, 2, 3, 4, 5]);
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [startTime, setStartTime] = useState('09:00');
    const [endTime, setEndTime] = useState('10:00');
    const [neverEnds, setNeverEnds] = useState(true);

    function resetForm() {
        setTitle('');
        setReason('');
        setRecurrenceType('weekly');
        setInterval(1);
        setWeekdays([1, 2, 3, 4, 5]);
        setStartDate('');
        setEndDate('');
        setStartTime('09:00');
        setEndTime('10:00');
        setNeverEnds(true);
    }

    function handleAdd() {
        if (!title || !startDate || !startTime || !endTime) return;

        const payload: Record<string, unknown> = {
            title,
            reason: reason || null,
            start_date: startDate,
            start_time: startTime,
            end_time: endTime,
            recurrence_type: recurrenceType,
            interval,
            weekdays: recurrenceType === 'custom_weekly' ? weekdays : null,
            ends_at: neverEnds ? null : endDate || null,
        };

        if (masterId) {
            payload.master_id = masterId;
        }

        router.post('/admin/recurring-blocked-times', payload, {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                resetForm();
            },
        });
    }

    function handleTogglePause(seriesItem: RecurringSeries) {
        const newStatus = seriesItem.status === 'active' ? 'paused' : 'active';
        router.patch(`/admin/recurring-blocked-times/${seriesItem.id}`, {
            status: newStatus,
        }, { preserveScroll: true });
    }

    function handleDelete(seriesItem: RecurringSeries) {
        if (confirm('Отменить серию блокировок?')) {
            router.delete(`/admin/recurring-blocked-times/${seriesItem.id}`, {
                preserveScroll: true,
            });
        }
    }

    function toggleWeekday(day: number) {
        setWeekdays(prev =>
            prev.includes(day)
                ? prev.filter(d => d !== day)
                : [...prev, day].sort()
        );
    }

    if (!hasRecurringFeature) {
        return (
            <div className="flex items-center justify-between gap-4">
                <div>
                    <p className="text-[15px] font-semibold text-[var(--color-ink)] flex items-center gap-2">
                        <Repeat className="size-4" />
                        Повторяющиеся блокировки
                    </p>
                    <p className="text-[12px] text-[var(--color-graphite)]">
                        Регулярное недоступное время
                    </p>
                </div>
                <div className="flex items-center gap-1.5 rounded-[10px] bg-[var(--color-warm)] px-3 py-2 text-[12px] text-[var(--color-graphite)]">
                    <Lock className="size-3" />
                    Профи
                </div>
            </div>
        );
    }

    const activeSeries = series.filter(s => s.status !== 'cancelled');

    return (
        <>
            <div className="flex items-center justify-between gap-4">
                <div>
                    <p className="text-[15px] font-semibold text-[var(--color-ink)] flex items-center gap-2">
                        <Repeat className="size-4" />
                        Повторяющиеся блокировки
                    </p>
                    <p className="text-[12px] text-[var(--color-graphite)]">
                        Регулярное недоступное время
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

            {activeSeries.length === 0 ? (
                <p className="py-4 text-center text-[13px] text-[var(--color-graphite)]">
                    Нет повторяющихся блокировок
                </p>
            ) : (
                <div className="mt-3 space-y-2">
                    {activeSeries.map((s) => (
                        <div
                            key={s.id}
                            className={`flex min-h-[54px] items-center justify-between rounded-[10px] px-3 py-2.5 ${
                                s.status === 'paused'
                                    ? 'bg-[var(--color-surface)] opacity-60'
                                    : 'bg-[var(--color-warm)]'
                            }`}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-[14px] font-semibold text-[var(--color-ink)] flex items-center gap-1.5">
                                    {s.title}
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
                                    onClick={() => handleDelete(s)}
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

            <Dialog open={dialogOpen} onOpenChange={(open) => { setDialogOpen(open); if (!open) resetForm(); }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Повторяющаяся блокировка</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4 max-h-[60vh] overflow-y-auto">
                        <div>
                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                Название
                            </label>
                            <input
                                type="text"
                                value={title}
                                onChange={e => setTitle(e.target.value)}
                                placeholder="Забрать ребёнка"
                                className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                            />
                        </div>

                        <div>
                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                Повтор
                            </label>
                            <Select value={recurrenceType} onValueChange={(v) => setRecurrenceType(v as typeof recurrenceType)}>
                                <SelectTrigger className="h-[42px] w-full rounded-[10px] border-[var(--color-line)] bg-[var(--color-surface)] text-[13px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="daily">Каждый день</SelectItem>
                                    <SelectItem value="weekly">Каждую неделю</SelectItem>
                                    <SelectItem value="custom_weekly">Каждые N недель</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {recurrenceType === 'custom_weekly' && (
                            <div>
                                <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                    Интервал (каждые N недель)
                                </label>
                                <input
                                    type="number"
                                    min={1}
                                    max={12}
                                    value={interval}
                                    onChange={e => setInterval(Math.max(1, parseInt(e.target.value) || 1))}
                                    className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                                />
                            </div>
                        )}

                        {(recurrenceType === 'weekly' || recurrenceType === 'custom_weekly') && (
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
                                Начало
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

                        <div>
                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                Окончание
                            </label>
                            <div className="flex items-center gap-3">
                                <label className="flex items-center gap-1.5 text-[13px] text-[var(--color-ink)]">
                                    <input
                                        type="radio"
                                        checked={neverEnds}
                                        onChange={() => setNeverEnds(true)}
                                        className="accent-[var(--color-orange)]"
                                    />
                                    Никогда
                                </label>
                                <label className="flex items-center gap-1.5 text-[13px] text-[var(--color-ink)]">
                                    <input
                                        type="radio"
                                        checked={!neverEnds}
                                        onChange={() => setNeverEnds(false)}
                                        className="accent-[var(--color-orange)]"
                                    />
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

                        <div>
                            <label className="mb-1.5 block text-[13px] font-medium text-[var(--color-ink)]">
                                Причина (необязательно)
                            </label>
                            <input
                                type="text"
                                value={reason}
                                onChange={e => setReason(e.target.value)}
                                placeholder="Личные дела"
                                className="h-[42px] w-full rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 text-[13px] text-[var(--color-ink)]"
                            />
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
                            onClick={handleAdd}
                            disabled={!title || !startDate || !startTime || !endTime}
                            className="h-10 rounded-[10px] bg-[var(--color-orange)] px-5 text-[13px] font-semibold text-white hover:bg-[var(--color-orange-600)]"
                        >
                            Создать
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
