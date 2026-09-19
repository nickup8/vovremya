import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerTitle, DrawerDescription, DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { RecurrenceConfig, PreviewResult } from './RecurrenceSection';
import { WEEKDAY_OPTIONS } from './RecurrenceSection';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    appointmentInfo: { service: string; time: string; date: string; client_name: string } | null;
    isProcessing: boolean;
    previewLoading: boolean;
    previewResult: PreviewResult | null;
    onPreview: () => void;
    onSubmit: () => void;
    recurrence: RecurrenceConfig;
    onRecurrenceChange: (config: RecurrenceConfig) => void;
}

function isoWeekdayFromDate(dateStr: string): number {
    const d = new Date(dateStr + 'T00:00:00');
    const jsDay = d.getDay();
    return jsDay === 0 ? 7 : jsDay;
}

export function RecurrenceFromExistingDialog({
    open, onOpenChange, appointmentInfo, isProcessing,
    previewLoading, previewResult, onPreview, onSubmit,
    recurrence, onRecurrenceChange,
}: Props) {
    const weekdayInitialized = useRef(false);

    // Auto-select weekday of current appointment on first open
    useEffect(() => {
        if (open && appointmentInfo && !weekdayInitialized.current) {
            const currentWeekday = isoWeekdayFromDate(appointmentInfo.date);
            onRecurrenceChange({
                ...recurrence,
                recurrence_type: 'weekly',
                weekdays: [currentWeekday],
            });
            weekdayInitialized.current = true;
        }
        if (!open) {
            weekdayInitialized.current = false;
        }
    }, [open, appointmentInfo]);

    function toggleWeekday(day: number) {
        const current = recurrence.weekdays;
        const next = current.includes(day)
            ? current.filter((d) => d !== day)
            : [...current, day].sort();
        onRecurrenceChange({ ...recurrence, weekdays: next });
    }

    const futureTotal = previewResult ? (previewResult.total - (previewResult.current_date ? 1 : 0)) : 0;

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent>
                <DrawerHeader className="border-b border-[var(--color-line)] dark:border-[var(--color-cal-border)]">
                    <DrawerTitle className="text-[var(--color-ink)]">
                        Повторять запись
                    </DrawerTitle>
                    <DrawerDescription className="text-[var(--color-graphite)]">
                        {appointmentInfo && (
                            <>Создать серию на основе: {appointmentInfo.service}, {appointmentInfo.date} {appointmentInfo.time}</>
                        )}
                    </DrawerDescription>
                </DrawerHeader>

                <DrawerBody className="px-[22px] py-[22px]">
                    <div className="space-y-3">
                        {/* Frequency: Повторять каждые [ N ] [ дни / недели ] */}
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Частота</label>
                            <div className="flex items-center gap-2">
                                <span className="text-sm text-slate-600 dark:text-zinc-400">Каждые</span>
                                <input
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={recurrence.interval}
                                    onChange={(e) => onRecurrenceChange({ ...recurrence, interval: Math.max(1, parseInt(e.target.value) || 1) })}
                                    className="w-16 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                />
                                <Select
                                    value={recurrence.recurrence_type}
                                    onValueChange={(v: 'daily' | 'weekly') => {
                                        const next = { ...recurrence, recurrence_type: v };
                                        if (v === 'daily') next.weekdays = [];
                                        onRecurrenceChange(next);
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
                                            onClick={() => toggleWeekday(day.value)}
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

                        {/* End condition */}
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Окончание</label>
                            <div className="flex gap-2">
                                <Select
                                    value={recurrence.end_type}
                                    onValueChange={(v: 'count' | 'date') => onRecurrenceChange({ ...recurrence, end_type: v })}
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
                                        min={1}
                                        max={100}
                                        value={recurrence.occurrences_count}
                                        onChange={(e) => onRecurrenceChange({ ...recurrence, occurrences_count: Math.max(1, parseInt(e.target.value) || 10) })}
                                        className="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                ) : (
                                    <input
                                        type="date"
                                        value={recurrence.ends_at}
                                        onChange={(e) => onRecurrenceChange({ ...recurrence, ends_at: e.target.value })}
                                        className="flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                )}
                            </div>
                        </div>

                        {/* Preview */}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={onPreview}
                            disabled={previewLoading || (recurrence.recurrence_type === 'weekly' && recurrence.weekdays.length === 0)}
                            className="w-full"
                        >
                            {previewLoading ? 'Проверка...' : 'Проверить доступность'}
                        </Button>

                        {previewResult && (
                            <div className="rounded-lg bg-white p-2.5 text-sm dark:bg-zinc-800">
                                <p className="font-medium text-slate-700 dark:text-zinc-200">
                                    Серия: {previewResult.total} записей
                                </p>
                                <p className="text-slate-600 dark:text-zinc-300">
                                    {previewResult.current_date
                                        ? <>Текущая запись + {futureTotal} будущих</>
                                        : <>{previewResult.total} будущих</>
                                    }
                                </p>
                                <p className="text-slate-600 dark:text-zinc-300">
                                    {previewResult.available} свободны
                                    {previewResult.conflicts.length > 0 && (
                                        <span className="text-red-500"> · {previewResult.conflicts.length} конфликт</span>
                                    )}
                                </p>
                                {previewResult.conflicts.length > 0 && (
                                    <p className="mt-1 text-xs text-red-400">
                                        Конфликты: {previewResult.conflicts.map((c) => c.date).join(', ')}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </DrawerBody>

                <DrawerFooter>
                    <div className="flex gap-3">
                        <Button
                            onClick={() => onOpenChange(false)}
                            disabled={isProcessing}
                            variant="outline"
                            className="flex-1 rounded-lg"
                        >
                            Отмена
                        </Button>
                        <Button
                            onClick={onSubmit}
                            disabled={isProcessing || !previewResult || previewResult.available === 0}
                            className="flex-1 rounded-lg bg-[var(--color-orange)] text-white hover:bg-[var(--color-orange-600)]"
                        >
                            {isProcessing ? 'Создание...' : `Создать серию (${previewResult?.available ?? 0})`}
                        </Button>
                    </div>
                </DrawerFooter>
            </DrawerContent>
        </Drawer>
    );
}
