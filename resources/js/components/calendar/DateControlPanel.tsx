import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';

interface DateControlPanelProps {
    viewMode: 'week' | 'day' | 'month';
    dateLabel: string;
    onPrev: () => void;
    onNext: () => void;
    onToday: () => void;
    onSetView: (mode: 'week' | 'day' | 'month') => void;
    onNewAppointment: () => void;
}

export default function DateControlPanel({
    viewMode,
    dateLabel,
    onPrev,
    onNext,
    onToday,
    onSetView,
    onNewAppointment,
}: DateControlPanelProps) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div className="flex items-center gap-2">
                <button
                    onClick={onPrev}
                    className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"
                >
                    <ChevronLeft className="size-4 text-slate-600 dark:text-zinc-400" />
                </button>
                <h2 className="text-sm font-semibold text-slate-900 dark:text-zinc-100 md:text-base">
                    {dateLabel}
                </h2>
                <button
                    onClick={onNext}
                    className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"
                >
                    <ChevronRight className="size-4 text-slate-600 dark:text-zinc-400" />
                </button>
                <button
                    onClick={onToday}
                    className="ml-2 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                >
                    Сегодня
                </button>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="flex w-full overflow-hidden rounded-md bg-slate-100 dark:bg-zinc-800 sm:w-auto">
                    {(['day', 'week', 'month'] as const).map((mode) => (
                        <button
                            key={mode}
                            onClick={() => onSetView(mode)}
                            className={`flex-1 px-3 py-1.5 text-xs font-medium transition-colors sm:flex-none ${
                                viewMode === mode
                                    ? 'bg-white text-slate-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100'
                                    : 'bg-transparent text-slate-600 hover:text-slate-800 dark:text-zinc-400 dark:hover:text-zinc-200'
                            }`}
                        >
                            {mode === 'day' ? 'День' : mode === 'week' ? 'Неделя' : 'Месяц'}
                        </button>
                    ))}
                </div>
                <button
                    onClick={onNewAppointment}
                    className="flex w-full items-center justify-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 sm:w-auto sm:justify-start"
                >
                    <Plus className="size-3.5" />
                    Новая запись
                </button>
            </div>
        </div>
    );
}
