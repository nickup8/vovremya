import { useState, useMemo, useEffect } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { User, CalendarDays } from 'lucide-react';
import { MONTHS_RU } from '@/lib/locale';
import DateControlPanel from '@/components/calendar/DateControlPanel';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';

import { useCalendarActions } from '@/hooks/useCalendarActions';
import { useCalendarData } from '@/hooks/useCalendarData';
import type { PageProps, Appointment, ClientOption } from './components/calendar/types';
import {
    getWeekDates, formatDateRange, getYearFromDate, dateToKey, timeToMinutes,
} from './components/calendar/helpers';
import { WeekView } from './components/calendar/WeekView';
import { DayView } from './components/calendar/DayView';
import { MonthView } from './components/calendar/MonthView';
import { CalendarLegend } from './components/calendar/CalendarLegend';
import { AppointmentDetailDrawer } from './components/calendar/AppointmentDetailDrawer';
import { RescheduleDialog } from './components/calendar/RescheduleDialog';
import { NewAppointmentDialog } from './components/calendar/NewAppointmentDialog';
import { WarningDialog } from './components/calendar/WarningDialog';

/* ═══════════════ Main Calendar Page ═══════════════ */

export default function CalendarPage() {
    const { appointments: initialAppointments = [], initialBlockedTimes: initialBlockedTimes = [], clients = [], services = [], slotInterval = 30, workingHours = [], timezoneConfirmed = false, timezone = 'Europe/Moscow', prefillClientId, auth, masters = [], dateRange: loadedRange } = usePage<PageProps>().props;

    // ═══════════════ Selected Appointment State (hoisted for both hooks) ═══════════════
    const [selected, setSelected] = useState<Appointment | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [drawerEditMode, setDrawerEditMode] = useState(false);

    // ═══════════════ UI Navigation State ═══════════════
    const [weekOffset, setWeekOffset] = useState(0);
    const [monthOffset, setMonthOffset] = useState(0);
    const [dayOffset, setDayOffset] = useState(0);
    const safeGetItem = (key: string): string | null => {
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                return localStorage.getItem(key);
            }
        } catch {
            return null;
        }

        return null;
    };

    const safeSetItem = (key: string, value: string): void => {
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                localStorage.setItem(key, value);
            }
        } catch {
            return;
        }
    };

    const [viewMode, setViewModeState] = useState<'week' | 'day' | 'month'>(() => {
        const saved = safeGetItem('calendar_view_mode');

        return saved === 'day' || saved === 'week' || saved === 'month' ? saved : 'week';
    });

    const setViewMode = (mode: 'week' | 'day' | 'month') => {
        setViewModeState(mode);
        safeSetItem('calendar_view_mode', mode);
    };

    const [localClients, setLocalClients] = useState<ClientOption[]>(clients);

    const handleClientCreated = (c: ClientOption) => {
        setLocalClients((prev: ClientOption[]) =>
            prev.some((x: ClientOption) => String(x.id) === String(c.id)) ? prev : [c, ...prev],
        );
    };

    const today = new Date();
    const centerDate = useMemo(() => {
        const d = new Date(today);
        d.setDate(d.getDate() + weekOffset * 7);

        return d;
    }, [weekOffset]);
    const weekDates = useMemo(() => getWeekDates(centerDate), [centerDate]);
    const dateRangeStr = useMemo(() => formatDateRange(weekDates), [weekDates]);
    const weekYearLabel = useMemo(() => getYearFromDate(weekDates), [weekDates]);

    const monthCenterDate = useMemo(() => {
        const d = new Date(today);
        d.setMonth(d.getMonth() + monthOffset);

        return d;
    }, [monthOffset]);

    const monthRangeStr = useMemo(() => {
        return `${MONTHS_RU[monthCenterDate.getMonth()]} ${monthCenterDate.getFullYear()}`;
    }, [monthCenterDate]);

    const dayDate = useMemo(() => {
        const d = new Date(today);
        d.setDate(d.getDate() + dayOffset);

        return d;
    }, [dayOffset]);
    const dayDateKey = useMemo(() => dateToKey(dayDate), [dayDate]);
    const dayRangeStr = useMemo(() => {
        return dayDate.toLocaleDateString('ru-RU', {
            day: 'numeric', month: 'long', year: 'numeric', weekday: 'long',
        }).replace(/\s*г\.$/, '');
    }, [dayDate]);

    // ═══════════════ Appointments Data ═══════════════
    const weekDateKeys = useMemo(() => weekDates.map(dateToKey), [weekDates]);

    const {
        localAppointments,
        getAppointmentsForDay,
        getBlockedTimesForDay,
        applyOptimisticMove,
        rollbackAppointment,
        confirmOptimistic,
    } = useCalendarData({
        initialAppointments,
        initialBlockedTimes,
        authUserId: auth?.user?.id,
        weekDateKeys,
        selectedId: selected?.id,
        onSelectedUpdate: setSelected,
        onSelectedExpired: () => {
            setSelected(null);
            setSheetOpen(false);
        },
    });

    const {
        isProcessing,
        newAppointmentOpen, setNewAppointmentOpen,
        rescheduleOpen, setRescheduleOpen,
        rescheduleDate, setRescheduleDate,
        rescheduleTime, setRescheduleTime,
        bookingModeServiceId, setBookingModeServiceId,
        hoveredSlot, setHoveredSlot,
        breakWarningOpen, setBreakWarningOpen,
        breakWarningMessage,
        outsideHoursOpen, setOutsideHoursOpen,
        outsideHoursMessage,
        newAppointmentForm,
        timeOptions,
        activeBookingClient,
        bookingModeService,
        preselectedMasterId,
        updateStatus,
        deleteAppointment,
        openReschedule,
        submitReschedule,
        submitRescheduleInDrawer,
        confirmRescheduleWithBreak,
        cancelReschedule,
        confirmOutsideHours,
        cancelOutsideHours,
        rescheduleByDrag,
        openNewAppointment,
        openNewAppointmentForDate,
        submitNewAppointment,
        openDetail,
        cancelBookingMode,
    } = useCalendarActions({
        clients,
        services,
        slotInterval,
        timezone,
        prefillClientId,
        selected,
        setSelected,
        sheetOpen,
        setSheetOpen,
        applyOptimisticMove,
        rollbackAppointment,
        confirmOptimistic,
        localAppointments,
        masters,
    });

    const rescheduleByDragDay = (apptId: string, newDate: string, newTime: string, newMasterId: string, prevMasterId?: string) =>
        rescheduleByDrag(apptId, newDate, newTime, newMasterId, false, prevMasterId);

    function openDrawerEdit() {
        if (!selected) return;
        setRescheduleDate(selected.date);
        setRescheduleTime(selected.time);
        setDrawerEditMode(true);
    }

    const openDayFromMonth = (dateKey: string) => {
        const clicked = new Date(dateKey + 'T00:00:00');
        const base = new Date(today);
        base.setHours(0, 0, 0, 0);
        const offset = Math.round((clicked.getTime() - base.getTime()) / 86400000);
        setDayOffset(offset);
        setViewMode('day');
    };

    // ═══════════════ Grid Computed ═══════════════

    const visibleAppointments = useMemo(() => {
        if (viewMode === 'day') {
            return localAppointments.filter(a => a.date === dayDateKey);
        }

        const weekKeySet = new Set(weekDateKeys);

        return localAppointments.filter(a => weekKeySet.has(a.date));
    }, [localAppointments, viewMode, weekDateKeys, dayDateKey]);

    const gridHours = useMemo(() => {
        let minHour = 24;
        let maxHour = 0;

        for (const wh of workingHours) {
            if (wh.is_working && wh.start_time && wh.end_time) {
                const sh = parseInt(wh.start_time.split(':')[0], 10);
                const eh = parseInt(wh.end_time.split(':')[0], 10);

                if (sh < minHour) {
minHour = sh;
}

                if (eh > maxHour) {
maxHour = eh;
}
            }
        }

        for (const a of visibleAppointments) {
            if (!a.time || a.duration == null) {
continue;
}

            const startMin = timeToMinutes(a.time);
            const endMin = startMin + a.duration;
            minHour = Math.min(minHour, Math.floor(startMin / 60));
            maxHour = Math.max(maxHour, Math.ceil(endMin / 60));
        }

        if (minHour >= maxHour) {
return [];
}

        minHour = Math.max(0, minHour);
        maxHour = Math.min(24, maxHour);

        return Array.from({ length: maxHour - minHour }, (_, i) => minHour + i);
    }, [workingHours, visibleAppointments]);

    const DAY_START_HOUR = gridHours.length > 0 ? gridHours[0] : 8;

    // ═══════════════ Dynamic Date Range Loading ═══════════════
    useEffect(() => {
        const visibleStart = new Date(centerDate);
        visibleStart.setDate(visibleStart.getDate() - 3);
        const visibleEnd = new Date(centerDate);
        visibleEnd.setDate(visibleEnd.getDate() + 10);

        const bufferStart = new Date(visibleStart);
        bufferStart.setDate(bufferStart.getDate() - 7);
        const bufferEnd = new Date(visibleEnd);
        bufferEnd.setDate(bufferEnd.getDate() + 7);

        const toKey = (d: Date) => d.toISOString().split('T')[0];
        const bufferStartKey = toKey(bufferStart);
        const bufferEndKey = toKey(bufferEnd);

        if (loadedRange && loadedRange.start <= bufferStartKey && loadedRange.end >= bufferEndKey) {
            return;
        }

        router.reload({
            data: {
                start: toKey(bufferStart),
                end: toKey(bufferEnd),
            },
            preserveState: true,
            preserveScroll: true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [weekOffset, monthOffset]);

    function isToday(date: Date): boolean {
        return (
            date.getDate() === today.getDate() &&
            date.getMonth() === today.getMonth() &&
            date.getFullYear() === today.getFullYear()
        );
    }

    const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    const todayCount = initialAppointments.filter((a) => a.date === todayStr).length;

    return (
        <>
            <Head title="Календарь — Вовремя" />

            <AdminLayout title="Календарь" auth={auth} todayCount={todayCount} fullBleed>
                        <div className="flex h-full flex-col overflow-hidden">
                            <TimezoneConfirmBanner confirmed={timezoneConfirmed} />

                            {/* ─── Calendar Toolbar ─── */}
                            <DateControlPanel
                                viewMode={viewMode}
                                dateLabel={viewMode === 'day' ? dayRangeStr : viewMode === 'week' ? dateRangeStr : monthRangeStr}
                                yearLabel={viewMode === 'week' ? weekYearLabel : viewMode === 'day' ? String(dayDate.getFullYear()) : undefined}
                                onPrev={() => viewMode === 'day' ? setDayOffset((d) => d - 1) : viewMode === 'week' ? setWeekOffset((w) => w - 1) : setMonthOffset((m) => m - 1)}
                                onNext={() => viewMode === 'day' ? setDayOffset((d) => d + 1) : viewMode === 'week' ? setWeekOffset((w) => w + 1) : setMonthOffset((m) => m + 1)}
                                onToday={() => {
 setWeekOffset(0); setMonthOffset(0); setDayOffset(0); 
}}
                                onSetView={setViewMode}
                            />

                            {/* ─── Booking Mode Banner ─── */}
                            {activeBookingClient && (
                                <div className="mx-4 flex flex-col gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 shadow-xs transition-all dark:border-indigo-800 dark:bg-indigo-950/40 lg:mx-6 lg:flex-row lg:items-center lg:justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/60">
                                            <User className="size-4 text-indigo-600 dark:text-indigo-400" />
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-indigo-900 dark:text-indigo-200">
                                                Режим записи
                                            </p>
                                            <p className="truncate text-xs text-indigo-600 dark:text-indigo-400">
                                                Клиент: {activeBookingClient.name}
                                                {bookingModeService && (
                                                    <> — {bookingModeService.title} ({bookingModeService.duration_minutes} мин)</>
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Select value={bookingModeServiceId} onValueChange={setBookingModeServiceId}>
                                            <SelectTrigger className="h-8 flex-1 border-indigo-200 bg-white text-xs dark:border-indigo-700 dark:bg-indigo-900/40 lg:w-[200px] lg:flex-none">
                                                <SelectValue placeholder="Выберите услугу" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {services.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>
                                                        {s.title} — {s.duration_minutes} мин
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <button
                                            onClick={cancelBookingMode}
                                            className="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-indigo-600 transition-colors hover:bg-indigo-100 dark:text-indigo-400 dark:hover:bg-indigo-900/40"
                                        >
                                            Отменить
                                        </button>
                                    </div>
                                </div>
                            )}

                            {/* ─── Calendar Grid (full-bleed) ─── */}
                            <div className="flex-1 min-h-0 overflow-hidden">
                            {viewMode !== 'month' && gridHours.length === 0 ? (
                                <div className="flex flex-col items-center justify-center gap-2 py-20 text-gray-400">
                                    <CalendarDays className="size-10 opacity-40" />
                                    <p className="text-sm">Рабочие часы не настроены</p>
                                </div>
                            ) : viewMode === 'day' ? (
                                <DayView
                                    dayDate={dayDate}
                                    dayDateKey={dayDateKey}
                                    masters={masters}
                                    gridHours={gridHours}
                                    dayStartHour={DAY_START_HOUR}
                                    slotInterval={slotInterval}
                                    workingHours={workingHours}
                                    localAppointments={initialAppointments}
                                    blockedTimes={initialBlockedTimes}
                                    onSlotClick={(date, time, masterId) => openNewAppointmentForDate(date, time, masterId)}
                                    onAppointmentClick={openDetail}
                                    isToday={isToday}
                                    onRescheduleByDrag={rescheduleByDragDay}
                                    timezone={timezone}
                                />
                            ) : viewMode === 'month' ? (
                                <MonthView
                                    appointments={initialAppointments}
                                    centerDate={monthCenterDate}
                                    onDayColumnClick={openDayFromMonth}
                                />
                            ) : (
                                <WeekView
                                    weekDates={weekDates}
                                    weekDateKeys={weekDateKeys}
                                    gridHours={gridHours}
                                    dayStartHour={DAY_START_HOUR}
                                    slotInterval={slotInterval}
                                    workingHours={workingHours}
                                    localAppointments={localAppointments}
                                    activeBookingClient={activeBookingClient}
                                    bookingModeServiceId={bookingModeServiceId}
                                    bookingModeService={bookingModeService}
                                    hoveredSlot={hoveredSlot}
                                    onSlotHover={setHoveredSlot}
                                    onSlotClick={openNewAppointmentForDate}
                                    onAppointmentClick={openDetail}
                                    getAppointmentsForDay={getAppointmentsForDay}
                                    getBlockedTimesForDay={getBlockedTimesForDay}
                                    isToday={isToday}
                                    timezone={timezone}
                                    onRescheduleByDrag={rescheduleByDrag}
                                />
                            )}
                            </div>

                            {/* ─── Legend (sticky footer) ─── */}
                            <div className="flex-none border-t border-[var(--color-line)] dark:border-[var(--color-cal-border)]">
                                <CalendarLegend />
                            </div>
                        </div>
            </AdminLayout>

            {/* ─── Appointment Detail Drawer ─── */}
            <AppointmentDetailDrawer
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                selected={selected}
                isProcessing={isProcessing}
                onUpdateStatus={updateStatus}
                onReschedule={openDrawerEdit}
                onDelete={deleteAppointment}
                editMode={drawerEditMode}
                onEditModeChange={setDrawerEditMode}
                editDate={rescheduleDate}
                editTime={rescheduleTime}
                onEditDateChange={setRescheduleDate}
                onEditTimeChange={setRescheduleTime}
                onEditSubmit={() => submitRescheduleInDrawer(() => setDrawerEditMode(false))}
                timeOptions={timeOptions}
            />

            {/* ─── Break Intersection Warning Dialog ─── */}
            <WarningDialog
                open={breakWarningOpen}
                onOpenChange={setBreakWarningOpen}
                title="Пересечение с обедом"
                message={breakWarningMessage}
                confirmLabel="Всё равно перенести"
                onConfirm={confirmRescheduleWithBreak}
                onCancel={cancelReschedule}
            />

            {/* ─── Outside Working Hours Warning Dialog ─── */}
            <WarningDialog
                open={outsideHoursOpen}
                onOpenChange={setOutsideHoursOpen}
                title="Вне рабочего графика"
                message={outsideHoursMessage}
                confirmLabel="Всё равно создать"
                onConfirm={confirmOutsideHours}
                onCancel={cancelOutsideHours}
            />

            {/* ─── New Appointment Dialog ─── */}
            <NewAppointmentDialog
                open={newAppointmentOpen}
                onOpenChange={setNewAppointmentOpen}
                form={newAppointmentForm}
                clients={localClients}
                services={services}
                masters={masters}
                preselectedMasterId={preselectedMasterId}
                onSubmit={submitNewAppointment}
                slotInterval={slotInterval}
                onClientCreated={handleClientCreated}
            />

            {/* ─── Reschedule Dialog ─── */}
            <RescheduleDialog
                open={rescheduleOpen}
                onOpenChange={setRescheduleOpen}
                date={rescheduleDate}
                time={rescheduleTime}
                onDateChange={setRescheduleDate}
                onTimeChange={setRescheduleTime}
                onSubmit={submitReschedule}
                timeOptions={timeOptions}
            />
        </>
    );
}
