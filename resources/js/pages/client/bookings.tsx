import { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft, CalendarDays, Clock, DollarSign, User,
    AlertTriangle,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle,
    DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import ClientLayout from '@/layouts/ClientLayout';
import { AppointmentStatus } from '@/types/appointment-status';

Bookings.layout = (page: React.ReactNode) => <ClientLayout>{page}</ClientLayout>;

interface Appointment {
    id: number;
    master_name: string;
    master_specialty: string | null;
    service: string;
    date: string;
    time: string;
    price: number;
    status: AppointmentStatus;
}

interface PageProps {
    appointments: Appointment[];
    [key: string]: unknown;
}

/* ═══════════════ Styles ═══════════════ */

const STATUS_STYLES: Record<AppointmentStatus, string> = {
    [AppointmentStatus.Booked]: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    [AppointmentStatus.PendingPayment]: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    [AppointmentStatus.Prepaid]: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    [AppointmentStatus.Paid]: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    [AppointmentStatus.NoShow]: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    [AppointmentStatus.Cancelled]: 'bg-stone-100 text-stone-500 dark:bg-stone-800 dark:text-stone-400',
};

const STATUS_LABELS: Record<AppointmentStatus, string> = {
    [AppointmentStatus.Booked]: 'Записан',
    [AppointmentStatus.PendingPayment]: 'Ожидает оплаты',
    [AppointmentStatus.Prepaid]: 'Предоплата получена',
    [AppointmentStatus.Paid]: 'Оплачен',
    [AppointmentStatus.NoShow]: 'Не пришёл',
    [AppointmentStatus.Cancelled]: 'Отменён',
};

/* ═══════════════ Helpers ═══════════════ */

function formatDate(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}

/* ═══════════════ Main Page ═══════════════ */

export default function Bookings() {
    const { appointments: initialAppointments = [] } = usePage<PageProps>().props;
    const [tab, setTab] = useState<'upcoming' | 'archive'>('upcoming');
    const [cancelId, setCancelId] = useState<number | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);

    const upcoming = useMemo(
        () => initialAppointments.filter((v) =>
            v.status === AppointmentStatus.Booked
            || v.status === AppointmentStatus.PendingPayment
            || v.status === AppointmentStatus.Prepaid
        ),
        [initialAppointments],
    );
    const archive = useMemo(
        () => initialAppointments.filter((v) =>
            v.status === AppointmentStatus.Paid
            || v.status === AppointmentStatus.NoShow
            || v.status === AppointmentStatus.Cancelled
        ),
        [initialAppointments],
    );

    const activeVisits = tab === 'upcoming' ? upcoming : archive;
    const cancelTarget = initialAppointments.find((v) => v.id === cancelId);

    function confirmCancel() {
        if (!cancelId || isProcessing) return;
        setIsProcessing(true);
        router.patch(`/my-bookings/appointments/${cancelId}/cancel`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setIsProcessing(false);
                setCancelId(null);
            },
        });
    }

    return (
        <>
            <Head title="Мои записи — Вовремя" />

            <div className="min-h-screen bg-[#FAF8F5] dark:bg-[#121110]">
                <div className="sticky top-0 z-30 border-b border-stone-200/50 bg-[#FAF8F5]/80 backdrop-blur-xl dark:border-stone-800/60 dark:bg-[#121110]/80">
                    <div className="flex items-center justify-between px-5 py-4">
                        <h1 className="text-lg font-bold tracking-tight text-stone-900 dark:text-stone-50">
                            Мои записи
                        </h1>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.visit('/admin/calendar')}
                                className="rounded-full text-xs text-stone-500 hover:text-stone-900 dark:text-stone-400 dark:hover:text-stone-50"
                            >
                                <ArrowLeft className="size-3" />
                                Панель мастера
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.post('/logout')}
                                className="rounded-full text-xs text-stone-400 hover:text-red-600 dark:text-stone-500 dark:hover:text-red-400"
                            >
                                Выйти
                            </Button>
                        </div>
                    </div>

                    <div className="flex gap-1 px-5 pb-3">
                        {([['upcoming', 'Предстоящие'], ['archive', 'Архив']] as const).map(([key, label]) => (
                            <button
                                key={key}
                                onClick={() => setTab(key)}
                                className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
                                    tab === key
                                        ? 'bg-stone-900 text-white dark:bg-stone-100 dark:text-stone-900'
                                        : 'text-stone-500 hover:text-stone-700 dark:text-stone-400 dark:hover:text-stone-200'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="px-5 py-4">
                    {activeVisits.length === 0 ? (
                        <div className="py-16 text-center">
                            <CalendarDays className="mx-auto size-10 text-stone-300 dark:text-stone-600" />
                            <p className="mt-3 text-sm text-stone-400 dark:text-stone-500">
                                {tab === 'upcoming' ? 'Нет предстоящих визитов' : 'Архив пуст'}
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {activeVisits.map((visit) => (
                                <div
                                    key={visit.id}
                                    className="rounded-2xl border border-stone-200/60 bg-white/70 p-4 shadow-sm shadow-stone-200/30 backdrop-blur-sm dark:border-stone-700/40 dark:bg-stone-900/50 dark:shadow-none"
                                >
                                    <div className="flex items-start justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-stone-900 text-xs font-bold text-white dark:bg-stone-100 dark:text-stone-900">
                                                    {visit.master_name.split(' ').map((w) => w[0]).join('')}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-stone-900 dark:text-stone-50">
                                                        {visit.master_name}
                                                    </p>
                                                    {visit.master_specialty && (
                                                        <p className="truncate text-xs text-stone-400 dark:text-stone-500">
                                                            {visit.master_specialty}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-[10px] font-medium ${STATUS_STYLES[visit.status]}`}>
                                            {STATUS_LABELS[visit.status]}
                                        </span>
                                    </div>

                                    <div className="mt-3 space-y-1.5 border-t border-stone-100 pt-3 dark:border-stone-800">
                                        <div className="flex items-center gap-2 text-xs text-stone-600 dark:text-stone-300">
                                            <User className="size-3 text-stone-400 dark:text-stone-500" />
                                            {visit.service}
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-stone-600 dark:text-stone-300">
                                            <CalendarDays className="size-3 text-stone-400 dark:text-stone-500" />
                                            {formatDate(visit.date)}, {visit.time}
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-stone-600 dark:text-stone-300">
                                            <DollarSign className="size-3 text-stone-400 dark:text-stone-500" />
                                            {visit.price.toLocaleString('ru-RU')} ₽
                                        </div>
                                    </div>

                                    {(visit.status === AppointmentStatus.PendingClient || visit.status === AppointmentStatus.Confirmed) && (
                                        <div className="mt-3 border-t border-stone-100 pt-3 dark:border-stone-800">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setCancelId(visit.id)}
                                                className="w-full rounded-xl border-stone-200 text-xs text-stone-500 hover:bg-red-50 hover:text-red-600 hover:border-red-200 dark:border-stone-700 dark:text-stone-400 dark:hover:bg-red-950/30 dark:hover:text-red-400 dark:hover:border-red-800"
                                            >
                                                Отменить запись
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <Dialog open={cancelId !== null} onOpenChange={(open) => !open && setCancelId(null)}>
                    <DialogContent className="rounded-3xl border-stone-200/60 bg-white dark:border-stone-700/50 dark:bg-stone-900 sm:max-w-sm">
                        <DialogHeader>
                            <div className="mx-auto flex size-10 items-center justify-center rounded-full bg-red-50 dark:bg-red-950/40">
                                <AlertTriangle className="size-5 text-red-500" />
                            </div>
                            <DialogTitle className="text-center text-stone-900 dark:text-stone-50">
                                Отменить запись?
                            </DialogTitle>
                            <DialogDescription className="text-center text-stone-500 dark:text-stone-400">
                                {cancelTarget && (
                                    <>Запись к <strong>{cancelTarget.master_name}</strong> на {formatDate(cancelTarget.date)}, {cancelTarget.time} будет отменена.</>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button
                                variant="outline"
                                onClick={() => setCancelId(null)}
                                className="flex-1 rounded-xl sm:flex-none"
                            >
                                Оставить
                            </Button>
                            <Button
                                onClick={confirmCancel}
                                disabled={isProcessing}
                                className="flex-1 rounded-xl bg-red-600 text-white hover:bg-red-700 sm:flex-none"
                            >
                                Да, отменить
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}
