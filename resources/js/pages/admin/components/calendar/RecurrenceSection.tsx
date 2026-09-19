import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export interface RecurrenceConfig {
    enabled: boolean;
    recurrence_type: 'weekly';
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

export interface PreviewResult {
    total: number;
    available: number;
    conflicts: Array<{ date: string; reason: string }>;
    dates: string[];
}

interface Props {
    value: RecurrenceConfig;
    onChange: (config: RecurrenceConfig) => void;
    isPro: boolean;
    previewLoading: boolean;
    previewResult: PreviewResult | null;
    onPreview: () => void;
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

export function RecurrenceSection({ value, onChange, isPro, previewLoading, previewResult, onPreview }: Props) {
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
                    {/* Frequency */}
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Частота</label>
                        <Select
                            value={String(value.interval)}
                            onValueChange={(v) => onChange({ ...value, interval: parseInt(v) })}
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

                    {/* End condition */}
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-500 dark:text-zinc-400">Окончание</label>
                        <div className="flex gap-2">
                            <Select
                                value={value.end_type}
                                onValueChange={(v: 'count' | 'date') => onChange({ ...value, end_type: v })}
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="count">После N записей</SelectItem>
                                    <SelectItem value="date">До даты</SelectItem>
                                </SelectContent>
                            </Select>

                            {value.end_type === 'count' ? (
                                <input
                                    type="number"
                                    min={2}
                                    max={100}
                                    value={value.occurrences_count}
                                    onChange={(e) => onChange({ ...value, occurrences_count: parseInt(e.target.value) || 10 })}
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
                        disabled={previewLoading || value.weekdays.length === 0}
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
            )}
        </div>
    );
}
