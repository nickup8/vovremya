import { useState, useEffect, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Clock, CheckCircle2, AlertCircle } from 'lucide-react';
import PublicLayout from '@/layouts/PublicLayout';
import { AppointmentStatus } from '@/types/appointment-status';

Status.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

/* ═══════════════ Types ═══════════════ */

interface Service {
    id: number;
    title: string;
}

interface Master {
    id: number;
    name: string;
    specialty: string | null;
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

/* ═══════════════ Main Status Page ═══════════════ */

export default function Status({ appointment }: PageProps) {
    if (!appointment) {
        return (
            <>
                <Head title="Статус записи — Вовремя" />
                <div className="flex min-h-screen flex-col items-center justify-center px-5">
                    <AlertCircle className="size-12 text-stone-300 dark:text-stone-600" />
                    <p className="mt-4 text-sm text-stone-500 dark:text-stone-400">Запись не найдена</p>
                </div>
            </>
        );
    }

    const safeAppointment = appointment;
    const service = safeAppointment.service ?? { id: 0, title: '' };
    const master = safeAppointment.master ?? { id: 0, name: '', specialty: null };

    const isPending = safeAppointment.status === AppointmentStatus.Booked || safeAppointment.status === AppointmentStatus.PendingPayment;
    const isConfirmed = safeAppointment.status === AppointmentStatus.Prepaid || safeAppointment.status === AppointmentStatus.Paid;

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
                                Ожидает подтверждения
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
                                        {formatDateTime(safeAppointment.start_time)}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Pending confirmation message */}
                    {isPending && (
                        <div className="rounded-2xl border border-amber-200/60 bg-amber-50/50 p-6 text-center dark:border-amber-900/30 dark:bg-amber-950/20">
                            <Clock className="mx-auto size-10 text-amber-500 dark:text-amber-400" />
                            <p className="mt-3 text-sm font-semibold text-amber-700 dark:text-amber-300">
                                Запись ожидает подтверждения
                            </p>
                            <p className="mt-1 text-xs text-amber-600/80 dark:text-amber-400/70">
                                Мастер подтвердит запись в ближайшее время
                            </p>
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
                                Ждём вас {formatDateTime(safeAppointment.start_time)}
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
