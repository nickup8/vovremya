import { useMemo } from 'react';
import { AppointmentStatus } from '@/types/appointment-status';
import { Appointment } from './types';
import { DAY_NAMES, STATUS_STYLES } from './constants';
import { dateToKey, isSameDay, getMonthGrid } from './helpers';

interface Props {
    appointments: Appointment[];
    centerDate: Date;
    onDayClick: (appointment: Appointment) => void;
    onEmptyDayClick: (date: string) => void;
}

export function MonthView({ appointments, centerDate, onDayClick, onEmptyDayClick }: Props) {
    const today = new Date();
    const grid = useMemo(() => getMonthGrid(centerDate), [centerDate]);
    const currentMonth = centerDate.getMonth();

    return (
        <div className="rounded-xl border border-slate-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            {/* Day-of-week headers */}
            <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50 dark:border-zinc-800 dark:bg-zinc-900/50">
                {DAY_NAMES.map((name) => (
                    <div key={name} className="p-2 text-center text-xs font-semibold text-slate-500 dark:text-zinc-400">
                        {name}
                    </div>
                ))}
            </div>

            {/* Day grid */}
            <div className="grid grid-cols-7 gap-px bg-slate-200 dark:bg-zinc-800">
                {grid.map((day, i) => {
                    const dateKey = dateToKey(day);
                    const dayAppts = appointments.filter((a) => a.date === dateKey);
                    const isCurrentMonth = day.getMonth() === currentMonth;
                    const isToday_ = isSameDay(day, today);

                    return (
                        <button
                            key={i}
                            type="button"
                            onClick={() => dayAppts.length === 0 ? onEmptyDayClick(dateKey) : undefined}
                            className={`flex min-h-[80px] md:min-h-[120px] flex-col gap-1 bg-white p-1.5 text-left transition-colors hover:bg-slate-50 dark:bg-zinc-900 dark:hover:bg-zinc-800/50 ${
                                !isCurrentMonth ? 'bg-slate-50/50 text-slate-400 dark:bg-zinc-900/50 dark:text-zinc-600' : ''
                            }`}
                        >
                            {/* Day number */}
                            <div className="flex items-center justify-between">
                                <span className={`inline-flex size-6 items-center justify-center rounded-full text-xs font-medium ${
                                    isToday_
                                        ? 'bg-blue-600 text-white'
                                        : isCurrentMonth
                                            ? 'text-slate-700 dark:text-zinc-300'
                                            : 'text-slate-400 dark:text-zinc-600'
                                }`}>
                                    {day.getDate()}
                                </span>
                                {dayAppts.length > 3 && (
                                    <span className="text-[10px] text-slate-400 dark:text-zinc-500">
                                        +{dayAppts.length - 3}
                                    </span>
                                )}
                            </div>

                            {/* Appointment badges */}
                            <div className="flex flex-1 flex-col gap-0.5 overflow-y-auto scrollbar-none">
                                {dayAppts.slice(0, 3).map((appt) => (
                                    <div
                                        key={appt.id}
                                        onClick={(e) => { e.stopPropagation(); onDayClick(appt); }}
                                        className={`cursor-pointer truncate rounded px-1 py-0.5 text-[10px] font-medium leading-tight transition-colors hover:opacity-80 ${
                                            appt.status === AppointmentStatus.Paid
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                : appt.status === AppointmentStatus.NoShow
                                                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400'
                                                    : appt.status === AppointmentStatus.Cancelled
                                                        ? 'bg-zinc-100 text-zinc-500 line-through dark:bg-zinc-800 dark:text-zinc-500'
                                                        : 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400'
                                        }`}
                                    >
                                        {appt.time} — {appt.client_name}
                                    </div>
                                ))}
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
