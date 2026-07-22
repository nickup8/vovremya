import { useState, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { User } from 'lucide-react';
import { MONTHS_RU } from '@/lib/locale';
import DateControlPanel from '@/components/calendar/DateControlPanel';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';
import { PageProps } from './components/calendar/types';
import {
    getWeekDates, formatDateRange, dateToKey,
} from './components/calendar/helpers';
import { WeekView } from './components/calendar/WeekView';
import { MonthView } from './components/calendar/MonthView';
import { CalendarLegend } from './components/calendar/CalendarLegend';
import { AppointmentDetailDialog } from './components/calendar/AppointmentDetailDialog';
import { RescheduleDialog } from './components/calendar/RescheduleDialog';
import { NewAppointmentDialog } from './components/calendar/NewAppointmentDialog';
import { WarningDialog } from './components/calendar/WarningDialog';
import { useCalendarActions } from '@/hooks/useCalendarActions';
import { useCalendarData } from '@/hooks/useCalendarData';

/* ═══════════════ Main Calendar Page ═══════════════ */

export default function CalendarPage() {
    const { appointments: initialAppointments = [], initialBlockedTimes: initialBlockedTimes = [], clients = [], services = [], slotInterval = 30, workingHours = [], timezoneConfirmed = false, timezone = 'Europe/Moscow', prefillClientId, auth, masters = [] } = usePage<PageProps>().props;

    const {
        selected, setSelected,
        sheetOpen, setSheetOpen,
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
    });

    // ═══════════════ UI Navigation State ═══════════════
    const [weekOffset, setWeekOffset] = useState(0);
    const [monthOffset, setMonthOffset] = useState(0);
    const [viewMode, setViewMode] = useState<'week' | 'month'>('week');
    const [selectedMasterId, setSelectedMasterId] = useState<string>('all');

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

    // ═══════════════ Appointments Data ═══════════════
    const weekDateKeys = useMemo(() => weekDates.map(dateToKey), [weekDates]);

    const filteredAppointments = useMemo(() => {
        if (selectedMasterId === 'all') return initialAppointments;
        return initialAppointments.filter(app => String(app.master_id) === selectedMasterId);
    }, [initialAppointments, selectedMasterId]);

    const filteredBlockedTimes = useMemo(() => {
        if (selectedMasterId === 'all') return initialBlockedTimes;
        return initialBlockedTimes.filter(bt => String(bt.user_id) === selectedMasterId);
    }, [initialBlockedTimes, selectedMasterId]);

    const { localAppointments, getAppointmentsForDay, getBlockedTimesForDay } = useCalendarData({
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

    // ═══════════════ Grid Computed ═══════════════

    const gridHours = useMemo(() => {
        let minHour = 24;
        let maxHour = 0;
        for (const wh of workingHours) {
            if (wh.is_working && wh.start_time && wh.end_time) {
                const sh = parseInt(wh.start_time.split(':')[0], 10);
                const eh = parseInt(wh.end_time.split(':')[0], 10);
                if (sh < minHour) minHour = sh;
                if (eh > maxHour) maxHour = eh;
            }
        }
        if (minHour >= maxHour) {
            minHour = 8;
            maxHour = 21;
        }
        return Array.from({ length: maxHour - minHour }, (_, i) => minHour + i);
    }, [workingHours]);

    const DAY_START_HOUR = gridHours.length > 0 ? gridHours[0] : 8;

    function isToday(date: Date): boolean {
        return (
            date.getDate() === today.getDate() &&
            date.getMonth() === today.getMonth() &&
            date.getFullYear() === today.getFullYear()
        );
    }

    function toggleViewMode() {
        setViewMode((m) => (m === 'week' ? 'month' : 'week'));
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
                                dateLabel={viewMode === 'week' ? dateRangeStr : monthRangeStr}
                                onPrev={() => viewMode === 'week' ? setWeekOffset((w) => w - 1) : setMonthOffset((m) => m - 1)}
                                onNext={() => viewMode === 'week' ? setWeekOffset((w) => w + 1) : setMonthOffset((m) => m + 1)}
                                onToday={() => { setWeekOffset(0); setMonthOffset(0); }}
                                onToggleView={toggleViewMode}
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
                            {viewMode === 'month' ? (
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
                                    initialBlockedTimes={filteredBlockedTimes}
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
                clients={clients}
                services={services}
                onSubmit={submitNewAppointment}
                slotInterval={slotInterval}
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
