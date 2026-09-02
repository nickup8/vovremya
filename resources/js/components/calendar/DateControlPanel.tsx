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
        <div className="shrink-0 border-b border-[var(--color-line)] bg-white dark:bg-[var(--color-cal-surface-alt)]">
            {/* Mobile: two rows */}
            <div className="px-4 py-3 lg:hidden">
                {/* Row 1: prev / date / next / today */}
                <div className="flex items-center gap-2">
                    <button
                        onClick={onPrev}
                        className="flex size-10 shrink-0 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                        aria-label="Предыдущий период"
                    >
                        <ChevronLeftIcon className="size-5" />
                    </button>
                    <div className="flex min-w-0 flex-1 items-baseline gap-1.5">
                        <span className="truncate text-[15px] font-semibold tracking-tight text-[var(--color-ink)]">
                            {dateLabel}
                        </span>
                    </div>
                    <button
                        onClick={onNext}
                        className="flex size-10 shrink-0 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                        aria-label="Следующий период"
                    >
                        <ChevronRightIcon className="size-5" />
                    </button>
                    <button
                        onClick={onToday}
                        className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-[var(--color-line)] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)]"
                        aria-label="Сегодня"
                    >
                        <CalendarDaysIcon className="size-[18px]" />
                    </button>
                </div>
                {/* Row 2: full-width segmented */}
                <div className="mt-2">
                    <div
                        className="flex h-10 w-full gap-0.5 rounded-xl bg-[var(--color-warm)] p-[3px]"
                        aria-label="Представление календаря"
                    >
                        {(['day', 'week', 'month'] as const).map((mode) => (
                            <button
                                key={mode}
                                onClick={() => onSetView(mode)}
                                className={`h-full flex-1 cursor-pointer rounded-[9px] px-[13px] text-[13px] font-semibold transition-all ${
                                    viewMode === mode
                                        ? 'bg-white text-[var(--color-ink)] shadow-[0_1px_2px_rgba(24,24,24,0.18)] dark:bg-[var(--color-cal-surface)]'
                                        : 'text-[var(--color-graphite)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-ink)]'
                                }`}
                            >
                                {mode === 'day' ? 'День' : mode === 'week' ? 'Неделя' : 'Месяц'}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {/* Desktop: single row — exact 19aafed baseline */}
            <div className="hidden min-h-[72px] items-center gap-3 px-[28px] py-[14px] lg:flex">
                <div className="flex items-center gap-0.5">
                    <button
                        onClick={onPrev}
                        className="flex size-9 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                        aria-label="Предыдущий период"
                    >
                        <ChevronLeftIcon className="size-5" />
                    </button>
                    <button
                        onClick={onNext}
                        className="flex size-9 items-center justify-center rounded-lg text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)]"
                        aria-label="Следующий период"
                    >
                        <ChevronRightIcon className="size-5" />
                    </button>
                    <div className="ml-2 flex items-baseline gap-1.5 whitespace-nowrap">
                        <span className="text-[15px] font-semibold tracking-tight text-[var(--color-ink)]">
                            {dateLabel}
                        </span>
                        {yearLabel && (
                            <span className="text-xs font-medium text-[var(--color-graphite)]">{yearLabel}</span>
                        )}
                    </div>
                </div>
                <div className="flex-1" />
                <div className="flex items-center gap-2">
                    <button
                        onClick={onToday}
                        className="flex h-10 items-center gap-1.5 rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-elevated,#fff)] px-3 text-[13px] font-semibold text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-line-soft)] hover:text-[var(--color-ink)] dark:bg-[var(--color-cal-surface)]"
                    >
                        <CalendarDaysIcon className="size-[18px]" />
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
                                className={`h-full cursor-pointer rounded-[9px] px-[13px] text-[13px] font-semibold transition-all ${
                                    viewMode === mode
                                        ? 'bg-white text-[var(--color-ink)] shadow-[0_1px_2px_rgba(24,24,24,0.18)] dark:bg-[var(--color-cal-surface)]'
                                        : 'text-[var(--color-graphite)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-ink)]'
                                }`}
                            >
                                {mode === 'day' ? 'День' : mode === 'week' ? 'Неделя' : 'Месяц'}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
