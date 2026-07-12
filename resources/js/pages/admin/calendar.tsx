import { useState, useMemo } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    ChevronLeft, ChevronRight, Plus,
    CalendarDays, Clock, User, Phone,
    CheckCircle2, XCircle, Trash2, RotateCw, AlertTriangle,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { formatPhone } from '@/lib/phone';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout';
import TimezoneConfirmBanner from '@/components/admin/TimezoneConfirmBanner';
import { AppointmentStatus } from '@/types/appointment-status';

/* ═══════════════ Types ═══════════════ */

interface Appointment {
    id: number;
    client_name: string;
    client_phone: string | null;
    service: string;
    time: string;
    date: string;
    duration: number;
    price: number;
    status: AppointmentStatus;
}

interface BlockedTime {
    id: number;
    start_datetime: string;
    end_datetime: string;
    reason: string;
}

interface ClientOption {
    id: number;
    name: string;
    phone: string | null;
}

interface ServiceOption {
    id: number;
    title: string;
    duration_minutes: number;
    price: number;
}

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface WorkingHour {
    day_of_week: number;
    start_time: string | null;
    end_time: string | null;
    break_start_time: string | null;
    break_end_time: string | null;
    is_working: boolean;
}

interface PageProps {
    appointments: Appointment[];
    initialBlockedTimes: BlockedTime[];
    clients: ClientOption[];
    services: ServiceOption[];
    slotInterval: number;
    workingHours: WorkingHour[];
    timezoneConfirmed: boolean;
    prefillClientId?: string;
    auth?: { user?: AuthUser };
    [key: string]: unknown;
}

/* ═══════════════ Constants ═══════════════ */

const STATUS_STYLES: Record<AppointmentStatus, { card: string; label: string; dot: string }> = {
    [AppointmentStatus.Booked]: {
        card: 'bg-blue-50 border-blue-500 text-blue-900 dark:bg-blue-950 dark:border-blue-500 dark:text-blue-200',
        label: 'Записан',
        dot: 'bg-blue-500',
    },
    [AppointmentStatus.Paid]: {
        card: 'bg-emerald-50 border-emerald-500 text-emerald-900 dark:bg-emerald-950 dark:border-emerald-500 dark:text-emerald-200',
        label: 'Оплачен',
        dot: 'bg-emerald-500',
    },
    [AppointmentStatus.NoShow]: {
        card: 'bg-rose-50 border-rose-500 text-rose-900 dark:bg-rose-950 dark:border-rose-500 dark:text-rose-200',
        label: 'Неявка',
        dot: 'bg-rose-500',
    },
    [AppointmentStatus.Cancelled]: {
        card: 'bg-zinc-100 border-zinc-300 text-zinc-500 line-through dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-500',
        label: 'Отменён',
        dot: 'bg-zinc-400',
    },
};

const DAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const HOUR_HEIGHT = 80;
const MINUTE_HEIGHT = HOUR_HEIGHT / 60;

/* ═══════════════ Helpers ═══════════════ */

function timeToMinutes(t: string): number {
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
}

function getEndTime(time: string, duration: number): string {
    const total = timeToMinutes(time) + duration;
    const h = Math.floor(total / 60);
    const m = total % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function getWeekDates(center: Date): Date[] {
    const start = new Date(center);
    const day = start.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    start.setDate(start.getDate() + diff);
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        return d;
    });
}

function formatDateRange(dates: Date[]): string {
    const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    const first = dates[0];
    const last = dates[6];
    if (first.getMonth() === last.getMonth()) {
        return `${first.getDate()} – ${last.getDate()} ${months[first.getMonth()]} ${first.getFullYear()}`;
    }
    return `${first.getDate()} ${months[first.getMonth()]} – ${last.getDate()} ${months[last.getMonth()]} ${first.getFullYear()}`;
}

function dateToKey(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function isSameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function hasCollision(date: string, startTime: string, durationMinutes: number, appointments: Appointment[]): boolean {
    const start = timeToMinutes(startTime);
    const end = start + durationMinutes;
    return appointments.some((a) => {
        if (a.date !== date) return false;
        const aStart = timeToMinutes(a.time);
        const aEnd = aStart + a.duration;
        return start < aEnd && end > aStart;
    });
}

function getMonthGrid(center: Date): Date[] {
    const year = center.getFullYear();
    const month = center.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    const startDow = firstDay.getDay();
    const gridStart = new Date(firstDay);
    gridStart.setDate(gridStart.getDate() - (startDow === 0 ? 6 : startDow - 1));

    const totalCells = Math.ceil(((lastDay.getTime() - gridStart.getTime()) / 86400000 + 1) / 7) * 7;

    return Array.from({ length: totalCells }, (_, i) => {
        const d = new Date(gridStart);
        d.setDate(d.getDate() + i);
        return d;
    });
}

/* ═══════════════ Collision Detection ═══════════════ */

interface AppointmentWithCollision extends Appointment {
    colIndex: number;
    totalCols: number;
}

/**
 * Алгоритм расчёта коллизий для записей одного дня.
 * Работает аналогично Google Calendar: пересекающиеся записи
 * распределяются по колонкам, каждая получает свою ширину.
 */
function calculateCollisions(appointments: Appointment[]): AppointmentWithCollision[] {
    if (appointments.length === 0) return [];

    // Шаг 1: Сортировка по времени начала, при равенстве — по убыванию длительности
    const sorted = [...appointments].sort((a, b) => {
        const startDiff = timeToMinutes(a.time) - timeToMinutes(b.time);
        if (startDiff !== 0) return startDiff;
        return b.duration - a.duration;
    });

    // Шаг 2: Разбивка на группы (кластеры) пересекающихся записей
    const groups: Appointment[][] = [];
    let currentGroup: Appointment[] = [sorted[0]];

    for (let i = 1; i < sorted.length; i++) {
        const current = sorted[i];
        const currentStart = timeToMinutes(current.time);
        const currentEnd = currentStart + current.duration;

        // Проверяем, пересекается ли текущая запись с любой из текущей группы
        const overlaps = currentGroup.some((g) => {
            const gStart = timeToMinutes(g.time);
            const gEnd = gStart + g.duration;
            return currentStart < gEnd && gStart < currentEnd;
        });

        if (overlaps) {
            currentGroup.push(current);
        } else {
            groups.push(currentGroup);
            currentGroup = [current];
        }
    }
    groups.push(currentGroup);

    // Шаг 3: Внутри каждой группы распределяем по колонкам
    const result: AppointmentWithCollision[] = [];

    for (const group of groups) {
        // Массив колонок: в каждой колонке хранится время окончания последней записи
        const columns: number[][] = [];

        for (const appt of group) {
            const apptStart = timeToMinutes(appt.time);
            const apptEnd = apptStart + appt.duration;

            // Ищем первую свободную колонку
            let placed = false;
            for (let col = 0; col < columns.length; col++) {
                const lastEnd = columns[col][columns[col].length - 1];
                if (apptStart >= lastEnd) {
                    columns[col].push(apptEnd);
                    result.push({ ...appt, colIndex: col, totalCols: 0 });
                    placed = true;
                    break;
                }
            }

            // Если свободной колонки нет — создаём новую
            if (!placed) {
                columns.push([apptEnd]);
                result.push({ ...appt, colIndex: columns.length - 1, totalCols: 0 });
            }
        }

        // Присваиваем totalCols для всех записей в группе
        const totalCols = columns.length;
        for (const appt of result) {
            if (group.some((g) => g.id === appt.id)) {
                appt.totalCols = totalCols;
            }
        }
    }

    return result;
}

/* ═══════════════ Appointment Card ═══════════════ */

function AppointmentCard({ appointment, onClick, dayStartHour }: { appointment: AppointmentWithCollision; onClick: () => void; dayStartHour: number }) {
    const startMinutes = timeToMinutes(appointment.time) - dayStartHour * 60;
    const top = startMinutes * MINUTE_HEIGHT;
    const height = appointment.duration * MINUTE_HEIGHT;
    const styles = STATUS_STYLES[appointment.status];
    const endTime = getEndTime(appointment.time, appointment.duration);
    const showPrice = appointment.duration >= 45;

    const { colIndex, totalCols } = appointment;
    const widthPercent = 100 / totalCols;
    const leftPercent = widthPercent * colIndex;

    return (
        <button
            onClick={onClick}
            className={`absolute z-10 cursor-pointer overflow-hidden rounded-lg border-l-4 px-2 py-1 shadow-xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${styles.card}`}
            style={{
                top,
                height: Math.max(height, 32),
                width: `calc(${widthPercent}% - 4px)`,
                left: `calc(${leftPercent}% + 2px)`,
            }}
        >
            <div className="flex h-full flex-col justify-between">
                <div>
                    <p className="font-mono text-[10px] opacity-75">
                        {appointment.time} – {endTime}
                    </p>
                    <p className="truncate text-xs font-semibold leading-tight">
                        {appointment.client_name}
                    </p>
                    <p className="truncate text-[11px] leading-tight opacity-80">
                        {appointment.service}
                    </p>
                </div>
                {showPrice && (
                    <p className="text-[10px] opacity-60">
                        {appointment.price.toLocaleString('ru-RU')} ₽
                    </p>
                )}
            </div>
        </button>
    );
}

/* ═══════════════ Blocked Time Card ═══════════════ */

function BlockedTimeCard({ blockedTime, dayDate, dayStartHour }: { blockedTime: BlockedTime; dayDate: string; dayStartHour: number }) {
    const btStart = new Date(blockedTime.start_datetime);
    const btEnd = new Date(blockedTime.end_datetime);

    const dayStart = new Date(dayDate + 'T00:00:00');
    const dayEnd = new Date(dayDate + 'T23:59:59');

    const effectiveStart = btStart < dayStart ? dayStart : btStart;
    const effectiveEnd = btEnd > dayEnd ? dayEnd : btEnd;

    const startMinutes = effectiveStart.getHours() * 60 + effectiveStart.getMinutes() - dayStartHour * 60;
    const endMinutes = effectiveEnd.getHours() * 60 + effectiveEnd.getMinutes() - dayStartHour * 60;
    const durationMinutes = Math.max(endMinutes - startMinutes, 15);

    const top = startMinutes * MINUTE_HEIGHT;
    const height = durationMinutes * MINUTE_HEIGHT;

    return (
        <div
            className="absolute z-0 mx-1 overflow-hidden rounded-lg border-l-4 border-dashed border-zinc-300 bg-zinc-50 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"
            style={{
                top,
                height: Math.max(height, 24),
                backgroundImage: 'repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0,0,0,0.03) 8px, rgba(0,0,0,0.03) 16px)',
            }}
        >
            <p className="truncate text-[10px] font-medium text-zinc-400 dark:text-zinc-500">
                {blockedTime.reason}
            </p>
        </div>
    );
}

/* ═══════════════ Break Zone ═══════════════ */

function BreakZone({ breakStart, breakEnd, dayStartHour }: { breakStart: string; breakEnd: string; dayStartHour: number }) {
    const startMinutes = timeToMinutes(breakStart) - dayStartHour * 60;
    const endMinutes = timeToMinutes(breakEnd) - dayStartHour * 60;
    const durationMinutes = Math.max(endMinutes - startMinutes, 15);
    const top = startMinutes * MINUTE_HEIGHT;
    const height = durationMinutes * MINUTE_HEIGHT;

    return (
        <div
            className="absolute z-0 mx-1 overflow-hidden rounded-lg border-l-4 border-dashed border-amber-200 bg-amber-50 px-2 py-1 dark:border-amber-800 dark:bg-amber-950/50"
            style={{
                top,
                height: Math.max(height, 24),
                backgroundImage: 'repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0,0,0,0.03) 8px, rgba(0,0,0,0.03) 16px)',
            }}
        >
            <p className="truncate text-[10px] font-medium text-amber-500 dark:text-amber-400">
                Обед
            </p>
        </div>
    );
}

/* ═══════════════ Month View ═══════════════ */

function MonthView({ appointments, centerDate, onDayClick, onEmptyDayClick }: {
    appointments: Appointment[];
    centerDate: Date;
    onDayClick: (appointment: Appointment) => void;
    onEmptyDayClick: (date: string) => void;
}) {
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
                                {dayAppts.slice(0, 3).map((appt) => {
                                    const styles = STATUS_STYLES[appt.status];
                                    return (
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

    const appointments = useMemo(
        () => initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled),
        [initialAppointments],
    );

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
        return appointments.filter((a) => a.date === key);
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
                                    appointments={appointments}
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
                                                    ? hasCollision(dateKey, hoveredSlot.time, bookingModeService.duration_minutes, appointments)
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
                                                                                if (hasCollision(dateKey, timeStr, bookingModeService?.duration_minutes ?? 60, appointments)) {
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
                            <div className="flex flex-wrap gap-4 text-xs">
                                {[AppointmentStatus.Booked, AppointmentStatus.NoShow, AppointmentStatus.Paid, AppointmentStatus.Cancelled].map((status) => (
                                    <div key={status} className="flex items-center gap-1.5">
                                        <div className={`size-2.5 rounded-full ${STATUS_STYLES[status].dot}`} />
                                        <span className="text-slate-500 dark:text-zinc-400">{STATUS_STYLES[status].label}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
            </AdminLayout>

            {/* ─── Appointment Detail Dialog ─── */}
                <Dialog open={sheetOpen} onOpenChange={setSheetOpen}>
                    <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-md">
                        {selected && (
                            <>
                                <DialogHeader className="pb-2">
                                    <DialogTitle className="text-lg text-slate-900 dark:text-zinc-100">
                                        Детали записи
                                    </DialogTitle>
                                    <DialogDescription className="text-slate-500 dark:text-zinc-400">
                                        {STATUS_STYLES[selected.status].label}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-3">
                                    <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                        <CalendarDays className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                        {(() => {
                                            const d = new Date(selected.date + 'T00:00:00');
                                            const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
                                            const monthNames = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
                                            return `${dayNames[d.getDay()]}, ${d.getDate()} ${monthNames[d.getMonth()]} ${d.getFullYear()}`;
                                        })()}
                                    </div>
                                    <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                        <Clock className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                        {selected.time} — {(() => {
                                            const [h, m] = selected.time.split(':').map(Number);
                                            const total = h * 60 + m + selected.duration;
                                            const eh = Math.floor(total / 60);
                                            const em = total % 60;
                                            return `${String(eh).padStart(2, '0')}:${String(em).padStart(2, '0')}`;
                                        })()}
                                        <span className="text-xs text-slate-400 dark:text-zinc-500">({selected.duration} мин)</span>
                                    </div>
                                    <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                        <User className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                        {selected.client_name}
                                    </div>
                                    {selected.client_phone && (
                                        <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                            <Phone className="size-4 shrink-0 text-slate-400 dark:text-zinc-500" />
                                            <a href={`tel:+${selected.client_phone.replace(/\D/g, '')}`} className="hover:text-blue-600 dark:hover:text-blue-400">
                                                {formatPhone(selected.client_phone)}
                                            </a>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-3 text-sm text-slate-700 dark:text-zinc-300">
                                        <span className="size-4 shrink-0 text-center text-sm font-bold text-slate-400 dark:text-zinc-500">₽</span>
                                        {selected.service} — {selected.price.toLocaleString('ru-RU')} ₽
                                    </div>
                                </div>

                                <div className="flex flex-col gap-2 pt-2">
                                    {selected.status !== AppointmentStatus.Paid && selected.status !== AppointmentStatus.Cancelled && (
                                        <Button
                                            onClick={() => updateStatus(AppointmentStatus.Paid)}
                                            disabled={isProcessing}
                                            className="w-full justify-start rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60"
                                        >
                                            <CheckCircle2 className="size-4" />
                                            Оплата получена
                                        </Button>
                                    )}
                                    {selected.status !== AppointmentStatus.NoShow && selected.status !== AppointmentStatus.Cancelled && (
                                        <Button
                                            onClick={() => updateStatus(AppointmentStatus.NoShow)}
                                            disabled={isProcessing}
                                            variant="outline"
                                            className="w-full justify-start rounded-lg border-rose-200 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                        >
                                            <XCircle className="size-4" />
                                            Не пришёл
                                        </Button>
                                    )}
                                    {selected.status !== AppointmentStatus.Cancelled && (
                                        <>
                                            <Button
                                                onClick={openReschedule}
                                                disabled={isProcessing}
                                                variant="outline"
                                                className="w-full justify-start rounded-lg"
                                            >
                                                <RotateCw className="size-4" />
                                                Перенести запись
                                            </Button>
                                            <Button
                                                onClick={deleteAppointment}
                                                disabled={isProcessing}
                                                variant="outline"
                                                className="w-full justify-start rounded-lg border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                            >
                                                <Trash2 className="size-4" />
                                                Удалить бронь
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </>
                        )}
                    </DialogContent>
                </Dialog>

                {/* ─── Break Intersection Warning Dialog ─── */}
                <Dialog open={breakWarningOpen} onOpenChange={setBreakWarningOpen}>
                    <DialogContent className="rounded-2xl border-amber-200 bg-white dark:border-amber-800 dark:bg-zinc-900 sm:max-w-md">
                        <DialogHeader>
                            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950/40">
                                <AlertTriangle className="size-6 text-amber-600 dark:text-amber-400" />
                            </div>
                            <DialogTitle className="text-center text-slate-900 dark:text-zinc-100">
                                Пересечение с обедом
                            </DialogTitle>
                            <DialogDescription className="text-center text-slate-500 dark:text-zinc-400">
                                {breakWarningMessage}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                variant="outline"
                                onClick={cancelReschedule}
                                className="flex-1 rounded-xl sm:flex-none"
                            >
                                Отмена
                            </Button>
                            <Button
                                onClick={confirmRescheduleWithBreak}
                                className="flex-1 rounded-xl bg-amber-600 text-white hover:bg-amber-700 sm:flex-none"
                            >
                                Всё равно перенести
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ─── Outside Working Hours Warning Dialog ─── */}
                <Dialog open={outsideHoursOpen} onOpenChange={setOutsideHoursOpen}>
                    <DialogContent className="rounded-2xl border-amber-200 bg-white dark:border-amber-800 dark:bg-zinc-900 sm:max-w-md">
                        <DialogHeader>
                            <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950/40">
                                <AlertTriangle className="size-6 text-amber-600 dark:text-amber-400" />
                            </div>
                            <DialogTitle className="text-center text-slate-900 dark:text-zinc-100">
                                Вне рабочего графика
                            </DialogTitle>
                            <DialogDescription className="text-center text-slate-500 dark:text-zinc-400">
                                {outsideHoursMessage}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                variant="outline"
                                onClick={cancelOutsideHours}
                                className="flex-1 rounded-xl sm:flex-none"
                            >
                                Отмена
                            </Button>
                            <Button
                                onClick={confirmOutsideHours}
                                className="flex-1 rounded-xl bg-amber-600 text-white hover:bg-amber-700 sm:flex-none"
                            >
                                Всё равно создать
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ─── New Appointment Dialog ─── */}
                <Dialog open={newAppointmentOpen} onOpenChange={setNewAppointmentOpen}>
                    <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-md">
                        <form onSubmit={submitNewAppointment}>
                            <DialogHeader>
                                <DialogTitle className="text-slate-900 dark:text-zinc-100">
                                    Новая запись
                                </DialogTitle>
                                <DialogDescription className="text-slate-500 dark:text-zinc-400">
                                    Выберите клиента, услугу и время
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4">
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                        Клиент *
                                    </label>
                                    <Select
                                        value={newAppointmentForm.data.client_id}
                                        onValueChange={(value) => newAppointmentForm.setData('client_id', value)}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Выберите клиента" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c: ClientOption) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name}{c.phone ? ` (${c.phone})` : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {newAppointmentForm.errors.client_id && (
                                        <p className="mt-1 text-xs text-red-500">{newAppointmentForm.errors.client_id}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                        Услуга *
                                    </label>
                                    <Select
                                        value={newAppointmentForm.data.service_id}
                                        onValueChange={(value) => newAppointmentForm.setData('service_id', value)}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Выберите услугу" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {services.map((s: ServiceOption) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.title} — {s.duration_minutes} мин, {s.price.toLocaleString('ru-RU')} ₽
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {newAppointmentForm.errors.service_id && (
                                        <p className="mt-1 text-xs text-red-500">{newAppointmentForm.errors.service_id}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                            Дата *
                                        </label>
                                        <input
                                            type="date"
                                            value={newAppointmentForm.data.date}
                                            min={dateToKey(new Date())}
                                            onChange={(e) => newAppointmentForm.setData('date', e.target.value)}
                                            className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                        />
                                        {newAppointmentForm.errors.date && (
                                            <p className="mt-1 text-xs text-red-500">{newAppointmentForm.errors.date}</p>
                                        )}
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                            Время *
                                        </label>
                                        <input
                                            type="time"
                                            value={newAppointmentForm.data.time}
                                            onChange={(e) => newAppointmentForm.setData('time', e.target.value)}
                                            step={slotInterval * 60}
                                            className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                        />
                                        {newAppointmentForm.errors.time && (
                                            <p className="mt-1 text-xs text-red-500">{newAppointmentForm.errors.time}</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setNewAppointmentOpen(false)}
                                    className="flex-1 rounded-xl sm:flex-none"
                                >
                                    Отмена
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={newAppointmentForm.processing || !newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time}
                                    className="flex-1 rounded-xl bg-blue-600 text-white hover:bg-blue-700 sm:flex-none"
                                >
                                    {newAppointmentForm.processing ? 'Создание...' : 'Создать запись'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* ─── Reschedule Dialog ─── */}
                <Dialog open={rescheduleOpen} onOpenChange={setRescheduleOpen}>
                    <DialogContent className="rounded-2xl border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle className="text-slate-900 dark:text-zinc-100">
                                Перенос записи
                            </DialogTitle>
                            <DialogDescription className="text-slate-500 dark:text-zinc-400">
                                Выберите новую дату и время
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    Дата
                                </label>
                                <input
                                    type="date"
                                    value={rescheduleDate}
                                    min={dateToKey(new Date())}
                                    onChange={(e) => setRescheduleDate(e.target.value)}
                                    className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-300">
                                    Время
                                </label>
                                <Select value={rescheduleTime} onValueChange={setRescheduleTime}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Выберите время" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {timeOptions.map((t) => (
                                            <SelectItem key={t} value={t}>{t}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <DialogFooter className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                variant="outline"
                                onClick={() => setRescheduleOpen(false)}
                                className="flex-1 rounded-xl sm:flex-none"
                            >
                                Отмена
                            </Button>
                            <Button
                                onClick={submitReschedule}
                                disabled={!rescheduleDate || !rescheduleTime}
                                className="flex-1 rounded-xl bg-blue-600 text-white hover:bg-blue-700 sm:flex-none"
                            >
                                Сохранить перенос
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
        </>
    );
}
