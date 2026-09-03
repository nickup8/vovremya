import { useState, useMemo } from 'react';
import { CalendarIcon, ChevronLeft, ChevronRight } from 'lucide-react';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { MONTHS_RU } from '@/lib/locale';
import { dateToKey, isSameDay } from './helpers';

interface Props {
    value: string;
    onChange: (value: string) => void;
    min?: string;
}

const DAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

function getMonthGrid(year: number, month: number): Date[] {
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDow = firstDay.getDay();
    const gridStart = new Date(firstDay);
    gridStart.setDate(gridStart.getDate() - (startDow === 0 ? 6 : startDow - 1));
    const totalCells = Math.ceil(((lastDay.getTime() - gridStart.getTime()) / 86400000 + 1) / 7) * 7;

    return Array.from({ length: totalCells }, (_, i) => {
        const d = new Date(gridStart);
        d.setDate(d.getDate() + i);

        return d;
    });
}

function parseValue(v: string): Date | null {
    if (!v) return null;
    const parts = v.split('-');

    if (parts.length !== 3) return null;

    return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
}

export function IrsiDatePicker({ value, onChange, min }: Props) {
    const [open, setOpen] = useState(false);
    const today = useMemo(() => {
        const d = new Date();
        d.setHours(0, 0, 0, 0);

        return d;
    }, []);
    const selected = useMemo(() => parseValue(value), [value]);
    const minDate = useMemo(() => parseValue(min ?? ''), [min]);

    const [viewYear, setViewYear] = useState(() => selected?.getFullYear() ?? today.getFullYear());
    const [viewMonth, setViewMonth] = useState(() => selected?.getMonth() ?? today.getMonth());

    const grid = useMemo(() => getMonthGrid(viewYear, viewMonth), [viewYear, viewMonth]);

    function prevMonth() {
        if (viewMonth === 0) {
            setViewMonth(11);
            setViewYear(viewYear - 1);
        } else {
            setViewMonth(viewMonth - 1);
        }
    }

    function nextMonth() {
        if (viewMonth === 11) {
            setViewMonth(0);
            setViewYear(viewYear + 1);
        } else {
            setViewMonth(viewMonth + 1);
        }
    }

    function selectDay(d: Date) {
        onChange(dateToKey(d));
        setOpen(false);
    }

    const displayText = value
        ? (() => {
            const p = value.split('-');

            return `${p[2]}.${p[1]}.${p[0]}`;
        })()
        : '';

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center justify-between rounded-lg border border-[var(--color-line)] bg-white px-3 py-2 text-sm text-[var(--color-ink)] dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)]"
                >
                    <span className={cn(!value && 'text-[var(--color-graphite)]')}>
                        {displayText || 'ДД.ММ.ГГГГ'}
                    </span>
                    <CalendarIcon className="size-4 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                sideOffset={4}
                className="w-auto rounded-xl border-[var(--color-line)] bg-white p-3 shadow-sm dark:border-[var(--color-cal-border)] dark:bg-[var(--color-cal-surface)]"
            >
                <div className="mb-2 flex items-center justify-between">
                    <button
                        type="button"
                        onClick={prevMonth}
                        className="rounded-lg p-1 hover:bg-[var(--color-surface-hover)]"
                    >
                        <ChevronLeft className="size-4" />
                    </button>
                    <span className="text-sm font-medium text-[var(--color-ink)]">
                        {MONTHS_RU[viewMonth]} {viewYear}
                    </span>
                    <button
                        type="button"
                        onClick={nextMonth}
                        className="rounded-lg p-1 hover:bg-[var(--color-surface-hover)]"
                    >
                        <ChevronRight className="size-4" />
                    </button>
                </div>

                <div className="mb-1 grid grid-cols-7 gap-0">
                    {DAY_NAMES.map((d) => (
                        <div key={d} className="flex h-7 items-center justify-center text-[11px] font-medium text-[var(--color-graphite)]">
                            {d}
                        </div>
                    ))}
                </div>

                <div className="grid grid-cols-7 gap-0">
                    {grid.map((day, i) => {
                        const isCurrentMonth = day.getMonth() === viewMonth;
                        const isSelected = selected ? isSameDay(day, selected) : false;
                        const isToday = isSameDay(day, today);
                        const isDisabled = minDate ? day < minDate : false;

                        return (
                            <button
                                key={i}
                                type="button"
                                disabled={isDisabled}
                                onClick={() => selectDay(day)}
                                className={cn(
                                    'flex h-8 items-center justify-center rounded-lg text-sm transition-colors',
                                    isSelected && 'bg-[var(--color-orange)] font-semibold text-white',
                                    !isSelected && isToday && 'font-semibold text-[var(--color-orange)]',
                                    !isSelected && !isToday && isCurrentMonth && 'text-[var(--color-ink)] hover:bg-[var(--color-surface-hover)]',
                                    !isSelected && !isCurrentMonth && 'text-[var(--color-graphite)]/40',
                                    isDisabled && !isSelected && 'pointer-events-none opacity-30',
                                )}
                            >
                                {day.getDate()}
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}
