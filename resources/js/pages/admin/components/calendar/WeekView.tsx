import { useState, useEffect, useRef, useCallback } from 'react';
import type { DragStartEvent, DragEndEvent, DragOverEvent } from '@dnd-kit/core';
import {
    DndContext,
    PointerSensor,
    useSensor,
    useSensors,
    useDroppable,
    DragOverlay,
} from '@dnd-kit/core';
import type { Appointment, BlockedTime, WorkingHour, ClientOption, ServiceOption } from './types';
import { AppointmentCard } from './AppointmentCard';
import { BlockedTimeCard } from './BlockedTimeCard';
import { BreakZone } from './BreakZone';
import { DAY_NAMES, HOUR_HEIGHT, MINUTE_HEIGHT } from './constants';
import { timeToMinutes, getEndTime, hasCollision, formatGmtOffset } from './helpers';
import { calculateCollisions } from './collision';

interface WeekViewProps {
    weekDates: Date[];
    weekDateKeys: string[];
    gridHours: number[];
    dayStartHour: number;
    slotInterval: number;
    workingHours: WorkingHour[];
    localAppointments: Appointment[];
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
    timezone: string;
    onRescheduleByDrag: (apptId: string, newDate: string, newTime: string) => void;
}

interface CurrentTimeLineProps {
    dayStartHour: number;
    gridHours: number[];
}

function CurrentTimeLine({ dayStartHour, gridHours }: CurrentTimeLineProps) {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 60_000);

        return () => clearInterval(id);
    }, []);

    const totalMinutes = now.getHours() * 60 + now.getMinutes();
    const gridStart = dayStartHour * 60;
    const gridEnd = gridStart + gridHours.length * 60;

    if (totalMinutes < gridStart || totalMinutes >= gridEnd) {
        return null;
    }

    const top = (totalMinutes - gridStart) * MINUTE_HEIGHT;
    const timeLabel = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    return (
        <div className="pointer-events-none absolute inset-x-0 z-20" style={{ top }}>
            <div className="absolute -left-1 top-1/2 size-2 -translate-y-1/2 rounded-full bg-[var(--color-orange)] shadow-[0_0_0_3px_white] dark:shadow-[0_0_0_3px_var(--color-warm)]" />
            <div className="h-px bg-[var(--color-orange)]" />
            <span className="absolute right-1 -top-2.5 rounded bg-white px-1 py-0.5 text-[9px] font-bold text-[var(--color-orange)] dark:bg-[var(--color-cal-surface)]">
                {timeLabel}
            </span>
        </div>
    );
}

function DroppableSlot({ id, children, ...rest }: { id: string; children: React.ReactNode; [key: string]: unknown }) {
    const { setNodeRef } = useDroppable({ id });

    return (
        <div ref={setNodeRef} {...rest}>
            {children}
        </div>
    );
}

export function WeekView({
    weekDates,
    weekDateKeys,
    gridHours,
    dayStartHour,
    slotInterval,
    workingHours,
    localAppointments,
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
    timezone,
    onRescheduleByDrag,
}: WeekViewProps) {
    const DAY_START_HOUR = dayStartHour;
    const DAY_END_HOUR = gridHours.length > 0 ? gridHours[gridHours.length - 1] + 1 : 21;
    const scrollRef = useRef<HTMLDivElement>(null);
    const didInitialScroll = useRef(false);

    const [activeAppointment, setActiveAppointment] = useState<Appointment | null>(null);
    const [hoveredDropId, setHoveredDropId] = useState<string | null>(null);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 8 },
        }),
    );

    // Initial scroll to working hours
    const scrollToWorkingHours = useCallback(() => {
        if (didInitialScroll.current || !scrollRef.current) return;
        didInitialScroll.current = true;

        const firstWorkingHour = workingHours
            .filter((w) => w.is_working)
            .map((w) => {
                const parts = w.start_time.split(':');

                return parseInt(parts[0], 10);
            })
            .filter((h) => !isNaN(h))
            .sort((a, b) => a - b)[0];

        if (firstWorkingHour !== undefined && firstWorkingHour > dayStartHour) {
            const offset = (firstWorkingHour - dayStartHour) * HOUR_HEIGHT - 20;
            scrollRef.current.scrollTop = Math.max(0, offset);
        }
    }, [workingHours, dayStartHour]);

    useEffect(() => {
        didInitialScroll.current = false;
        const timer = setTimeout(scrollToWorkingHours, 50);

        return () => clearTimeout(timer);
    }, [scrollToWorkingHours]);

    function handleDragStart(event: DragStartEvent) {
        const id = String(event.active.id);
        const appt = localAppointments.find((a) => a.id === id);

        if (appt) {
            setActiveAppointment(appt);
        }
    }

    function handleDragOver(event: DragOverEvent) {
        setHoveredDropId(event.over ? String(event.over.id) : null);
    }

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;

        setActiveAppointment(null);
        setHoveredDropId(null);

        if (!over) {
            return;
        }

        const overId = String(over.id);

        if (!overId.includes('__')) {
            return;
        }

        const [newDate, newTime] = overId.split('__');

        const apptId = String(active.id);
        const appt = localAppointments.find((a) => a.id === apptId);

        if (!appt) {
            return;
        }

        if (appt.date === newDate && appt.time === newTime) {
            return;
        }

        onRescheduleByDrag(apptId, newDate, newTime);
    }

    return (
        <div
            ref={scrollRef}
            className="relative h-full w-full overflow-x-auto overflow-y-auto scrollbar-thin"
        >
            {/* Day Headers — sticky at top */}
            <div className="sticky top-0 z-30 flex min-w-[980px] border-b border-[var(--color-line)] bg-white/96 backdrop-blur-sm dark:bg-[var(--color-cal-surface)]/96">
                <div className="sticky left-0 z-40 flex w-[72px] min-w-[72px] items-center justify-center border-r border-[var(--color-line-soft)] bg-white/96 py-3 backdrop-blur-sm dark:bg-[var(--color-cal-surface)]/96">
                    <span className="text-[10px] font-semibold text-[var(--color-graphite)]">{formatGmtOffset(timezone)}</span>
                </div>
                <div className="grid min-w-[908px] flex-1 grid-cols-7">
                    {weekDates.map((date, idx) => {
                        const todayMark = isToday(date);

                        return (
                            <div
                                key={`h-${idx}`}
                                className="relative flex h-[58px] items-center justify-center border-r border-[var(--color-line-soft)] last:border-r-0"
                                style={todayMark ? {
                                    backgroundImage: 'linear-gradient(to right, rgba(255,90,31,0.08) 0%, rgba(255,90,31,0.02) 40%, transparent 70%)',
                                } : undefined}
                            >
                                <div className="flex items-center gap-2.5">
                                    <span className="text-xs font-semibold text-[var(--color-graphite)]">
                                        {DAY_NAMES[idx]}
                                    </span>
                                    <span
                                        className={`flex size-[30px] items-center justify-center rounded-full text-sm font-bold ${
                                            todayMark
                                                ? 'bg-[var(--color-orange)] text-white shadow-[0_5px_14px_rgba(255,90,31,0.2)]'
                                                : 'text-[var(--color-ink)]'
                                        }`}
                                    >
                                        {date.getDate()}
                                    </span>
                                </div>
                                {todayMark && (
                                    <span className="absolute bottom-1 text-[9px] font-bold text-[var(--color-orange)]">
                                        Сегодня
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Grid Body — time + slots */}
            <DndContext sensors={sensors} onDragStart={handleDragStart} onDragOver={handleDragOver} onDragEnd={handleDragEnd}>
                <div className="flex min-w-[980px]">
                    {/* Time Column — sticky left */}
                    <div className="sticky left-0 z-20 w-[72px] min-w-[72px] border-r border-[var(--color-line-soft)] bg-white dark:bg-[var(--color-cal-surface)]">
                        {gridHours.map((hour) => {
                            const slotHeightPx = (slotInterval / 60) * HOUR_HEIGHT;
                            const labels: React.ReactNode[] = [];

                            for (let m = 0; m < 60; m += slotInterval) {
                                labels.push(
                                    <div
                                        key={`${hour}-${m}`}
                                        style={{ height: slotHeightPx }}
                                        className="flex items-start justify-end border-b border-[var(--color-line-soft)] pr-3 pt-0.5 font-mono text-[11px] text-[var(--color-graphite)]"
                                    >
                                        {m === 0 ? `${String(hour).padStart(2, '0')}:00` : ''}
                                    </div>,
                                );
                            }

                            return labels;
                        })}
                    </div>

                    {/* Day Columns with Appointment Cards */}
                    <div className="grid min-w-[908px] flex-1 grid-cols-7">
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
                            const todayMark = isToday(date);

                            return (
                                <div
                                    key={`col-${dayIdx}`}
                                    className="relative overflow-hidden border-r border-[var(--color-line-soft)] last:border-r-0"
                                >
                                    {todayMark && (
                                        <div className="pointer-events-none absolute inset-0 z-0 bg-gradient-to-r from-orange-500/[0.07] via-orange-500/[0.02] to-transparent dark:from-orange-400/[0.09] dark:via-orange-400/[0.03] dark:to-transparent" style={{ backgroundSize: '100% 100%' }} />
                                    )}
                                    {gridHours.map((hour) => {
                                        const slots: React.ReactNode[] = [];

                                        for (let m = 0; m < 60; m += slotInterval) {
                                            const timeStr = `${String(hour).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
                                            slots.push(
                                                <DroppableSlot
                                                    key={`${hour}-${m}`}
                                                    id={`${dateKey}__${timeStr}`}
                                                    style={{ height: slotHeightPx }}
                                                    className="group relative border-b border-[var(--color-line-soft)] transition-colors hover:bg-[var(--color-line-soft)]"
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
                                                    <span className="pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 select-none text-[10px] font-medium text-slate-400 opacity-0 transition-opacity group-hover:opacity-100 dark:text-zinc-500">
                                                        {timeStr}
                                                    </span>
                                                </DroppableSlot>,
                                            );
                                        }

                                        return slots;
                                    })}
                                    {/* Ghost Appointment */}
                                    {isBookingDay && ghostHeight > 0 && (
                                        <div
                                            className={`pointer-events-none absolute z-10 mx-1 rounded-md border-2 border-dashed transition-shadow ${
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
                                            dayEndHour={DAY_END_HOUR}
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
                                    {todayMark && (
                                        <CurrentTimeLine dayStartHour={DAY_START_HOUR} gridHours={gridHours} />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <DragOverlay>
                    {activeAppointment ? (
                        <div
                            className="pointer-events-none z-50 w-56 rounded-lg border-l-4 border-blue-500 bg-white px-2 py-1 shadow-lg dark:bg-[var(--color-cal-surface)]"
                        >
                            <p className="font-mono text-[10px] text-gray-400">
                                {activeAppointment.time} – {getEndTime(activeAppointment.time, activeAppointment.duration)}
                            </p>
                            {(() => {
                                const newTime = hoveredDropId?.split('__')[1];

                                if (newTime && newTime !== activeAppointment.time) {
                                    return (
                                        <p className="font-mono text-[10px] font-bold text-blue-600">
                                            → {newTime} – {getEndTime(newTime, activeAppointment.duration)}
                                        </p>
                                    );
                                }

                                return null;
                            })()}
                            <p className="truncate text-xs font-semibold">
                                {activeAppointment.client_name}
                            </p>
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>
        </div>
    );
}
