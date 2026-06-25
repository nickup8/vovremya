import { useState, useEffect, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Clock, CheckCircle2, AlertCircle, Phone, MapPin } from 'lucide-react';
import PublicLayout from '@/layouts/PublicLayout';
import { AppointmentStatus } from '@/types/appointment-status';

Status.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

/* ═══════════════ Types ═══════════════ */

interface Service {
    id: number;
    title: string;
    price: number;
    duration_minutes: number;
}

interface Master {
    id: number;
    name: string;
    phone: string | null;
    specialty: string | null;
    soft_deposit: boolean;
    deposit_timeout: number;
    deposit_percent: number;
}

interface Appointment {
    id: number;
    status: string;
    start_time: string;
    created_at: string;
    service: Service;
    master: Master;
}

interface PageProps {
    appointment: Appointment;
    [key: string]: unknown;
}

/* ═══════════════ Helpers ═══════════════ */

function formatDateTime(iso: string): string {
    const d = new Date(iso);
    const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const monthNames = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    return `${dayNames[d.getDay()]}, ${d.getDate()} ${monthNames[d.getMonth()]} в ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function formatPhone(phone: string | null): string {
    if (!phone) return 'не указан';
    return phone;
}

/* ═══════════════ Countdown Timer ═══════════════ */

function CountdownTimer({ expiresAt }: { expiresAt: Date }) {
    const [remaining, setRemaining] = useState<number>(0);
    const [expired, setExpired] = useState(false);

    useEffect(() => {
        function tick() {
            const now = Date.now();
            const diff = expiresAt.getTime() - now;
            if (diff <= 0) {
                setRemaining(0);
                setExpired(true);
                return;
            }
            setRemaining(Math.floor(diff / 1000));
        }

        tick();
        const interval = setInterval(tick, 1000);
        return () => clearInterval(interval);
    }, [expiresAt]);

    if (expired) {
        return (
            <div className="rounded-2xl border border-red-200 bg-red-50 p-5 text-center dark:border-red-900/50 dark:bg-red-950/30">
                <AlertCircle className="mx-auto size-8 text-red-400 dark:text-red-500" />
                <p className="mt-3 text-sm font-semibold text-red-700 dark:text-red-300">
                    Время на предоплату истекло
                </p>
                <p className="mt-1 text-xs text-red-500 dark:text-red-400">
                    Запись аннулирована. Вы можете записаться снова через виджет мастера.
                </p>
            </div>
        );
    }

    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;

    return (
        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center dark:border-amber-900/50 dark:bg-amber-950/30">
            <Clock className="mx-auto size-8 text-amber-500 dark:text-amber-400" />
            <p className="mt-3 text-xs font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400">
                Осталось времени
            </p>
            <p className="mt-1 font-mono text-4xl font-bold tracking-tight text-amber-900 dark:text-amber-100">
                {String(minutes).padStart(2, '0')}:{String(seconds).padStart(2, '0')}
            </p>
            <p className="mt-2 text-xs text-amber-600/80 dark:text-amber-400/70">
                Подтвердите запись до истечения таймера
            </p>
        </div>
    );
}

/* ═══════════════ Main Status Page ═══════════════ */

export default function Status({ appointment }: PageProps) {
    const safeAppointment = appointment || { id: 0, status: AppointmentStatus.PendingClient, start_time: '', created_at: '', service: { id: 0, title: '', price: 0, duration_minutes: 0 }, master: { id: 0, name: '', phone: null, specialty: null, soft_deposit: false, deposit_timeout: 15, deposit_percent: 30 } };
    const { service, master } = safeAppointment;

    const isPending = safeAppointment.status === AppointmentStatus.PendingClient;
    const isConfirmed = safeAppointment.status === AppointmentStatus.Confirmed;

    const depositAmount = useMemo(() => {
        if (!master.soft_deposit || !master.deposit_percent) return 0;
        return Math.round(service.price * master.deposit_percent / 100);
    }, [service.price, master.soft_deposit, master.deposit_percent]);

    const expiresAt = useMemo(() => {
        const created = new Date(appointment.created_at);
        return new Date(created.getTime() + master.deposit_timeout * 60 * 1000);
    }, [appointment.created_at, master.deposit_timeout]);

    return (
        <>
            <Head title="Статус записи — Вовремя" />

            <div className="flex min-h-screen flex-col items-center px-5 py-12">
                {/* Header */}
                <div className="mb-8 text-center">
                    <span className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                        вовремя
                    </span>
                </div>

                {/* Status Badge */}
                <div className="mb-8">
                    {isPending && (
                        <div className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
                            <Clock className="size-4 text-amber-500 dark:text-amber-400" />
                            <span className="text-sm font-semibold text-amber-700 dark:text-amber-300">
                                Ожидает предоплаты
                            </span>
                        </div>
                    )}
                    {isConfirmed && (
                        <div className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                            <CheckCircle2 className="size-4 text-emerald-500 dark:text-emerald-400" />
                            <span className="text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                Запись подтверждена
                            </span>
                        </div>
                    )}
                </div>

                {/* Appointment Details Card */}
                <div className="w-full max-w-md space-y-4">
                    <div className="rounded-2xl border border-stone-200/60 bg-white p-6 shadow-sm dark:border-stone-700/40 dark:bg-stone-900/50">
                        <h2 className="mb-4 text-lg font-semibold text-stone-900 dark:text-stone-50">
                            Детали записи
                        </h2>

                        <div className="space-y-3">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-800">
                                    <span className="text-sm font-bold text-stone-600 dark:text-stone-300">
                                        {master.name.split(' ').map((w) => w[0]).join('').slice(0, 2)}
                                    </span>
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-stone-900 dark:text-stone-50">
                                        {master.name}
                                    </p>
                                    {master.specialty && (
                                        <p className="text-xs text-stone-400 dark:text-stone-500">
                                            {master.specialty}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="h-px bg-stone-100 dark:bg-stone-800" />

                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-stone-500 dark:text-stone-400">Услуга</span>
                                    <span className="font-medium text-stone-900 dark:text-stone-50">
                                        {service.title}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-stone-500 dark:text-stone-400">Дата и время</span>
                                    <span className="font-medium text-stone-900 dark:text-stone-50">
                                        {formatDateTime(appointment.start_time)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-stone-500 dark:text-stone-400">Длительность</span>
                                    <span className="font-medium text-stone-900 dark:text-stone-50">
                                        {service.duration_minutes} мин
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-stone-500 dark:text-stone-400">Стоимость</span>
                                    <span className="font-bold text-stone-900 dark:text-stone-50">
                                        {service.price.toLocaleString('ru-RU')} ₽
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Soft Deposit Block */}
                    {isPending && master.soft_deposit && (
                        <div className="space-y-4">
                            <CountdownTimer expiresAt={expiresAt} />

                            <div className="rounded-2xl border border-stone-200/60 bg-white p-6 shadow-sm dark:border-stone-700/40 dark:bg-stone-900/50">
                                <h3 className="mb-3 text-base font-semibold text-stone-900 dark:text-stone-50">
                                    Мягкая предоплата
                                </h3>

                                <div className="mb-4 rounded-xl bg-stone-50 p-4 text-center dark:bg-stone-800/50">
                                    <p className="text-xs text-stone-500 dark:text-stone-400">
                                        Сумма к переводу ({master.deposit_percent}%)
                                    </p>
                                    <p className="mt-1 text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                                        {depositAmount.toLocaleString('ru-RU')} ₽
                                    </p>
                                </div>

                                <div className="space-y-2 text-sm text-stone-600 dark:text-stone-300">
                                    <p>
                                        Переведите сумму по номеру телефона мастера:
                                    </p>
                                    <div className="flex items-center gap-2 rounded-xl border border-stone-200/60 bg-stone-50 px-4 py-3 dark:border-stone-700/40 dark:bg-stone-800/50">
                                        <Phone className="size-4 shrink-0 text-stone-400 dark:text-stone-500" />
                                        <span className="font-mono font-semibold text-stone-900 dark:text-stone-50">
                                            {formatPhone(master.phone)}
                                        </span>
                                    </div>
                                    <p className="text-xs text-stone-400 dark:text-stone-500">
                                        После перевода мастер подтвердит запись вручную.
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Confirmed message */}
                    {isConfirmed && (
                        <div className="rounded-2xl border border-emerald-200/60 bg-emerald-50/50 p-6 text-center dark:border-emerald-900/30 dark:bg-emerald-950/20">
                            <CheckCircle2 className="mx-auto size-10 text-emerald-500 dark:text-emerald-400" />
                            <p className="mt-3 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                Вы успешно записаны!
                            </p>
                            <p className="mt-1 text-xs text-emerald-600/80 dark:text-emerald-400/70">
                                Ждём вас {formatDateTime(appointment.start_time)}
                            </p>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <p className="mt-10 text-xs text-stone-300 dark:text-stone-600">
                    © {new Date().getFullYear()} Вовремя
                </p>
            </div>
        </>
    );
}
