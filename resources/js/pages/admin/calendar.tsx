import { useState, useMemo, useEffect } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { User } from 'lucide-react';
import { MONTHS_RU } from '@/lib/locale';
import DateControlPanel from '@/components/calendar/DateControlPanel';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';
import { AppointmentStatus } from '@/types/appointment-status';
import { useCalendarActions } from '@/hooks/useCalendarActions';
import { useCalendarData } from '@/hooks/useCalendarData';
import type { PageProps, Appointment, ClientOption } from './components/calendar/types';
import {
    getWeekDates, formatDateRange, dateToKey, timeToMinutes,
} from './components/calendar/helpers';
import { WeekView } from './components/calendar/WeekView';
import { DayView } from './components/calendar/DayView';
import { MonthView } from './components/calendar/MonthView';
import { CalendarLegend } from './components/calendar/CalendarLegend';
import { AppointmentDetailDialog } from './components/calendar/AppointmentDetailDialog';
import { RescheduleDialog } from './components/calendar/RescheduleDialog';
import { NewAppointmentDialog } from './components/calendar/NewAppointmentDialog';
import { WarningDialog } from './components/calendar/WarningDialog';

/* ═══════════════ Main Calendar Page ═══════════════ */

export default function CalendarPage() {
    const { appointments: initialAppointments = [], initialBlockedTimes: initialBlockedTimes = [], clients = [], services = [], slotInterval = 30, workingHours = [], timezoneConfirmed = false, timezone = 'Europe/Moscow', prefillClientId, auth, masters = [], dateRange: loadedRange } = usePage<PageProps>().props;

    // ═══════════════ Selected Appointment State (hoisted for both hooks) ═══════════════
    const [selected, setSelected] = useState<Appointment | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);

    // ═══════════════ UI Navigation State ═══════════════
    const [weekOffset, setWeekOffset] = useState(0);
    const [monthOffset, setMonthOffset] = useState(0);
    const [dayOffset, setDayOffset] = useState(0);
    const [viewMode, setViewMode] = useState<'week' | 'day' | 'month'>('week');
    const [selectedMasterId, setSelectedMasterId] = useState<string>('all');
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
        });
    }, [dayDate]);

    // ═══════════════ Appointments Data ═══════════════
    const weekDateKeys = useMemo(() => weekDates.map(dateToKey), [weekDates]);

    const filteredAppointments = useMemo(() => {
        if (selectedMasterId === 'all') {
return initialAppointments;
}

        return initialAppointments.filter(app => String(app.master_id) === selectedMasterId);
    }, [initialAppointments, selectedMasterId]);

    const filteredBlockedTimes = useMemo(() => {
        if (selectedMasterId === 'all') {
return initialBlockedTimes;
}

        return initialBlockedTimes.filter(bt => String(bt.user_id) === selectedMasterId);
    }, [initialBlockedTimes, selectedMasterId]);

    const dayAppointments = useMemo(
        () => (initialAppointments as Appointment[]).filter((a) => a.status !== AppointmentStatus.Cancelled),
        [initialAppointments],
    );

    const {
        localAppointments,
        getAppointmentsForDay,
        getBlockedTimesForDay,
        applyOptimisticMove,
        rollbackAppointment,
        confirmOptimistic,
    } = useCalendarData({
        initialAppointments: filteredAppointments,
        initialBlockedTimes: filteredBlockedTimes,
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
        updateStatus,
        deleteAppointment,
        openReschedule,
        submitReschedule,
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
    });

    // ═══════════════ Grid Computed ═══════════════

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

        if (minHour >= maxHour) {
            minHour = 8;
            maxHour = 21;
        }

        for (const a of localAppointments) {
            if (!a.time || a.duration == null) {
continue;
}

            const startMin = timeToMinutes(a.time);
            const endMin = startMin + a.duration;
            minHour = Math.min(minHour, Math.floor(startMin / 60));
            maxHour = Math.max(maxHour, Math.ceil(endMin / 60));
        }

        minHour = Math.max(0, minHour);
        maxHour = Math.min(24, maxHour);

        return Array.from({ length: maxHour - minHour }, (_, i) => minHour + i);
    }, [workingHours, localAppointments]);

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

    return (
        <>
            <Head title="Календарь — Вовремя" />

            <AdminLayout title="Рабочий календарь" auth={auth}>
                        <div className="space-y-4">
                            <TimezoneConfirmBanner confirmed={timezoneConfirmed} />

                            {/* ─── Date Control Panel ─── */}
                            <DateControlPanel
                                viewMode={viewMode}
                                dateLabel={viewMode === 'day' ? dayRangeStr : viewMode === 'week' ? dateRangeStr : monthRangeStr}
                                onPrev={() => viewMode === 'day' ? setDayOffset((d) => d - 1) : viewMode === 'week' ? setWeekOffset((w) => w - 1) : setMonthOffset((m) => m - 1)}
                                onNext={() => viewMode === 'day' ? setDayOffset((d) => d + 1) : viewMode === 'week' ? setWeekOffset((w) => w + 1) : setMonthOffset((m) => m + 1)}
                                onToday={() => {
 setWeekOffset(0); setMonthOffset(0); setDayOffset(0); 
}}
                                onSetView={setViewMode}
                                onNewAppointment={openNewAppointment}
                                masters={masters}
                                selectedMasterId={selectedMasterId}
                                onMasterChange={setSelectedMasterId}
                            />

                            {/* ─── Booking Mode Banner ─── */}
                            {activeBookingClient && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 shadow-xs transition-all dark:border-indigo-800 dark:bg-indigo-950/40">
                                    <div className="flex items-center gap-3">
                                        <div className="flex size-8 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/60">
                                            <User className="size-4 text-indigo-600 dark:text-indigo-400" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-indigo-900 dark:text-indigo-200">
                                                Режим записи
                                            </p>
                                            <p className="text-xs text-indigo-600 dark:text-indigo-400">
                                                Клиент: {activeBookingClient.name}
                                                {bookingModeService && (
                                                    <> — {bookingModeService.title} ({bookingModeService.duration_minutes} мин)</>
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Select value={bookingModeServiceId} onValueChange={setBookingModeServiceId}>
                                            <SelectTrigger className="h-8 w-[200px] border-indigo-200 bg-white text-xs dark:border-indigo-700 dark:bg-indigo-900/40">
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
                                            className="rounded-lg px-3 py-1.5 text-xs font-medium text-indigo-600 transition-colors hover:bg-indigo-100 dark:text-indigo-400 dark:hover:bg-indigo-900/40"
                                        >
                                            Отменить
                                        </button>
                                    </div>
                                </div>
                            )}

                            {/* ─── Calendar Content ─── */}
                            {viewMode === 'day' ? (
                                <DayView
                                    dayDate={dayDate}
                                    dayDateKey={dayDateKey}
                                    masters={masters}
                                    gridHours={gridHours}
                                    dayStartHour={DAY_START_HOUR}
                                    slotInterval={slotInterval}
                                    workingHours={workingHours}
                                    localAppointments={dayAppointments}
                                    blockedTimes={initialBlockedTimes}
                                    onSlotClick={(date, time, masterId) => openNewAppointmentForDate(date, time)}
                                    onAppointmentClick={openDetail}
                                    isToday={isToday}
                                    onRescheduleByDrag={rescheduleByDrag}
                                />
                            ) : viewMode === 'month' ? (
                                <MonthView
                                    appointments={filteredAppointments}
                                    centerDate={monthCenterDate}
                                    onDayClick={openDetail}
                                    onEmptyDayClick={(dateKey) => openNewAppointmentForDate(dateKey)}
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
                                    onRescheduleByDrag={rescheduleByDrag}
                                />
                            )}

                            {/* ─── Legend ─── */}
                            <CalendarLegend />
                        </div>
            </AdminLayout>

            {/* ─── Appointment Detail Dialog ─── */}
            <AppointmentDetailDialog
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                selected={selected}
                isProcessing={isProcessing}
                onUpdateStatus={updateStatus}
                onReschedule={openReschedule}
                onDelete={deleteAppointment}
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
