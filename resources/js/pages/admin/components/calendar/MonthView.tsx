import { useMemo } from 'react';
import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment } from './types';
import { DAY_NAMES, STATUS_STYLES } from './constants';
import { dateToKey, isSameDay, getMonthGrid } from './helpers';

const STATUS_TEXT: Record<AppointmentStatus, string> = {
    [AppointmentStatus.Booked]: 'text-[#3478F6] dark:text-[#3478F6]/80',
    [AppointmentStatus.PendingPayment]: 'text-[#3478F6] dark:text-[#3478F6]/80',
    [AppointmentStatus.Prepaid]: 'text-[#3478F6] dark:text-[#3478F6]/80',
    [AppointmentStatus.Paid]: 'text-[#22A66F] dark:text-[#22A66F]/80',
    [AppointmentStatus.NoShow]: 'text-[#E34F5F] dark:text-[#E34F5F]/80',
    [AppointmentStatus.Cancelled]: 'text-[#92969D] dark:text-[#92969D]/80',
};

interface Props {
    appointments: Appointment[];
    centerDate: Date;
    onDayColumnClick: (dateKey: string) => void;
}

export function MonthView({ appointments, centerDate, onDayColumnClick }: Props) {
    const today = new Date();
    const grid = useMemo(() => getMonthGrid(centerDate), [centerDate]);
    const currentMonth = centerDate.getMonth();

    return (
        <div className="rounded-xl border border-[var(--color-line)] bg-white shadow-xs dark:bg-[var(--color-cal-surface)]">
            {/* Day-of-week headers */}
            <div className="grid grid-cols-7 border-b border-[var(--color-line)] bg-[var(--color-warm)] dark:bg-[var(--color-cal-surface-alt)]">
                {DAY_NAMES.map((name) => (
                    <div key={name} className="p-2 text-center text-xs font-semibold text-[var(--color-graphite)]">
                        {name}
                    </div>
                ))}
            </div>

            {/* Day grid */}
            <div className="grid grid-cols-7 gap-px bg-[var(--color-line)]">
                {grid.map((day, i) => {
                    const dateKey = dateToKey(day);
                    const dayAppts = appointments.filter((a) => a.date === dateKey);
                    const isCurrentMonth = day.getMonth() === currentMonth;
                    const isToday_ = isSameDay(day, today);

                    return (
                        <button
                            key={i}
                            type="button"
                            onClick={() => onDayColumnClick(dateKey)}
                            className={`flex min-h-[80px] md:min-h-[120px] flex-col gap-1 bg-white p-1.5 text-left transition-colors hover:bg-[var(--color-surface-hover)] dark:bg-[var(--color-cal-surface)] dark:hover:bg-[var(--color-cal-surface-alt)] ${
                                !isCurrentMonth ? 'bg-[var(--color-surface)]/50 text-[var(--color-graphite)]/50 dark:bg-[var(--color-cal-surface-alt)]/50 dark:text-[var(--color-graphite)]/40' : ''
                            }`}
                        >
                            {/* Day number */}
                            <div className="flex items-center justify-between">
                                <span className={`inline-flex size-6 items-center justify-center rounded-full text-xs font-medium ${
                                    isToday_
                                        ? 'bg-[var(--color-orange)] text-white shadow-[0_2px_8px_rgba(255,90,31,0.25)]'
                                        : isCurrentMonth
                                            ? 'text-[var(--color-ink)]'
                                            : 'text-[var(--color-graphite)]/50'
                                }`}>
                                    {day.getDate()}
                                </span>
                                {dayAppts.length > 3 && (
                                    <span className="text-[10px] text-[var(--color-graphite)]">
                                        +{dayAppts.length - 3}
                                    </span>
                                )}
                            </div>

                            {/* Appointment badges */}
                            <div className="flex flex-1 flex-col gap-0.5 overflow-y-auto scrollbar-none">
                                {dayAppts.slice(0, 3).map((appt) => {
                                    const style = STATUS_STYLES[appt.status as AppointmentStatus] ?? STATUS_STYLES[AppointmentStatus.Booked];
                                    const textColor = STATUS_TEXT[appt.status as AppointmentStatus] ?? STATUS_TEXT[AppointmentStatus.Booked];
                                    const isCancelled = appt.status === AppointmentStatus.Cancelled;

                                    return (
                                        <div
                                            key={appt.id}
                                            className={`truncate rounded px-1 py-0.5 text-[10px] font-medium leading-tight transition-colors ${style.bg} ${textColor} ${isCancelled ? 'line-through' : ''}`}
                                        >
                                            <span className={`mr-1 inline-block size-1.5 align-middle rounded-full ${style.dot}`} />
                                            {appt.time} — {appt.client_name}
                                        </div>
                                    );
                                })}
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
