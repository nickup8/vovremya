import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Drawer, DrawerContent, DrawerHeader, DrawerTitle, DrawerDescription, DrawerBody, DrawerFooter,
} from '@/components/ui/drawer';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { RecurrenceConfig, PreviewResult } from './RecurrenceSection';

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

const WEEKDAY_OPTIONS = [
    { value: 1, label: 'Пн' },
    { value: 2, label: 'Вт' },
    { value: 3, label: 'Ср' },
    { value: 4, label: 'Чт' },
    { value: 5, label: 'Пт' },
    { value: 6, label: 'Сб' },
    { value: 7, label: 'Вс' },
];

export function RecurrenceFromExistingDialog({
    open, onOpenChange, appointmentInfo, isProcessing,
    previewLoading, previewResult, onPreview, onSubmit,
    recurrence, onRecurrenceChange,
}: Props) {
    function toggleWeekday(day: number) {
        const current = recurrence.weekdays;
        const next = current.includes(day)
            ? current.filter((d) => d !== day)
            : [...current, day].sort();
        onRecurrenceChange({ ...recurrence, weekdays: next });
    }

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
                        {/* Frequency */}
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Частота</label>
                            <Select
                                value={String(recurrence.interval)}
                                onValueChange={(v) => onRecurrenceChange({ ...recurrence, interval: parseInt(v) })}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1">Каждую неделю</SelectItem>
                                    <SelectItem value="2">Каждые 2 недели</SelectItem>
                                    <SelectItem value="3">Каждые 3 недели</SelectItem>
                                    <SelectItem value="4">Каждые 4 недели</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Weekdays */}
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

                        {/* End condition */}
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Окончание</label>
                            <div className="flex gap-2">
                                <Select
                                    value={recurrence.end_type}
                                    onValueChange={(v: 'count' | 'date') => onRecurrenceChange({ ...recurrence, end_type: v })}
                                >
                                    <SelectTrigger className="w-40">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="count">После N записей</SelectItem>
                                        <SelectItem value="date">До даты</SelectItem>
                                    </SelectContent>
                                </Select>

                                {recurrence.end_type === 'count' ? (
                                    <input
                                        type="number"
                                        min={2}
                                        max={100}
                                        value={recurrence.occurrences_count}
                                        onChange={(e) => onRecurrenceChange({ ...recurrence, occurrences_count: parseInt(e.target.value) || 10 })}
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
                            disabled={previewLoading || recurrence.weekdays.length === 0}
                            className="w-full"
                        >
                            {previewLoading ? 'Проверка...' : 'Проверить доступность'}
                        </Button>

                        {previewResult && (
                            <div className="rounded-lg bg-white p-2.5 text-sm dark:bg-zinc-800">
                                <p className="font-medium text-slate-700 dark:text-zinc-200">
                                    {previewResult.total} записей: {previewResult.available} доступны
                                    {previewResult.conflicts.length > 0 && (
                                        <span className="text-red-500">, {previewResult.conflicts.length} конфликтуют</span>
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
