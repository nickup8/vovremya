import { Appointment, BlockedTime, WorkingHour, ClientOption, ServiceOption } from './types';
import { AppointmentCard } from './AppointmentCard';
import { BlockedTimeCard } from './BlockedTimeCard';
import { BreakZone } from './BreakZone';
import { DAY_NAMES, HOUR_HEIGHT, MINUTE_HEIGHT } from './constants';
import { timeToMinutes, hasCollision } from './helpers';
import { calculateCollisions } from './collision';

interface WeekViewProps {
    weekDates: Date[];
    weekDateKeys: string[];
    gridHours: number[];
    dayStartHour: number;
    slotInterval: number;
    workingHours: WorkingHour[];
    localAppointments: Appointment[];
    initialBlockedTimes: BlockedTime[];
    activeBookingClient: ClientOption | null;
    bookingModeServiceId: string;
    bookingModeService: ServiceOption | null;
    hoveredSlot: { date: string; time: string } | null;
    onSlotHover: (slot: { date: string; time: string } | null) => void;
    onSlotClick: (date: string, time: string) => void;
    onAppointmentClick: (appointment: Appointment) => void;
    getAppointmentsForDay: (dayIndex: number) => Appointment[];
    getBlockedTimesForDay: (dayIndex: number) => BlockedTime[];
    isToday: (date: Date) => boolean;
}

function getAppointmentsForDayFromProps(
    dayIndex: number,
    weekDateKeys: string[],
    localAppointments: Appointment[],
): Appointment[] {
    const key = weekDateKeys[dayIndex];
    return localAppointments.filter((a) => a.date === key);
}

function getBlockedTimesForDayFromProps(
    dayIndex: number,
    weekDateKeys: string[],
    initialBlockedTimes: BlockedTime[],
): BlockedTime[] {
    const key = weekDateKeys[dayIndex];
    const dayStart = new Date(key + 'T00:00:00');
    const dayEnd = new Date(key + 'T23:59:59');
    return initialBlockedTimes.filter((bt) => {
        const btStart = new Date(bt.start_datetime);
        const btEnd = new Date(bt.end_datetime);
        return btStart <= dayEnd && btEnd >= dayStart;
    });
}

export function WeekView({
    weekDates,
    weekDateKeys,
    gridHours,
    dayStartHour,
    slotInterval,
    workingHours,
    localAppointments,
    initialBlockedTimes,
    activeBookingClient,
    bookingModeServiceId,
    bookingModeService,
    hoveredSlot,
    onSlotHover,
    onSlotClick,
    onAppointmentClick,
    getAppointmentsForDay,
    getBlockedTimesForDay,
    isToday,
}: WeekViewProps) {
    const DAY_START_HOUR = dayStartHour;

    return (
        <div className="relative max-h-[calc(100vh-240px)] w-full overflow-auto rounded-xl border border-slate-200 bg-white shadow-xs scrollbar-thin dark:border-zinc-800 dark:bg-zinc-900">
            {/* Day Headers — sticky at top */}
            <div className="sticky top-0 z-30 flex min-w-[980px] border-b border-slate-200 bg-slate-50 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50">
                <div className="sticky left-0 z-40 w-[60px] min-w-[60px] border-r border-slate-200 bg-slate-50 p-3 text-xs font-semibold text-slate-500 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-500">
                    Время
                </div>
                <div className="grid min-w-[920px] flex-1 grid-cols-7">
                    {weekDates.map((date, idx) => {
                        const todayMark = isToday(date);
                        return (
                            <div
                                key={`h-${idx}`}
                                className={`cursor-pointer border-r border-slate-200 p-3 text-center transition-colors last:border-r-0 hover:bg-slate-100 dark:border-zinc-800 dark:hover:bg-zinc-800/50 ${todayMark ? 'bg-blue-50/50 dark:bg-blue-950/20' : ''}`}
                            >
                                <div className="text-xs font-medium text-slate-500 dark:text-zinc-400">
                                    {DAY_NAMES[idx]}
                                </div>
                                <div className={`mt-0.5 text-lg font-bold ${todayMark ? 'text-blue-600 dark:text-blue-400' : 'text-slate-900 dark:text-zinc-100'}`}>
                                    {date.getDate()}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Grid Body — time + slots */}
            <div className="flex min-w-[980px]">
                {/* Time Column — sticky left */}
                <div className="sticky left-0 z-20 w-[60px] min-w-[60px] border-r border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    {gridHours.map((hour) => {
                        const slotHeightPx = (slotInterval / 60) * HOUR_HEIGHT;
                        const labels: React.ReactNode[] = [];
                        for (let m = 0; m < 60; m += slotInterval) {
                            labels.push(
                                <div
                                    key={`${hour}-${m}`}
                                    style={{ height: slotHeightPx }}
                                    className="flex items-start border-b border-slate-100 p-2 font-mono text-xs text-slate-400 dark:border-zinc-800/40 dark:text-zinc-500"
                                >
                                    {m === 0 ? `${String(hour).padStart(2, '0')}:00` : ''}
                                </div>,
                            );
                        }
                        return labels;
                    })}
                </div>

                {/* Day Columns with Appointment Cards */}
                <div className="grid min-w-[920px] flex-1 grid-cols-7">
                    {weekDates.map((date, dayIdx) => {
                        const dayAppts = getAppointmentsForDay(dayIdx);
                        const dayBlocked = getBlockedTimesForDay(dayIdx);
                        const dateKey = weekDateKeys[dayIdx];
                        const backendDow = (dayIdx + 1) % 7;
                        const wh = workingHours.find((w) => w.day_of_week === backendDow);
                        const isBookingDay = activeBookingClient && bookingModeServiceId && hoveredSlot?.date === dateKey;
                        const ghostHeight = bookingModeService ? (bookingModeService.duration_minutes / 60) * HOUR_HEIGHT : 0;
                        const ghostTop = hoveredSlot && isBookingDay
                            ? (timeToMinutes(hoveredSlot.time) - DAY_START_HOUR * 60) * MINUTE_HEIGHT
                            : 0;
                        const ghostHasCollision = isBookingDay && hoveredSlot && bookingModeService
                            ? hasCollision(dateKey, hoveredSlot.time, bookingModeService.duration_minutes, localAppointments)
                            : false;
                        const slotHeightPx = (slotInterval / 60) * HOUR_HEIGHT;
                        return (
                            <div
                                key={`col-${dayIdx}`}
                                className="relative border-r border-slate-100 last:border-r-0 dark:border-zinc-800/40"
                            >
                                {gridHours.map((hour) => {
                                    const slots: React.ReactNode[] = [];
                                    for (let m = 0; m < 60; m += slotInterval) {
                                        const timeStr = `${String(hour).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
                                        slots.push(
                                            <div
                                                key={`${hour}-${m}`}
                                                style={{ height: slotHeightPx }}
                                                className="group relative border-b border-slate-100 transition-colors hover:bg-slate-50 dark:border-zinc-800/40 dark:hover:bg-zinc-800/30"
                                                onMouseEnter={() => {
                                                    if (activeBookingClient && bookingModeServiceId) {
                                                        onSlotHover({ date: dateKey, time: timeStr });
                                                    }
                                                }}
                                                onMouseLeave={() => {
                                                    if (hoveredSlot?.date === dateKey && hoveredSlot?.time === timeStr) {
                                                        onSlotHover(null);
                                                    }
                                                }}
                                                onClick={() => {
                                                    if (activeBookingClient && bookingModeServiceId) {
                                                        if (hasCollision(dateKey, timeStr, bookingModeService?.duration_minutes ?? 60, localAppointments)) {
                                                            return;
                                                        }
                                                    }
                                                    onSlotClick(dateKey, timeStr);
                                                }}
                                            >
                                                <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 select-none text-[10px] font-medium text-slate-400 opacity-0 transition-opacity group-hover:opacity-100 dark:text-zinc-500">
                                                    {timeStr}
                                                </span>
                                            </div>,
                                        );
                                    }
                                    return slots;
                                })}
                                {/* Ghost Appointment */}
                                {isBookingDay && ghostHeight > 0 && (
                                    <div
                                        className={`pointer-events-none absolute z-10 mx-1 rounded-md border-2 border-dashed transition-all ${
                                            ghostHasCollision
                                                ? 'border-red-500 bg-red-500/20'
                                                : 'border-blue-500 bg-blue-500/20'
                                        }`}
                                        style={{ top: ghostTop, height: Math.max(ghostHeight, 32) }}
                                    >
                                        <div className="px-2 py-1">
                                            <p className={`text-[10px] font-semibold ${
                                                ghostHasCollision
                                                    ? 'text-red-700 dark:text-red-300'
                                                    : 'text-blue-700 dark:text-blue-300'
                                            }`}>
                                                {hoveredSlot?.time} — {bookingModeService?.title}
                                                {ghostHasCollision && ' (занято)'}
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {wh?.is_working && wh.break_start_time && wh.break_end_time && (
                                    <BreakZone
                                        breakStart={wh.break_start_time}
                                        breakEnd={wh.break_end_time}
                                        dayStartHour={DAY_START_HOUR}
                                    />
                                )}
                                {dayBlocked.map((bt) => (
                                    <BlockedTimeCard
                                        key={`bt-${bt.id}`}
                                        blockedTime={bt}
                                        dayDate={dateKey}
                                        dayStartHour={DAY_START_HOUR}
                                    />
                                ))}
                                {calculateCollisions(dayAppts).map((appt) => (
                                    <AppointmentCard
                                        key={appt.id}
                                        appointment={appt}
                                        onClick={() => onAppointmentClick(appt)}
                                        dayStartHour={DAY_START_HOUR}
                                    />
                                ))}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
