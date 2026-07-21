import { useState, useMemo, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { echo } from '@laravel/echo-react';
import { User } from 'lucide-react';
import DateControlPanel from '@/components/calendar/DateControlPanel';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';
import { AppointmentStatus } from '@/types/appointment-status';
import {
    Appointment, BlockedTime, PageProps,
} from './components/calendar/types';
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

/* ═══════════════ Main Calendar Page ═══════════════ */

export default function CalendarPage() {
    const { appointments: initialAppointments = [], initialBlockedTimes: initialBlockedTimes = [], clients = [], services = [], slotInterval = 30, workingHours = [], timezoneConfirmed = false, timezone = 'Europe/Moscow', prefillClientId, auth } = usePage<PageProps>().props;

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
        const months = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
        return `${months[monthCenterDate.getMonth()]} ${monthCenterDate.getFullYear()}`;
    }, [monthCenterDate]);

    // ═══════════════ Appointments Data ═══════════════
    const [localAppointments, setLocalAppointments] = useState<Appointment[]>(
        initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled),
    );

    useEffect(() => {
        setLocalAppointments(
            initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled),
        );
    }, [initialAppointments]);

    // WebSocket: подписка на broadcast-события записей
    useEffect(() => {
        if (!auth?.user?.id) return;

        const channelName = `App.Models.User.${auth.user.id}`;
        const channel = echo<'reverb'>().private(channelName)
            .listen('.AppointmentCreated', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (prev.some((a) => a.id === appointment.id)) return prev;
                    if (appointment.status === AppointmentStatus.Cancelled) return prev;
                    return [...prev, appointment];
                });
            })
            .listen('.AppointmentStatusChanged', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        return prev.filter((a) => a.id !== appointment.id);
                    }
                    return prev.map((a) => (a.id === appointment.id ? appointment : a));
                });
            })
            .listen('.AppointmentRescheduled', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        return prev.filter((a) => a.id !== appointment.id);
                    }
                    return prev.map((a) => (a.id === appointment.id ? appointment : a));
                });
            })
            .listen('.AppointmentUpdated', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        return prev.filter((a) => a.id !== appointment.id);
                    }
                    return prev.map((a) => (a.id === appointment.id ? appointment : a));
                });
            });

        return () => {
            channel.stopListening('.AppointmentCreated');
            channel.stopListening('.AppointmentStatusChanged');
            channel.stopListening('.AppointmentRescheduled');
            channel.stopListening('.AppointmentUpdated');
            echo<'reverb'>().leave(channelName);
        };
    }, [auth?.user?.id]);

    // Синхронизация selected с обновлениями через WebSocket
    useEffect(() => {
        if (!selected) return;
        const updated = localAppointments.find((a) => a.id === selected.id);
        if (updated) {
            setSelected(updated);
        } else if (selected.status !== AppointmentStatus.Cancelled) {
            setSheetOpen(false);
            setSelected(null);
        }
    }, [localAppointments]);

    // ═══════════════ Grid Computed ═══════════════
    const weekDateKeys = useMemo(() => weekDates.map(dateToKey), [weekDates]);

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

    function getAppointmentsForDay(dayIndex: number): Appointment[] {
        const key = weekDateKeys[dayIndex];
        return localAppointments.filter((a) => a.date === key);
    }

    function getBlockedTimesForDay(dayIndex: number): BlockedTime[] {
        const key = weekDateKeys[dayIndex];
        const dayStart = new Date(key + 'T00:00:00');
        const dayEnd = new Date(key + 'T23:59:59');

        return initialBlockedTimes.filter((bt) => {
            const btStart = new Date(bt.start_datetime);
            const btEnd = new Date(bt.end_datetime);
            return btStart <= dayEnd && btEnd >= dayStart;
        });
    }

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
                                    appointments={localAppointments}
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
                                    initialBlockedTimes={initialBlockedTimes}
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
