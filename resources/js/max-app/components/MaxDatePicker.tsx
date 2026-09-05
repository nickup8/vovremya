import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface MaxDatePickerProps {
    value: string;
    onChange: (value: string) => void;
    min?: string;
    max?: string;
    disabled?: boolean;
}

const MONTHS_RU = [
    'Январь', 'Февраль', 'Март', 'Апрель',
    'Май', 'Июнь', 'Июль', 'Август',
    'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
];

const DAYS_RU = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

function parseYMD(s: string): Date | null {
    if (!s) return null;
    const [y, m, d] = s.split('-').map(Number);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d);
}

function toYMD(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function formatDisplay(iso: string): string {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}.${m}.${y}`;
}

function getWeeks(year: number, month: number): (Date | null)[][] {
    const first = new Date(year, month, 1);
    let startDay = first.getDay();
    startDay = startDay === 0 ? 6 : startDay - 1;

    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cells: (Date | null)[] = [];

    for (let i = 0; i < startDay; i++) {
        cells.push(null);
    }
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push(new Date(year, month, d));
    }
    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    const weeks: (Date | null)[][] = [];
    for (let i = 0; i < cells.length; i += 7) {
        weeks.push(cells.slice(i, i + 7));
    }
    return weeks;
}

export function MaxDatePicker({
    value,
    onChange,
    min,
    max,
    disabled = false,
}: MaxDatePickerProps) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    const selectedDate = parseYMD(value);
    const minDate = parseYMD(min ?? '');
    const maxDate = parseYMD(max ?? '');
    const todayStr = toYMD(new Date());

    const initialMonth = selectedDate ?? new Date();
    const [viewYear, setViewYear] = useState(initialMonth.getFullYear());
    const [viewMonth, setViewMonth] = useState(initialMonth.getMonth());

    useEffect(() => {
        if (!open) return;
        const d = selectedDate ?? new Date();
        setViewYear(d.getFullYear());
        setViewMonth(d.getMonth());
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const handler = (e: MouseEvent | TouchEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        document.addEventListener('touchstart', handler);
        return () => {
            document.removeEventListener('mousedown', handler);
            document.removeEventListener('touchstart', handler);
        };
    }, [open]);

    const isDisabled = useCallback((d: Date): boolean => {
        const ds = toYMD(d);
        if (minDate && ds < toYMD(minDate)) return true;
        if (maxDate && ds > toYMD(maxDate)) return true;
        return false;
    }, [minDate, maxDate]);

    const weeks = useMemo(() => getWeeks(viewYear, viewMonth), [viewYear, viewMonth]);

    const goPrev = () => {
        if (viewMonth === 0) {
            setViewMonth(11);
            setViewYear(viewYear - 1);
        } else {
            setViewMonth(viewMonth - 1);
        }
    };

    const goNext = () => {
        if (viewMonth === 11) {
            setViewMonth(0);
            setViewYear(viewYear + 1);
        } else {
            setViewMonth(viewMonth + 1);
        }
    };

    const handleSelect = (d: Date) => {
        if (isDisabled(d)) return;
        onChange(toYMD(d));
        setOpen(false);
    };

    const canGoPrev = (): boolean => {
        if (!minDate) return true;
        const prevMonth = viewMonth === 0 ? 11 : viewMonth - 1;
        const prevYear = viewMonth === 0 ? viewYear - 1 : viewYear;
        const lastDay = new Date(prevYear, prevMonth + 1, 0);
        return lastDay >= minDate;
    };

    const canGoNext = (): boolean => {
        if (!maxDate) return true;
        const nextMonth = viewMonth === 11 ? 0 : viewMonth + 1;
        const nextYear = viewMonth === 11 ? viewYear + 1 : viewYear;
        const firstDay = new Date(nextYear, nextMonth, 1);
        return firstDay <= maxDate;
    };

    return (
        <div className="mdp" ref={containerRef}>
            <button
                type="button"
                className="mdp-trigger"
                onClick={() => !disabled && setOpen(!open)}
                disabled={disabled}
            >
                <span className={value ? 'mdp-trigger-value' : 'mdp-trigger-placeholder'}>
                    {value ? formatDisplay(value) : 'ДД.ММ.ГГГГ'}
                </span>
                <svg className="mdp-trigger-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                    <path d="M16 2v4M8 2v4M3 10h18" />
                </svg>
            </button>

            {open && (
                <div className="mdp-popover">
                    <div className="mdp-nav">
                        <button
                            type="button"
                            className="mdp-nav-arrow"
                            onClick={goPrev}
                            disabled={!canGoPrev()}
                            aria-label="Предыдущий месяц"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M15 18l-6-6 6-6" />
                            </svg>
                        </button>
                        <span className="mdp-nav-title">
                            {MONTHS_RU[viewMonth]} {viewYear}
                        </span>
                        <button
                            type="button"
                            className="mdp-nav-arrow"
                            onClick={goNext}
                            disabled={!canGoNext()}
                            aria-label="Следующий месяц"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M9 18l6-6-6-6" />
                            </svg>
                        </button>
                    </div>

                    <div className="mdp-days-header">
                        {DAYS_RU.map((day) => (
                            <span key={day} className="mdp-day-label">{day}</span>
                        ))}
                    </div>

                    <div className="mdp-grid">
                        {weeks.map((week, wi) => (
                            <div key={wi} className="mdp-week">
                                {week.map((cell, ci) => {
                                    if (!cell) {
                                        return <span key={ci} className="mdp-cell mdp-cell--empty" />;
                                    }

                                    const cellStr = toYMD(cell);
                                    const disabledDay = isDisabled(cell);
                                    const isSelected = value === cellStr;
                                    const isToday = cellStr === todayStr;
                                    const isAdjacent = cell.getMonth() !== viewMonth;

                                    let cls = 'mdp-cell';
                                    if (isSelected) cls += ' mdp-cell--selected';
                                    else if (isToday) cls += ' mdp-cell--today';
                                    if (disabledDay) cls += ' mdp-cell--disabled';
                                    if (isAdjacent && !isSelected) cls += ' mdp-cell--adjacent';

                                    return (
                                        <button
                                            key={ci}
                                            type="button"
                                            className={cls}
                                            onClick={() => handleSelect(cell)}
                                            disabled={disabledDay}
                                            tabIndex={-1}
                                        >
                                            {cell.getDate()}
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
