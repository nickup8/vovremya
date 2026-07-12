import { useState, useEffect, useCallback } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays, Clock, ChevronLeft, ChevronRight, Check,
    Scissors, DollarSign, Loader2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/ClientLayout';
import type { PageProps } from '@/types/app';

Book.layout = (page: React.ReactNode) => <ClientLayout>{page}</ClientLayout>;

interface Service {
    id: string;
    title: string;
    price: number;
    duration_minutes: number;
}

interface BookPageProps extends PageProps {
    services: Service[];
    masterId: string;
}

const DAY_LABELS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const MONTH_LABELS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

export default function Book() {
    const { services } = usePage<BookPageProps>().props;

    const [step, setStep] = useState<1 | 2 | 3>(1);
    const [selectedService, setSelectedService] = useState<Service | null>(null);
    const [selectedDate, setSelectedDate] = useState<string | null>(null);
    const [selectedTime, setSelectedTime] = useState<string | null>(null);
    const [slots, setSlots] = useState<string[]>([]);
    const [loadingSlots, setLoadingSlots] = useState(false);

    // Calendar state
    const [viewDate, setViewDate] = useState(() => {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), 1);
    });

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Generate calendar days
    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startPad = (firstDay.getDay() + 6) % 7; // Mon=0
    const daysInMonth = lastDay.getDate();

    const calendarDays: (Date | null)[] = [];
    for (let i = 0; i < startPad; i++) calendarDays.push(null);
    for (let d = 1; d <= daysInMonth; d++) calendarDays.push(new Date(year, month, d));

    const fetchSlots = useCallback(async (date: string, serviceId: string) => {
        setLoadingSlots(true);
        setSlots([]);
        setSelectedTime(null);
        try {
            const res = await fetch(`/client/api/available-slots?date=${date}&service_id=${serviceId}`);
            const data = await res.json();
            setSlots(data.slots ?? []);
        } catch {
            setSlots([]);
        } finally {
            setLoadingSlots(false);
        }
    }, []);

    useEffect(() => {
        if (selectedDate && selectedService) {
            fetchSlots(selectedDate, selectedService.id);
        }
    }, [selectedDate, selectedService, fetchSlots]);

    function handleSelectService(service: Service) {
        setSelectedService(service);
        setStep(2);
    }

    function handleSelectDate(dateStr: string) {
        setSelectedDate(dateStr);
        setSelectedTime(null);
        setStep(3);
    }

    function handleConfirm() {
        if (!selectedService || !selectedDate || !selectedTime) return;

        router.post('/client/book', {
            service_id: selectedService.id,
            datetime: `${selectedDate} ${selectedTime}:00`,
        }, {
            preserveScroll: true,
        });
    }

    function prevMonth() {
        setViewDate(new Date(year, month - 1, 1));
    }

    function nextMonth() {
        setViewDate(new Date(year, month + 1, 1));
    }

    const progress = step === 1 ? 33 : step === 2 ? 66 : 100;

    return (
        <>
            <Head title="Запись — Вовремя" />

            <div className="space-y-6">
                {/* Progress */}
                <div>
                    <div className="mb-2 flex items-center justify-between text-xs text-slate-500 dark:text-zinc-400">
                        <span>Шаг {step} из 3</span>
                        <span>{step === 1 ? 'Услуга' : step === 2 ? 'Дата' : 'Время'}</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-zinc-800">
                        <div
                            className="h-full rounded-full bg-blue-600 transition-all duration-300"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                </div>

                {/* Step 1: Service selection */}
                {step === 1 && (
                    <div className="space-y-3">
                        <h2 className="text-lg font-bold text-slate-900 dark:text-zinc-100">
                            Выберите услугу
                        </h2>
                        {services.map((service) => (
                            <button
                                key={service.id}
                                onClick={() => handleSelectService(service)}
                                className="w-full rounded-2xl border border-slate-200 bg-white p-4 text-left transition-all hover:border-blue-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-blue-700"
                            >
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h3 className="font-semibold text-slate-900 dark:text-zinc-100">
                                            {service.title}
                                        </h3>
                                        <div className="mt-1 flex items-center gap-3 text-sm text-slate-500 dark:text-zinc-400">
                                            <span className="flex items-center gap-1">
                                                <Clock className="size-3.5" />
                                                {service.duration_minutes} мин
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <DollarSign className="size-3.5" />
                                                {service.price.toLocaleString('ru-RU')} ₽
                                            </span>
                                        </div>
                                    </div>
                                    <Scissors className="size-5 text-slate-300 dark:text-zinc-600" />
                                </div>
                            </button>
                        ))}
                    </div>
                )}

                {/* Step 2: Date selection */}
                {step === 2 && (
                    <div className="space-y-3">
                        <button
                            onClick={() => setStep(1)}
                            className="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400"
                        >
                            <ChevronLeft className="size-4" />
                            Назад к услугам
                        </button>
                        <h2 className="text-lg font-bold text-slate-900 dark:text-zinc-100">
                            Выберите дату
                        </h2>

                        {/* Calendar */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                            <div className="mb-4 flex items-center justify-between">
                                <button onClick={prevMonth} className="rounded-full p-1 hover:bg-slate-100 dark:hover:bg-zinc-800">
                                    <ChevronLeft className="size-5" />
                                </button>
                                <span className="font-semibold text-slate-900 dark:text-zinc-100">
                                    {MONTH_LABELS[month]} {year}
                                </span>
                                <button onClick={nextMonth} className="rounded-full p-1 hover:bg-slate-100 dark:hover:bg-zinc-800">
                                    <ChevronRight className="size-5" />
                                </button>
                            </div>

                            <div className="mb-2 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-slate-400 dark:text-zinc-500">
                                {DAY_LABELS.map((d) => (
                                    <div key={d}>{d}</div>
                                ))}
                            </div>

                            <div className="grid grid-cols-7 gap-1">
                                {calendarDays.map((day, i) => {
                                    if (!day) return <div key={`pad-${i}`} />;

                                    const dateStr = day.toISOString().split('T')[0];
                                    const isPast = day < today;
                                    const isSelected = dateStr === selectedDate;
                                    const isToday = day.getTime() === today.getTime();

                                    return (
                                        <button
                                            key={dateStr}
                                            disabled={isPast}
                                            onClick={() => handleSelectDate(dateStr)}
                                            className={`flex h-10 items-center justify-center rounded-xl text-sm font-medium transition-colors ${
                                                isPast
                                                    ? 'text-slate-300 dark:text-zinc-700'
                                                    : isSelected
                                                        ? 'bg-blue-600 text-white'
                                                        : isToday
                                                            ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/30 dark:text-blue-300'
                                                            : 'text-slate-700 hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800'
                                            }`}
                                        >
                                            {day.getDate()}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                )}

                {/* Step 3: Time selection */}
                {step === 3 && (
                    <div className="space-y-3">
                        <button
                            onClick={() => setStep(2)}
                            className="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400"
                        >
                            <ChevronLeft className="size-4" />
                            Назад к календарю
                        </button>
                        <h2 className="text-lg font-bold text-slate-900 dark:text-zinc-100">
                            Выберите время
                        </h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">
                            {selectedDate && new Date(selectedDate).toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })}
                        </p>

                        {loadingSlots ? (
                            <div className="flex items-center justify-center py-12">
                                <Loader2 className="size-6 animate-spin text-blue-600" />
                            </div>
                        ) : slots.length === 0 ? (
                            <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                                <Clock className="mx-auto size-10 text-slate-300 dark:text-zinc-600" />
                                <p className="mt-2 text-sm text-slate-500 dark:text-zinc-400">
                                    Нет свободных слотов на эту дату
                                </p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-3 gap-2">
                                {slots.map((time) => (
                                    <button
                                        key={time}
                                        onClick={() => setSelectedTime(time)}
                                        className={`rounded-xl border py-2.5 text-sm font-medium transition-colors ${
                                            selectedTime === time
                                                ? 'border-blue-600 bg-blue-600 text-white'
                                                : 'border-slate-200 bg-white text-slate-700 hover:border-blue-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-blue-700'
                                        }`}
                                    >
                                        {time}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Confirm button */}
                        {selectedTime && (
                            <Button
                                onClick={handleConfirm}
                                className="w-full bg-blue-600 text-white hover:bg-blue-700"
                            >
                                <Check className="size-4" />
                                Подтвердить запись
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
