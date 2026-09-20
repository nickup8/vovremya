import { useState, useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import type { RecurrenceConfig, PreviewResult, ConflictReason } from './RecurrenceSection';
import { WEEKDAY_OPTIONS, CONFLICT_LABELS } from './RecurrenceSection';
import type { Appointment, ServiceOption } from './types';
import { useAvailableSlots } from '@/hooks/useAvailableSlots';
import { IrsiTimeSelect } from './IrsiTimeSelect';

interface SeriesData {
    start_time: string;
    recurrence_type: string;
    interval: number;
    weekdays: number[] | null;
    ends_at: string | null;
    occurrences_count: number | null;
    master_service_id: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: 'this-and-future' | 'series-settings';
    appointment: Appointment | null;
    services: ServiceOption[];
    isProcessing: boolean;
    onPreview: (params: PreviewParams) => Promise<PreviewResult | null>;
    onSubmit: (params: SubmitParams) => Promise<void>;
}

export interface PreviewParams {
    service_id: string;
    time: string;
    recurrence_type: string;
    interval: number;
    weekdays: number[] | null;
    ends_at: string | null;
    occurrences_count: number | null;
}

export interface SubmitParams extends PreviewParams {
    allowed_dates: string[];
}

function buildConfigFromSeries(series: SeriesData): {
    time: string;
    serviceId: string;
    recurrence: RecurrenceConfig;
} {
    const hasEnd = series.occurrences_count != null;

    return {
        time: series.start_time,
        serviceId: series.master_service_id,
        recurrence: {
            enabled: true,
            recurrence_type: series.recurrence_type as 'daily' | 'weekly',
            interval: series.interval,
            weekdays: series.weekdays ?? [],
            end_type: hasEnd ? 'count' : 'date',
            occurrences_count: series.occurrences_count ?? 10,
            ends_at: series.ends_at ?? '',
        },
    };
}

export function RecurringEditDialog({
    open, onOpenChange, mode, appointment, services,
    isProcessing, onPreview, onSubmit,
}: Props) {
    const series = appointment?.recurring_series;
    const initRef = useRef(false);

    const [time, setTime] = useState('');
    const [serviceId, setServiceId] = useState('');
    const [recurrence, setRecurrence] = useState<RecurrenceConfig>({
        enabled: true,
        recurrence_type: 'weekly',
        interval: 1,
        weekdays: [],
        end_type: 'count',
        occurrences_count: 10,
        ends_at: '',
    });
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewResult, setPreviewResult] = useState<PreviewResult | null>(null);

    // Resolve date for availability: use appointment's occurrence date
    const slotDate = appointment?.recurring_occurrence_date ?? appointment?.date ?? '';

    // Shared availability from the same backend endpoint as New Appointment
    const { slots, loading: slotsLoading } = useAvailableSlots(
        open ? slotDate : '',
        open ? serviceId || undefined : undefined,
    );

    // Initialize from series params on first open
    useEffect(() => {
        if (open && series && !initRef.current) {
            const cfg = buildConfigFromSeries(series);
            setTime(cfg.time);
            setServiceId(cfg.serviceId);
            setRecurrence(cfg.recurrence);
            initRef.current = true;
            setPreviewResult(null);
        }
        if (!open) {
            initRef.current = false;
        }
    }, [open, series]);

    // Clear time if no longer in available slots after service/date change
    useEffect(() => {
        if (slotsLoading) return;
        const allSlots = [...slots.freeSlots, ...slots.outsideSlots];
        if (time && allSlots.length > 0 && !allSlots.includes(time)) {
            setTime('');
        }
    }, [slots, slotsLoading, time]);

    const timeGroups = [
        { label: 'Свободное время', options: slots.freeSlots },
        { label: 'Вне рабочего времени', options: slots.outsideSlots },
    ];

    async function handlePreview() {
        if (!serviceId || recurrence.weekdays.length === 0) return;
        setPreviewLoading(true);
        setPreviewResult(null);
        try {
            const result = await onPreview({
                service_id: serviceId,
                time,
                recurrence_type: recurrence.recurrence_type,
                interval: recurrence.interval,
                weekdays: recurrence.recurrence_type === 'weekly' ? recurrence.weekdays : null,
                // this-and-future: let backend auto-compute remaining count
                ends_at: mode === 'series-settings' && recurrence.end_type === 'date' ? recurrence.ends_at : null,
                occurrences_count: mode === 'series-settings' && recurrence.end_type === 'count' ? recurrence.occurrences_count : null,
            });
            if (result) setPreviewResult(result);
        } finally {
            setPreviewLoading(false);
        }
    }

    async function handleSubmit() {
        if (!previewResult || previewResult.available === 0) return;
        if ('error' in previewResult && previewResult.error) return;
        await onSubmit({
            service_id: serviceId,
            time,
            recurrence_type: recurrence.recurrence_type,
            interval: recurrence.interval,
            weekdays: recurrence.recurrence_type === 'weekly' ? recurrence.weekdays : null,
            ends_at: mode === 'series-settings' && recurrence.end_type === 'date' ? recurrence.ends_at : null,
            occurrences_count: mode === 'series-settings' && recurrence.end_type === 'count' ? recurrence.occurrences_count : null,
            allowed_dates: previewResult.dates,
        });
    }

    const title = mode === 'this-and-future'
        ? 'Изменить эту и следующие'
        : 'Настройки серии';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    {/* Time — IrsiTimeSelect with availability groups */}
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700 dark:text-zinc-300">Время</label>
                        <IrsiTimeSelect
                            value={time}
                            onChange={setTime}
                            groups={timeGroups}
                            disabled={slotsLoading}
                            placeholder={slotsLoading ? 'Загрузка...' : 'ЧЧ:ММ'}
                        />
                    </div>

                    {/* Service */}
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700 dark:text-zinc-300">Услуга</label>
                        <Select value={serviceId} onValueChange={setServiceId}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Выберите услугу" />
                            </SelectTrigger>
                            <SelectContent>
                                {services.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.title} — {s.duration_minutes} мин
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Frequency */}
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Частота</label>
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-slate-600 dark:text-zinc-400">Каждые</span>
                            <input
                                type="number"
                                min={1}
                                max={100}
                                value={recurrence.interval}
                                onChange={(e) => setRecurrence({ ...recurrence, interval: Math.max(1, parseInt(e.target.value) || 1) })}
                                className="w-16 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                            />
                            <Select
                                value={recurrence.recurrence_type}
                                onValueChange={(v: 'daily' | 'weekly') => {
                                    const next = { ...recurrence, recurrence_type: v };
                                    if (v === 'daily') next.weekdays = [];
                                    setRecurrence(next);
                                }}
                            >
                                <SelectTrigger className="w-28">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="daily">{recurrence.interval === 1 ? 'день' : (recurrence.interval >= 2 && recurrence.interval <= 4 ? 'дня' : 'дней')}</SelectItem>
                                    <SelectItem value="weekly">{recurrence.interval === 1 ? 'неделю' : (recurrence.interval >= 2 && recurrence.interval <= 4 ? 'недели' : 'недель')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* Weekdays — only for weekly */}
                    {recurrence.recurrence_type === 'weekly' && (
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Дни недели</label>
                            <div className="flex gap-1.5">
                                {WEEKDAY_OPTIONS.map((day) => (
                                    <button
                                        key={day.value}
                                        type="button"
                                        onClick={() => {
                                            const current = recurrence.weekdays;
                                            const next = current.includes(day.value)
                                                ? current.filter((d) => d !== day.value)
                                                : [...current, day.value].sort();
                                            setRecurrence({ ...recurrence, weekdays: next });
                                        }}
                                        className={`flex size-8 items-center justify-center rounded-lg text-xs font-medium transition-colors ${
                                            recurrence.weekdays.includes(day.value)
                                                ? 'bg-[var(--color-orange)] text-white'
                                                : 'bg-white text-slate-600 hover:bg-slate-100 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600'
                                        }`}
                                    >
                                        {day.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* End condition — hidden for this-and-future (backend auto-computes remaining) */}
                    {mode === 'series-settings' && (
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Окончание</label>
                            <div className="flex gap-2">
                                <Select
                                    value={recurrence.end_type}
                                    onValueChange={(v: 'count' | 'date') => setRecurrence({ ...recurrence, end_type: v })}
                                >
                                    <SelectTrigger className="w-44">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="count">Количество записей</SelectItem>
                                        <SelectItem value="date">До даты</SelectItem>
                                    </SelectContent>
                                </Select>
                                {recurrence.end_type === 'count' ? (
                                    <input
                                        type="number"
                                        min={2}
                                        max={100}
                                        value={recurrence.occurrences_count}
                                        onChange={(e) => setRecurrence({ ...recurrence, occurrences_count: Math.max(2, parseInt(e.target.value) || 2) })}
                                        className="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                ) : (
                                    <input
                                        type="date"
                                        value={recurrence.ends_at}
                                        onChange={(e) => setRecurrence({ ...recurrence, ends_at: e.target.value })}
                                        className="flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                )}
                            </div>
                        </div>
                    )}

                    {/* Remaining count indicator — shown for this-and-future when preview returns it */}
                    {mode === 'this-and-future' && previewResult && 'remaining_count' in previewResult && (
                        <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                            Оставшиеся записи серии: {previewResult.remaining_count}
                        </div>
                    )}

                    {/* Preview button */}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handlePreview}
                        disabled={previewLoading || !serviceId || (recurrence.recurrence_type === 'weekly' && recurrence.weekdays.length === 0)}
                        className="w-full"
                    >
                        {previewLoading ? 'Проверка...' : 'Проверить расписание'}
                    </Button>

                    {/* Preview result */}
                    {previewResult && (
                        <div className="rounded-lg bg-white p-2.5 text-sm dark:bg-zinc-800">
                            {'error' in previewResult && previewResult.error && (
                                <p className="font-medium text-red-500">
                                    {String(previewResult.error)}
                                </p>
                            )}
                            {'has_paid_conflict' in previewResult && previewResult.has_paid_conflict && (
                                <p className="font-medium text-red-500">
                                    В серии есть оплаченная запись, которую нельзя изменить автоматически.
                                </p>
                            )}
                            <p className="font-medium text-slate-700 dark:text-zinc-200">
                                Будет изменено: {previewResult.total} · Свободно: {previewResult.available}
                                {previewResult.conflicts.length > 0 && (
                                    <span className="text-red-500"> · Конфликтов: {previewResult.conflicts.length}</span>
                                )}
                            </p>
                            {previewResult.conflicts.length > 0 && (
                                <div className="mt-1 space-y-0.5 text-xs text-red-400">
                                    {previewResult.conflicts.map((c) => (
                                        <p key={c.date}>
                                            {c.date.split('-').reverse().join('.')} · {time} — {CONFLICT_LABELS[c.reason as ConflictReason] ?? CONFLICT_LABELS.conflict}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isProcessing}>
                        Отмена
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={isProcessing || !previewResult || previewResult.available === 0 || ('has_paid_conflict' in previewResult && !!previewResult.has_paid_conflict) || ('error' in previewResult && !!previewResult.error)}
                        className="bg-[var(--color-orange)] text-white hover:bg-[var(--color-orange-600)]"
                    >
                        {isProcessing ? 'Сохранение...' : 'Сохранить изменения'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
