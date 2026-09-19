import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export const WEEKDAY_OPTIONS = [
    { value: 1, label: 'Пн' },
    { value: 2, label: 'Вт' },
    { value: 3, label: 'Ср' },
    { value: 4, label: 'Чт' },
    { value: 5, label: 'Пт' },
    { value: 6, label: 'Сб' },
    { value: 7, label: 'Вс' },
];

export interface RecurrenceConfig {
    enabled: boolean;
    recurrence_type: 'daily' | 'weekly';
    interval: number;
    weekdays: number[];
    end_type: 'count' | 'date';
    occurrences_count: number;
    ends_at: string;
}

export const DEFAULT_RECURRENCE: RecurrenceConfig = {
    enabled: false,
    recurrence_type: 'weekly',
    interval: 1,
    weekdays: [],
    end_type: 'count',
    occurrences_count: 10,
    ends_at: '',
};

export type ConflictReason = 'past' | 'outside_hours' | 'break' | 'booked' | 'blocked' | 'conflict';

export const CONFLICT_LABELS: Record<ConflictReason, string> = {
    past: 'Дата уже прошла',
    outside_hours: 'Вне рабочего времени',
    break: 'Перерыв',
    booked: 'Время занято другой записью',
    blocked: 'Время заблокировано',
    conflict: 'Время недоступно',
};

export interface PreviewResult {
    total: number;
    available: number;
    conflicts: Array<{ date: string; reason: ConflictReason }>;
    dates: string[];
    current_date?: string;
}

interface Props {
    value: RecurrenceConfig;
    onChange: (config: RecurrenceConfig) => void;
    isPro: boolean;
    previewLoading: boolean;
    previewResult: PreviewResult | null;
    onPreview: () => void;
    startTime?: string;
}

export function RecurrenceSection({ value, onChange, isPro, previewLoading, previewResult, onPreview, startTime }: Props) {
    const [showConfig, setShowConfig] = useState(value.enabled);

    function toggleEnabled() {
        if (!isPro) return;
        const next = !showConfig;
        setShowConfig(next);
        onChange({ ...value, enabled: next });
    }

    function toggleWeekday(day: number) {
        const current = value.weekdays;
        const next = current.includes(day)
            ? current.filter((d) => d !== day)
            : [...current, day].sort();
        onChange({ ...value, weekdays: next });
    }

    return (
        <div className="space-y-3">
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={showConfig}
                    onChange={toggleEnabled}
                    disabled={!isPro}
                    className="size-4 rounded border-slate-300 text-[var(--color-orange)] focus:ring-[var(--color-orange)]"
                />
                <span className="text-sm font-medium text-slate-700 dark:text-zinc-300">
                    Повторять
                    {!isPro && (
                        <span className="ml-1.5 text-xs text-slate-400">Профи 🔒</span>
                    )}
                </span>
            </label>

            {showConfig && isPro && (
                <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                    {/* Frequency: Повторять каждые [ N ] [ дни / недели ] */}
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Частота</label>
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-slate-600 dark:text-zinc-400">Каждые</span>
                            <input
                                type="number"
                                min={1}
                                max={100}
                                value={value.interval}
                                onChange={(e) => onChange({ ...value, interval: Math.max(1, parseInt(e.target.value) || 1) })}
                                className="w-16 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                            />
                            <Select
                                value={value.recurrence_type}
                                onValueChange={(v: 'daily' | 'weekly') => {
                                    const next = { ...value, recurrence_type: v };
                                    if (v === 'daily') next.weekdays = [];
                                    onChange(next);
                                }}
                            >
                                <SelectTrigger className="w-28">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="daily">{value.interval === 1 ? 'день' : (value.interval >= 2 && value.interval <= 4 ? 'дня' : 'дней')}</SelectItem>
                                    <SelectItem value="weekly">{value.interval === 1 ? 'неделю' : (value.interval >= 2 && value.interval <= 4 ? 'недели' : 'недель')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* Weekdays — only for weekly */}
                    {value.recurrence_type === 'weekly' && (
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Дни недели</label>
                            <div className="flex gap-1.5">
                                {WEEKDAY_OPTIONS.map((day) => (
                                    <button
                                        key={day.value}
                                        type="button"
                                        onClick={() => toggleWeekday(day.value)}
                                        className={`flex size-8 items-center justify-center rounded-lg text-xs font-medium transition-colors ${
                                            value.weekdays.includes(day.value)
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
                                value={value.end_type}
                                onValueChange={(v: 'count' | 'date') => onChange({ ...value, end_type: v })}
                            >
                                <SelectTrigger className="w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="count">Количество записей</SelectItem>
                                    <SelectItem value="date">До даты</SelectItem>
                                </SelectContent>
                            </Select>

                            {value.end_type === 'count' ? (
                                <input
                                    type="number"
                                    min={2}
                                    max={100}
                                    value={value.occurrences_count}
                                    onChange={(e) => onChange({ ...value, occurrences_count: Math.max(2, parseInt(e.target.value) || 10) })}
                                    className="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                                />
                            ) : (
                                <input
                                    type="date"
                                    value={value.ends_at}
                                    onChange={(e) => onChange({ ...value, ends_at: e.target.value })}
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
                        disabled={previewLoading || (value.recurrence_type === 'weekly' && value.weekdays.length === 0)}
                        className="w-full"
                    >
                        {previewLoading ? 'Проверка...' : 'Проверить доступность'}
                    </Button>

                    {previewResult && (
                        <div className="rounded-lg bg-white p-2.5 text-sm dark:bg-zinc-800">
                            <p className="font-medium text-slate-700 dark:text-zinc-200">
                                {previewResult.total} записей: {previewResult.available} доступны
                                {previewResult.conflicts.length > 0 && (
                                    <span className="text-red-500">, {previewResult.conflicts.length} конфликт</span>
                                )}
                            </p>
                            {previewResult.conflicts.length > 0 && (
                                <div className="mt-1 space-y-0.5 text-xs text-red-400">
                                    {previewResult.conflicts.map((c) => (
                                        <p key={c.date}>
                                            {c.date.split('-').reverse().join('.')} · {startTime ?? '??:??'} — {CONFLICT_LABELS[c.reason] ?? CONFLICT_LABELS.conflict}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
