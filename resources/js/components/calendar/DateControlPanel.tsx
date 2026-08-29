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
        <div className="shrink-0 border-b border-[var(--color-line)] bg-white px-4 py-3 dark:bg-[var(--color-cal-surface-alt)] lg:px-[28px] lg:py-[14px]">
            {/* Row 1: navigation + date + today */}
            <div className="flex items-center gap-2">
                <button
                    onClick={onPrev}
                    className="flex size-10 shrink-0 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] lg:size-9"
                    aria-label="Предыдущий период"
                >
                    <ChevronLeftIcon className="size-5" />
                </button>
                <div className="flex min-w-0 flex-1 items-baseline gap-1.5">
                    <span className="truncate text-[15px] font-semibold tracking-tight text-[var(--color-ink)]">
                        {dateLabel}
                    </span>
                    {yearLabel && (
                        <span className="hidden text-xs font-medium text-[var(--color-graphite)] lg:inline">{yearLabel}</span>
                    )}
                </div>
                <button
                    onClick={onNext}
                    className="flex size-10 shrink-0 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] lg:size-9"
                    aria-label="Следующий период"
                >
                    <ChevronRightIcon className="size-5" />
                </button>
                <button
                    onClick={onToday}
                    className="flex h-10 shrink-0 items-center gap-1.5 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-elevated,#fff)] px-3 text-[13px] font-semibold text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)] dark:bg-[var(--color-cal-surface)]"
                >
                    <CalendarDaysIcon className="size-[18px]" />
                    <span className="hidden lg:inline">Сегодня</span>
                </button>
            </div>

            {/* Row 2: segmented view switch — full width on mobile, inline on desktop */}
            <div className="mt-2 flex lg:mt-0 lg:hidden">
                <div
                    className="flex h-10 w-full gap-0.5 rounded-xl bg-[var(--color-warm)] p-[3px]"
                    aria-label="Представление календаря"
                >
                    {(['day', 'week', 'month'] as const).map((mode) => (
                        <button
                            key={mode}
                            onClick={() => onSetView(mode)}
                            className={`h-full flex-1 rounded-[9px] px-[13px] text-[13px] font-semibold transition-all ${
                                viewMode === mode
                                    ? 'bg-white text-[var(--color-ink)] shadow-[0_1px_2px_rgba(24,24,24,0.18)] dark:bg-[var(--color-cal-surface)]'
                                    : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                            }`}
                        >
                            {mode === 'day' ? 'День' : mode === 'week' ? 'Неделя' : 'Месяц'}
                        </button>
                    ))}
                </div>
            </div>

            {/* Desktop: inline view tools */}
            <div className="hidden items-center justify-end gap-2 lg:flex">
                <div
                    className="flex h-10 items-center gap-0.5 rounded-xl bg-[var(--color-warm)] p-[3px]"
                    aria-label="Представление календаря"
                >
                    {(['day', 'week', 'month'] as const).map((mode) => (
                        <button
                            key={mode}
                            onClick={() => onSetView(mode)}
                            className={`h-full rounded-[9px] px-[13px] text-[13px] font-semibold transition-all ${
                                viewMode === mode
                                    ? 'bg-white text-[var(--color-ink)] shadow-[0_1px_2px_rgba(24,24,24,0.18)] dark:bg-[var(--color-cal-surface)]'
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
