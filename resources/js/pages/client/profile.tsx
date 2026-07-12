import { Head, Link, usePage } from '@inertiajs/react';
import {
    CalendarDays, Clock, DollarSign, MapPin, Phone, Star, User,
} from 'lucide-react';
import ClientLayout from '@/layouts/ClientLayout';
import { formatPhone } from '@/lib/phone';
import type { PageProps } from '@/types/app';

Profile.layout = (page: React.ReactNode) => <ClientLayout>{page}</ClientLayout>;

interface ClientData {
    id: string;
    name: string;
    phone: string | null;
}

interface MasterData {
    name: string;
    specialty: string | null;
    address: string | null;
    master_slug: string | null;
}

interface Stats {
    total_bookings: number;
    completed_bookings: number;
    ltv: number;
}

interface NextAppointment {
    id: string;
    service: string;
    date: string;
    time: string;
    price: number;
    master_name: string;
}

interface ProfilePageProps extends PageProps {
    client: ClientData;
    master: MasterData;
    stats: Stats;
    nextAppointment: NextAppointment | null;
}

export default function Profile() {
    const { client, master, stats, nextAppointment } = usePage<ProfilePageProps>().props;

    return (
        <>
            <Head title="Мой профиль — Вовремя" />

            <div className="space-y-6">
                {/* Greeting */}
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-zinc-100">
                        Здравствуйте, {client.name.split(' ')[0]}!
                    </h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                        {master.specialty ? master.specialty : 'Ваш личный кабинет'}
                    </p>
                </div>

                {/* Next Appointment Card */}
                {nextAppointment ? (
                    <div className="overflow-hidden rounded-2xl border border-blue-200 bg-gradient-to-br from-blue-50 to-indigo-50 p-5 dark:border-blue-900/50 dark:from-blue-950/30 dark:to-indigo-950/30">
                        <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300">
                            <CalendarDays className="size-4" />
                            Ближайшая запись
                        </div>
                        <h3 className="text-lg font-bold text-slate-900 dark:text-zinc-100">
                            {nextAppointment.service}
                        </h3>
                        <div className="mt-2 space-y-1 text-sm text-slate-600 dark:text-zinc-300">
                            <div className="flex items-center gap-2">
                                <CalendarDays className="size-4 text-slate-400" />
                                {nextAppointment.date}
                            </div>
                            <div className="flex items-center gap-2">
                                <Clock className="size-4 text-slate-400" />
                                {nextAppointment.time}
                            </div>
                            <div className="flex items-center gap-2">
                                <DollarSign className="size-4 text-slate-400" />
                                {nextAppointment.price.toLocaleString('ru-RU')} ₽
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 text-center dark:border-zinc-800 dark:bg-zinc-900">
                        <CalendarDays className="mx-auto size-10 text-slate-300 dark:text-zinc-600" />
                        <p className="mt-2 text-sm text-slate-500 dark:text-zinc-400">
                            У вас нет предстоящих записей
                        </p>
                        <Link
                            href="/client/book"
                            className="mt-4 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                        >
                            <CalendarDays className="size-4" />
                            Записаться
                        </Link>
                    </div>
                )}

                {/* Stats */}
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                        <p className="text-2xl font-bold text-slate-900 dark:text-zinc-100">
                            {stats.total_bookings}
                        </p>
                        <p className="text-[11px] text-slate-500 dark:text-zinc-400">Всего записей</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                        <p className="text-2xl font-bold text-slate-900 dark:text-zinc-100">
                            {stats.completed_bookings}
                        </p>
                        <p className="text-[11px] text-slate-500 dark:text-zinc-400">Визитов</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
                        <p className="text-2xl font-bold text-blue-600 dark:text-blue-400">
                            {stats.ltv.toLocaleString('ru-RU')}
                        </p>
                        <p className="text-[11px] text-slate-500 dark:text-zinc-400">₽ потрачено</p>
                    </div>
                </div>

                {/* Contact Info */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 className="mb-3 text-sm font-semibold text-slate-900 dark:text-zinc-100">
                        Контактная информация
                    </h2>
                    <div className="space-y-3">
                        {client.phone && (
                            <a
                                href={`tel:+${client.phone.replace(/\D/g, '')}`}
                                className="flex items-center gap-3 text-sm text-slate-600 transition-colors hover:text-blue-600 dark:text-zinc-300 dark:hover:text-blue-400"
                            >
                                <Phone className="size-4 shrink-0 text-slate-400" />
                                {formatPhone(client.phone)}
                            </a>
                        )}
                        {master.name && (
                            <div className="flex items-center gap-3 text-sm text-slate-600 dark:text-zinc-300">
                                <User className="size-4 shrink-0 text-slate-400" />
                                Мастер: {master.name}
                            </div>
                        )}
                        {master.address && (
                            <div className="flex items-center gap-3 text-sm text-slate-600 dark:text-zinc-300">
                                <MapPin className="size-4 shrink-0 text-slate-400" />
                                {master.address}
                            </div>
                        )}
                    </div>
                </div>

                {/* CTA */}
                <Link
                    href="/client/book"
                    className="flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 py-3.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                >
                    <Star className="size-4" />
                    Записаться снова
                </Link>
            </div>
        </>
    );
}
