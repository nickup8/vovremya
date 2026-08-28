import {
    ChevronLeftIcon,
    ChevronRightIcon,
    CalendarDaysIcon,
} from '@heroicons/react/24/outline';

interface DateControlPanelProps {
    viewMode: 'week' | 'day' | 'month';
    dateLabel: string;
    yearLabel?: string;
    onPrev: () => void;
    onNext: () => void;
    onToday: () => void;
    onSetView: (mode: 'week' | 'day' | 'month') => void;
}

export default function DateControlPanel({
    viewMode,
    dateLabel,
    yearLabel,
    onPrev,
    onNext,
    onToday,
    onSetView,
}: DateControlPanelProps) {
    return (
        <div className="flex min-h-[72px] items-center gap-3 border-b border-[var(--color-line)] bg-white px-7 py-3.5 dark:bg-zinc-900">
            {/* Left: navigation */}
            <div className="flex items-center gap-1">
                <button
                    onClick={onPrev}
                    className="flex size-10 items-center justify-center rounded-[10px] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                    aria-label="Предыдущий период"
                >
                    <ChevronLeftIcon className="size-5" />
                </button>
                <button
                    onClick={onNext}
                    className="flex size-10 items-center justify-center rounded-[10px] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                    aria-label="Следующий период"
                >
                    <ChevronRightIcon className="size-5" />
                </button>
                <div className="ml-2 whitespace-nowrap text-[15px] font-semibold tracking-tight text-[var(--color-ink)]">
                    {dateLabel}
                    {yearLabel && (
                        <span className="ml-1.5 text-xs font-medium text-[var(--color-graphite)]">{yearLabel}</span>
                    )}
                </div>
            </div>

            {/* Spacer */}
            <div className="flex-1" />

            {/* Right: tools */}
            <div className="flex items-center gap-2">
                <button
                    onClick={onToday}
                    className="flex h-10 items-center gap-2 rounded-[10px] border border-[var(--color-line)] bg-white px-3 text-sm font-semibold text-[var(--color-ink)] transition-colors hover:bg-[var(--color-line-soft)] dark:bg-zinc-900"
                >
                    <CalendarDaysIcon className="size-5" />
                    Сегодня
                </button>

                <div
                    className="flex h-10 items-center gap-0.5 rounded-xl bg-[var(--color-warm)] p-[3px]"
                    aria-label="Представление календаря"
                >
                    {(['day', 'week', 'month'] as const).map((mode) => (
                        <button
                            key={mode}
                            onClick={() => onSetView(mode)}
                            className={`rounded-lg px-3.5 py-0 text-[13px] font-semibold transition-all ${
                                viewMode === mode
                                    ? 'bg-white text-[var(--color-ink)] shadow-sm dark:bg-zinc-800'
                                    : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                            }`}
                        >
                            {mode === 'day' ? 'День' : mode === 'week' ? 'Неделя' : 'Месяц'}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}
