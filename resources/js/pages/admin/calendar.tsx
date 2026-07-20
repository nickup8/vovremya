import { useState, useMemo, useEffect } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { echo } from '@laravel/echo-react';
import { toast } from 'sonner';
import {
    ChevronLeft, ChevronRight, Plus,
    CalendarDays, User,
} from 'lucide-react';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';
import { AppointmentStatus } from '@/types/appointment-status';
import {
    Appointment, BlockedTime, ClientOption, ServiceOption, WorkingHour, PageProps,
    AppointmentWithCollision,
} from './components/calendar/types';
import { STATUS_STYLES, DAY_NAMES, HOUR_HEIGHT, MINUTE_HEIGHT } from './components/calendar/constants';
import {
    timeToMinutes, getEndTime, getWeekDates, formatDateRange, dateToKey,
    isSameDay, hasCollision,
} from './components/calendar/helpers';
import { calculateCollisions } from './components/calendar/collision';
import { AppointmentCard } from './components/calendar/AppointmentCard';
import { BlockedTimeCard } from './components/calendar/BlockedTimeCard';
import { BreakZone } from './components/calendar/BreakZone';
import { MonthView } from './components/calendar/MonthView';
import { CalendarLegend } from './components/calendar/CalendarLegend';
import { AppointmentDetailDialog } from './components/calendar/AppointmentDetailDialog';
import { RescheduleDialog } from './components/calendar/RescheduleDialog';
import { NewAppointmentDialog } from './components/calendar/NewAppointmentDialog';
import { WarningDialog } from './components/calendar/WarningDialog';

/* ═══════════════ Main Calendar Page ═══════════════ */

export default function CalendarPage() {
    const { appointments: initialAppointments = [], initialBlockedTimes: initialBlockedTimes = [], clients = [], services = [], slotInterval = 30, workingHours = [], timezoneConfirmed = false, timezone = 'Europe/Moscow', prefillClientId, auth } = usePage<PageProps>().props;
    const [selected, setSelected] = useState<Appointment | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [weekOffset, setWeekOffset] = useState(0);
    const [monthOffset, setMonthOffset] = useState(0);
    const [viewMode, setViewMode] = useState<'week' | 'month'>('week');
    const [breakWarningOpen, setBreakWarningOpen] = useState(false);
    const [breakWarningMessage, setBreakWarningMessage] = useState('');
    const [pendingReschedule, setPendingReschedule] = useState<{ appointmentId: number; date: string; time: string } | null>(null);
    const [outsideHoursOpen, setOutsideHoursOpen] = useState(false);
    const [outsideHoursMessage, setOutsideHoursMessage] = useState('');
    const [pendingOutsideHours, setPendingOutsideHours] = useState<{ appointmentId?: number; date: string; time: string } | null>(null);
    const [newAppointmentOpen, setNewAppointmentOpen] = useState(false);
    const [rescheduleOpen, setRescheduleOpen] = useState(false);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleTime, setRescheduleTime] = useState('');
    const [bookingModeServiceId, setBookingModeServiceId] = useState<string>('');
    const [hoveredSlot, setHoveredSlot] = useState<{ date: string; time: string } | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);

    const newAppointmentForm = useForm({
        client_id: '',
        service_id: '',
        date: '',
        time: '',
        ignore_warnings: false,
        confirm_outside_hours: false,
    });

    const activeBookingClient = prefillClientId
        ? clients.find((c) => c.id === prefillClientId) ?? null
        : null;

    const bookingModeService = bookingModeServiceId
        ? services.find((s) => String(s.id) === bookingModeServiceId) ?? null
        : null;

    function cancelBookingMode() {
        setBookingModeServiceId('');
        setHoveredSlot(null);
        router.visit('/admin/calendar', { replace: true, only: [] });
    }

    function clearBookingMode() {
        setBookingModeServiceId('');
        setHoveredSlot(null);
        if (prefillClientId) {
            window.history.replaceState({}, '', '/admin/calendar');
        }
    }

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

    const [localAppointments, setLocalAppointments] = useState<Appointment[]>(
        initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled),
    );

    // Синхронизация с Inertia-пропсами (при навигации по неделям/месяцам)
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
            // Записи нет в localAppointments (отменена или вне окна) — закрываем диалог
            setSheetOpen(false);
            setSelected(null);
        }
    }, [localAppointments]);

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

    function updateStatus(status: AppointmentStatus) {
        if (!selected || isProcessing) return;
        setIsProcessing(true);
        router.patch(`/admin/appointments/${selected.id}/status`, { status }, {
            preserveScroll: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.status) {
                    toast.error(errors.status);
                }
            },
            onFinish: () => {
                setIsProcessing(false);
                setSheetOpen(false);
                setSelected(null);
            },
        });
    }

    function deleteAppointment() {
        if (!selected || isProcessing) return;
        setIsProcessing(true);
        router.patch(`/admin/appointments/${selected.id}/status`, { status: AppointmentStatus.Cancelled }, {
            preserveScroll: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.status) {
                    toast.error(errors.status);
                }
            },
            onFinish: () => {
                setIsProcessing(false);
                setSheetOpen(false);
                setSelected(null);
            },
        });
    }

    function generateTimeOptions(interval: number, dateStr: string, tz: string): string[] {
        const options: string[] = [];
        const now = new Date(new Date().toLocaleString('en-US', { timeZone: tz }));
        const todayStr = dateToKey(now);
        const isToday = dateStr === todayStr;

        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += interval) {
                const timeStr = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
                if (isToday) {
                    const slotDate = new Date(`${dateStr}T${timeStr}:00`);
                    const slotInTz = new Date(slotDate.toLocaleString('en-US', { timeZone: tz }));
                    if (slotInTz < now) continue;
                }
                options.push(timeStr);
            }
        }
        return options;
    }

    const timeOptions = useMemo(() => generateTimeOptions(slotInterval, rescheduleDate || dateToKey(new Date()), timezone), [slotInterval, rescheduleDate, timezone]);

    function openReschedule() {
        if (!selected) return;
        setRescheduleDate(selected.date);
        setRescheduleTime(selected.time);
        setRescheduleOpen(true);
        setSheetOpen(false);
    }

    function submitReschedule() {
        if (!selected || !rescheduleDate || !rescheduleTime) return;

        router.patch(`/admin/appointments/${selected.id}/status`, {
            start_time: `${rescheduleDate} ${rescheduleTime}:00`,
        }, {
            preserveScroll: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.lunch_intersection) {
                    setBreakWarningMessage(errors.lunch_intersection);
                    setPendingReschedule({ appointmentId: selected.id, date: rescheduleDate, time: rescheduleTime });
                    setBreakWarningOpen(true);
                }
                if (errors.outside_working_hours) {
                    setOutsideHoursMessage(errors.outside_working_hours);
                    setPendingOutsideHours({ appointmentId: selected.id, date: rescheduleDate, time: rescheduleTime });
                    setOutsideHoursOpen(true);
                }
                if (errors.time) {
                    toast.error(errors.time);
                }
            },
            onFinish: () => {
                setRescheduleOpen(false);
                setSelected(null);
            },
        });
    }

    function confirmRescheduleWithBreak() {
        if (pendingReschedule) {
            router.patch(`/admin/appointments/${pendingReschedule.appointmentId}/status`, {
                start_time: `${pendingReschedule.date} ${pendingReschedule.time}:00`,
                ignore_warnings: true,
            }, {
                preserveScroll: true,
                only: ['appointments'],
                onError: (errors: Record<string, string>) => {
                    if (errors.time) {
                        toast.error(errors.time);
                    }
                },
            });

            setBreakWarningOpen(false);
            setPendingReschedule(null);
            setBreakWarningMessage('');
        } else if (newAppointmentOpen) {
            submitNewAppointmentIgnoreBreak();
        }
    }

    function cancelReschedule() {
        setBreakWarningOpen(false);
        setPendingReschedule(null);
        setBreakWarningMessage('');
    }

    function confirmOutsideHours() {
        if (pendingOutsideHours?.appointmentId) {
            router.patch(`/admin/appointments/${pendingOutsideHours.appointmentId}/status`, {
                start_time: `${pendingOutsideHours.date} ${pendingOutsideHours.time}:00`,
                confirm_outside_hours: true,
            }, {
                preserveScroll: true,
                only: ['appointments'],
                onError: (errors: Record<string, string>) => {
                    if (errors.time) {
                        toast.error(errors.time);
                    }
                },
            });

            setOutsideHoursOpen(false);
            setPendingOutsideHours(null);
            setOutsideHoursMessage('');
        } else if (newAppointmentOpen) {
            submitNewAppointmentConfirmOutside();
        }
    }

    function cancelOutsideHours() {
        setOutsideHoursOpen(false);
        setPendingOutsideHours(null);
        setOutsideHoursMessage('');
    }

    function openNewAppointment() {
        newAppointmentForm.reset();
        newAppointmentForm.setData('date', dateToKey(new Date()));
        newAppointmentForm.setData('time', '09:00');
        setNewAppointmentOpen(true);
    }

    function openNewAppointmentForDate(dateKey: string, time?: string) {
        if (activeBookingClient && !bookingModeServiceId) {
            return;
        }
        newAppointmentForm.reset();
        newAppointmentForm.setData('date', dateKey);
        newAppointmentForm.setData('time', time || '09:00');
        if (activeBookingClient) {
            newAppointmentForm.setData('client_id', activeBookingClient.id);
        }
        if (bookingModeServiceId) {
            newAppointmentForm.setData('service_id', bookingModeServiceId);
        }
        setNewAppointmentOpen(true);
    }

    function submitNewAppointment(e: React.FormEvent) {
        e.preventDefault();
        if (!newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time) return;

        newAppointmentForm.post('/admin/calendar/appointments', {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                if (errors.lunch_intersection) {
                    setBreakWarningMessage(errors.lunch_intersection);
                    setPendingReschedule(null);
                    setBreakWarningOpen(true);
                }
                if (errors.outside_working_hours) {
                    setOutsideHoursMessage(errors.outside_working_hours);
                    setPendingOutsideHours(null);
                    setOutsideHoursOpen(true);
                }
                if (errors.time) {
                    toast.error(errors.time);
                }
                if (errors.client_id) {
                    toast.error(errors.client_id);
                }
            },
            onSuccess: () => {
                setNewAppointmentOpen(false);
                newAppointmentForm.reset();
                clearBookingMode();
            },
        });
    }

    function submitNewAppointmentIgnoreBreak() {
        if (!newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time) return;

        newAppointmentForm.setData('ignore_warnings', true);
        newAppointmentForm.post('/admin/calendar/appointments', {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                if (errors.time) {
                    toast.error(errors.time);
                }
                if (errors.client_id) {
                    toast.error(errors.client_id);
                }
            },
            onSuccess: () => {
                setNewAppointmentOpen(false);
                setBreakWarningOpen(false);
                newAppointmentForm.reset();
                clearBookingMode();
            },
        });
    }

    function submitNewAppointmentConfirmOutside() {
        if (!newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time) return;

        newAppointmentForm.setData('confirm_outside_hours', true);
        newAppointmentForm.post('/admin/calendar/appointments', {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                if (errors.time) {
                    toast.error(errors.time);
                }
                if (errors.client_id) {
                    toast.error(errors.client_id);
                }
            },
            onSuccess: () => {
                setNewAppointmentOpen(false);
                setOutsideHoursOpen(false);
                newAppointmentForm.reset();
                clearBookingMode();
            },
        });
    }

    function openDetail(appointment: Appointment) {
        setSelected(appointment);
        setSheetOpen(true);
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
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => viewMode === 'week' ? setWeekOffset((w) => w - 1) : setMonthOffset((m) => m - 1)}
                                        className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"
                                    >
                                        <ChevronLeft className="size-4 text-slate-600 dark:text-zinc-400" />
                                    </button>
                                    <h2 className="text-sm font-semibold text-slate-900 dark:text-zinc-100 md:text-base">
                                        {viewMode === 'week' ? dateRangeStr : monthRangeStr}
                                    </h2>
                                    <button
                                        onClick={() => viewMode === 'week' ? setWeekOffset((w) => w + 1) : setMonthOffset((m) => m + 1)}
                                        className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"
                                    >
                                        <ChevronRight className="size-4 text-slate-600 dark:text-zinc-400" />
                                    </button>
                                    <button
                                        onClick={() => { setWeekOffset(0); setMonthOffset(0); }}
                                        className="ml-2 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        Сегодня
                                    </button>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={toggleViewMode}
                                        className="flex items-center gap-1.5 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        <CalendarDays className="size-3.5" />
                                        {viewMode === 'week' ? 'Месяц' : 'Неделя'}
                                    </button>
                                    <button
                                        onClick={openNewAppointment}
                                        className="flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
                                    >
                                        <Plus className="size-3.5" />
                                        Новая запись
                                    </button>
                                </div>
                            </div>

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
                                /* ─── Week Schedule Grid ─── */
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
                                                                        className="border-b border-slate-100 transition-colors hover:bg-slate-50 dark:border-zinc-800/40 dark:hover:bg-zinc-800/30"
                                                                        onMouseEnter={() => {
                                                                            if (activeBookingClient && bookingModeServiceId) {
                                                                                setHoveredSlot({ date: dateKey, time: timeStr });
                                                                            }
                                                                        }}
                                                                        onMouseLeave={() => {
                                                                            if (hoveredSlot?.date === dateKey && hoveredSlot?.time === timeStr) {
                                                                                setHoveredSlot(null);
                                                                            }
                                                                        }}
                                                                        onClick={() => {
                                                                            if (activeBookingClient && bookingModeServiceId) {
                                                                                if (hasCollision(dateKey, timeStr, bookingModeService?.duration_minutes ?? 60, localAppointments)) {
                                                                                    return;
                                                                                }
                                                                            }
                                                                            openNewAppointmentForDate(dateKey, timeStr);
                                                                        }}
                                                                    />,
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
                                                                onClick={() => openDetail(appt)}
                                                                dayStartHour={DAY_START_HOUR}
                                                            />
                                                        ))}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                </div>
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
