import { useState, useMemo, useCallback, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowRight, ArrowLeft, Clock,
    CheckCircle2, MessageCircle,
    ChevronLeft, ChevronRight, MapPin, Loader2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';
import { getInitials } from '@/lib/utils';

Widget.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

/* ═══════════════ Types ═══════════════ */

interface Master {
    name: string;
    specialty: string | null;
    address: string | null;
    avatar_url: string | null;
    master_slug: string;
}

interface Service {
    id: string;
    title: string;
    duration_minutes: number;
    price: number;
}

interface PageProps {
    master: Master;
    services: Service[];
    availableSlots: string[];
    selectedDate: string;
    selectedServiceId: string | null;
    maxBotName: string | null;
    [key: string]: unknown;
}

/* ═══════════════ Helpers ═══════════════ */

const MONTH_NAMES = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const DAY_LETTERS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

function getMonthGrid(year: number, month: number) {
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();

    let startOffset = firstDay.getDay() - 1;
    if (startOffset < 0) startOffset = 6;

    const cells: (Date | null)[] = [];
    for (let i = 0; i < startOffset; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) cells.push(new Date(year, month, d));
    while (cells.length % 7 !== 0) cells.push(null);

    return cells;
}

function formatDateKey(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/* ═══════════════ Master Profile ═══════════════ */

function MasterProfileHeader({ master }: { master: Master }) {
    const initials = getInitials(master.name);

    return (
        <div className="border-b border-stone-200/50 bg-white/50 px-5 py-4 dark:border-stone-800/50 dark:bg-stone-900/30">
            <div className="flex items-center gap-3.5">
                {master.avatar_url ? (
                    <img
                        src={master.avatar_url}
                        alt={master.name}
                        className="size-12 rounded-full object-cover"
                    />
                ) : (
                    <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-stone-900 text-sm font-bold text-white dark:bg-stone-100 dark:text-stone-900">
                        {initials}
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-stone-900 dark:text-stone-50">
                        {master.name}
                    </p>
                    {master.specialty && (
                        <p className="truncate text-xs text-stone-400 dark:text-stone-500">
                            {master.specialty}
                        </p>
                    )}
                    {master.address && (
                        <div className="mt-0.5 flex items-center gap-1.5 text-xs text-stone-400 dark:text-stone-500">
                            <MapPin className="size-3" />
                            {master.address}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Step 1 — Services ═══════════════ */

function StepServices({
    services,
    selected,
    onSelect,
}: {
    services: Service[];
    selected: Service | null;
    onSelect: (s: Service) => void;
}) {
    return (
        <div className="flex-1 overflow-y-auto pb-28">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                    Выберите услугу
                </h2>
                <p className="mt-1 text-sm text-stone-400 dark:text-stone-500">
                    Нажмите на карточку, чтобы продолжить
                </p>
            </div>

            <div className="space-y-2 px-5">
                {services.map((service) => {
                    const isActive = selected?.id === service.id;
                    return (
                        <button
                            key={service.id}
                            onClick={() => onSelect(service)}
                            className={`w-full rounded-2xl border p-4 text-left transition-all ${
                                isActive
                                    ? 'border-stone-900 bg-stone-900 text-white shadow-lg shadow-stone-900/20 dark:border-stone-100 dark:bg-stone-100 dark:text-stone-900 dark:shadow-stone-100/10'
                                    : 'border-stone-200/60 bg-white/70 hover:border-stone-300 hover:bg-white dark:border-stone-700/40 dark:bg-stone-900/50 dark:hover:border-stone-600'
                            }`}
                        >
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="font-semibold">{service.title}</p>
                                    <div className="mt-1 flex items-center gap-2 text-xs opacity-70">
                                        <Clock className="size-3" />
                                        {service.duration_minutes} мин
                                    </div>
                                </div>
                                <span className="text-lg font-bold">
                                    {service.price.toLocaleString('ru-RU')} ₽
                                </span>
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/* ═══════════════ Step 2 — Date Selection ═══════════════ */

function StepDate({
    masterSlug,
    serviceId,
    selectedDate,
    onSelectDate,
}: {
    masterSlug: string;
    serviceId: string;
    selectedDate: Date | null;
    onSelectDate: (d: Date) => void;
}) {
    const today = new Date();
    const [viewMonth, setViewMonth] = useState(today.getMonth());
    const [viewYear, setViewYear] = useState(today.getFullYear());
    const [availableDates, setAvailableDates] = useState<Set<string>>(new Set());
    const [loadingDates, setLoadingDates] = useState(false);

    const cells = useMemo(() => getMonthGrid(viewYear, viewMonth), [viewYear, viewMonth]);

    // Загрузка доступных дат при смене месяца
    useEffect(() => {
        setLoadingDates(true);
        fetch(`/book/${masterSlug}/available-dates?service_id=${serviceId}&year=${viewYear}&month=${viewMonth + 1}`)
            .then((res) => res.json())
            .then((data: { dates: string[] }) => {
                setAvailableDates(new Set(data.dates ?? []));
            })
            .catch(() => {
                setAvailableDates(new Set());
            })
            .finally(() => {
                setLoadingDates(false);
            });
    }, [masterSlug, serviceId, viewYear, viewMonth]);

    const isPast = (d: Date) => {
        const t = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        return d < t;
    };

    const isAvailable = (d: Date) => availableDates.has(formatDateKey(d));

    const isSelected = (d: Date) =>
        selectedDate?.toDateString() === d.toDateString();

    function prevMonth() {
        if (viewMonth === 0) { setViewMonth(11); setViewYear((y) => y - 1); }
        else { setViewMonth((m) => m - 1); }
    }

    function nextMonth() {
        if (viewMonth === 11) { setViewMonth(0); setViewYear((y) => y + 1); }
        else { setViewMonth((m) => m + 1); }
    }

    return (
        <div className="flex-1 overflow-y-auto pb-28">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                    Выберите дату
                </h2>
                <p className="mt-1 text-sm text-stone-400 dark:text-stone-500">
                    Серым отмечены дни без свободных слотов
                </p>
            </div>

            <div className="px-5">
                <div className="flex items-center justify-between">
                    <button
                        onClick={prevMonth}
                        className="flex size-9 items-center justify-center rounded-full transition-colors hover:bg-stone-200/60 dark:hover:bg-stone-700/60"
                    >
                        <ChevronLeft className="size-5 text-stone-500 dark:text-stone-400" />
                    </button>
                    <p className="text-base font-semibold text-stone-900 dark:text-stone-50">
                        {MONTH_NAMES[viewMonth]} {viewYear}
                    </p>
                    <button
                        onClick={nextMonth}
                        className="flex size-9 items-center justify-center rounded-full transition-colors hover:bg-stone-200/60 dark:hover:bg-stone-700/60"
                    >
                        <ChevronRight className="size-5 text-stone-500 dark:text-stone-400" />
                    </button>
                </div>

                <div className="mt-4 grid grid-cols-7 gap-1">
                    {DAY_LETTERS.map((l) => (
                        <div key={l} className="py-1 text-center text-xs font-medium text-stone-400 dark:text-stone-500">
                            {l}
                        </div>
                    ))}
                </div>

                <div className="mt-1 grid grid-cols-7 gap-1">
                    {loadingDates && (
                        <div className="col-span-7 flex justify-center py-8">
                            <Loader2 className="size-5 animate-spin text-stone-400" />
                        </div>
                    )}
                    {!loadingDates && cells.map((d, i) => {
                        if (!d) return <div key={`empty-${i}`} />;

                        const past = isPast(d);
                        const available = isAvailable(d);
                        const disabled = past || !available;
                        const selected = isSelected(d);

                        return (
                            <button
                                key={d.toISOString()}
                                onClick={() => !disabled && onSelectDate(d)}
                                disabled={disabled}
                                className={`flex aspect-square items-center justify-center rounded-full text-sm font-medium transition-all ${
                                    disabled
                                        ? past
                                            ? 'text-stone-300 pointer-events-none dark:text-stone-700'
                                            : 'text-stone-300 cursor-not-allowed dark:text-stone-700'
                                        : selected
                                            ? 'bg-stone-900 text-white shadow-md shadow-stone-900/20 dark:bg-stone-100 dark:text-stone-900'
                                            : 'text-stone-700 hover:bg-stone-200/70 dark:text-stone-300 dark:hover:bg-stone-700/60'
                                }`}
                            >
                                {d.getDate()}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Step 3 — Time Selection ═══════════════ */

function StepTime({
    selectedDate,
    selectedTime,
    availableSlots,
    loadingSlots,
    onSelectTime,
}: {
    selectedDate: Date | null;
    selectedTime: string | null;
    availableSlots: string[];
    loadingSlots: boolean;
    onSelectTime: (t: string) => void;
}) {
    const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const monthNames = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    return (
        <div className="flex-1 overflow-y-auto pb-28">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                    Выберите время
                </h2>
                {selectedDate && (
                    <p className="mt-1 text-sm text-stone-400 dark:text-stone-500">
                        {dayNames[selectedDate.getDay()]}, {selectedDate.getDate()} {monthNames[selectedDate.getMonth()]}
                    </p>
                )}
            </div>

            <div className="px-5">
                {loadingSlots ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="size-5 animate-spin text-stone-400" />
                    </div>
                ) : availableSlots.length === 0 ? (
                    <p className="py-8 text-center text-sm text-stone-400 dark:text-stone-500">
                        Нет свободных слотов на эту дату
                    </p>
                ) : (
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        {availableSlots.map((t) => {
                            const active = selectedTime === t;
                            return (
                                <button
                                    key={t}
                                    onClick={() => onSelectTime(t)}
                                    className={`rounded-xl py-2.5 text-sm font-medium transition-all ${
                                        active
                                            ? 'bg-stone-900 text-white shadow-md dark:bg-stone-100 dark:text-stone-900'
                                            : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-300 dark:hover:bg-stone-700'
                                    }`}
                                >
                                    {t}
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

/* ═══════════════ Step 4 — Provider Selection ═══════════════ */

function StepProvider({
    errors,
    onSubmit,
    loadingProvider,
    maxBotName,
}: {
    errors: Record<string, string>;
    onSubmit: (provider: 'telegram' | 'max') => void;
    loadingProvider: 'telegram' | 'max' | null;
    maxBotName: string | null;
}) {
    return (
        <div className="flex-1 overflow-y-auto pb-28">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                    Подтверждение
                </h2>
                <p className="mt-1 text-sm text-stone-400 dark:text-stone-500">
                    Выберите мессенджер для подтверждения записи
                </p>
            </div>

            <div className="space-y-4 px-5">
                {errors.time && (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800/50 dark:bg-red-900/20">
                        <p className="text-sm text-red-600 dark:text-red-400">{errors.time}</p>
                    </div>
                )}

                <div className="space-y-3 pt-2">
                    <button
                        onClick={() => onSubmit('telegram')}
                        disabled={loadingProvider !== null}
                        className="flex w-full items-center justify-center gap-3 rounded-2xl bg-[#2AABEE] py-5 text-base font-semibold text-white shadow-lg shadow-[#2AABEE]/20 transition-all hover:scale-[1.02] hover:shadow-xl disabled:opacity-50 disabled:hover:scale-100"
                    >
                        {loadingProvider === 'telegram' ? (
                            <Loader2 className="size-5 animate-spin" />
                        ) : (
                            <MessageCircle className="size-5" />
                        )}
                        {loadingProvider === 'telegram' ? 'Отправка...' : 'Записаться через Telegram'}
                    </button>

                    {maxBotName && (
                        <button
                            onClick={() => onSubmit('max')}
                            disabled={loadingProvider !== null}
                            className="flex w-full items-center justify-center gap-3 rounded-2xl bg-[#6366F1] py-5 text-base font-semibold text-white shadow-lg shadow-[#6366F1]/20 transition-all hover:scale-[1.02] hover:shadow-xl disabled:opacity-50 disabled:hover:scale-100"
                        >
                            {loadingProvider === 'max' ? (
                                <Loader2 className="size-5 animate-spin" />
                            ) : (
                                <MessageCircle className="size-5" />
                            )}
                            {loadingProvider === 'max' ? 'Отправка...' : 'Записаться через MAX'}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Step 5 — Confirmation ═══════════════ */

function StepConfirmation({
    service,
    date,
    time,
}: {
    service: Service;
    date: Date;
    time: string;
}) {
    const dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    const monthNames = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    return (
        <div className="flex-1 flex flex-col items-center justify-center px-5 py-12 text-center">
            <div className="flex size-20 items-center justify-center rounded-full bg-green-50 dark:bg-green-900/30">
                <CheckCircle2 className="size-10 text-green-500 dark:text-green-400" />
            </div>

            <h2 className="mt-6 text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                Заявка создана!
            </h2>

            <div className="mt-4 rounded-2xl border border-stone-200/60 bg-white/70 p-4 dark:border-stone-700/40 dark:bg-stone-900/50">
                <p className="text-sm font-medium text-stone-900 dark:text-stone-50">{service.title}</p>
                <p className="mt-1 text-xs text-stone-400 dark:text-stone-500">
                    {dayNames[date.getDay()]}, {date.getDate()} {monthNames[date.getMonth()]} · {time}
                </p>
            </div>

            <div className="mt-6 flex items-start gap-2.5 rounded-2xl bg-stone-50 p-4 text-left dark:bg-stone-800/50">
                <MessageCircle className="mt-0.5 size-4 shrink-0 text-stone-400 dark:text-stone-500" />
                <p className="text-sm leading-relaxed text-stone-500 dark:text-stone-400">
                    Откройте чат с ботом и нажмите кнопку
                    <strong className="text-stone-700 dark:text-stone-200"> «Поделиться номером»</strong> для завершения бронирования.
                </p>
            </div>

            <p className="mt-4 text-xs text-stone-400 dark:text-stone-500">
                Слот временно зарезервирован в календаре мастера
            </p>
        </div>
    );
}

/* ═══════════════ Main Widget ═══════════════ */

type Step = 1 | 2 | 3 | 4 | 5;
const TOTAL_STEPS = 4;

export default function Widget() {
    const { master, services, availableSlots, selectedDate: initialDate, selectedServiceId: initialServiceId, maxBotName } = usePage<PageProps>().props;
    const pageProps = usePage<{ errors: Record<string, string> }>().props;
    const serverErrors = (pageProps as Record<string, unknown>).errors as Record<string, string> | undefined;

    const [step, setStep] = useState<Step>(1);
    const [selectedService, setSelectedService] = useState<Service | null>(() =>
        initialServiceId ? services.find((svc: Service) => svc.id === initialServiceId) ?? null : null
    );
    const [selectedDate, setSelectedDate] = useState<Date | null>(() =>
        initialDate ? new Date(initialDate + 'T00:00:00') : null
    );
    const [selectedTime, setSelectedTime] = useState<string | null>(null);
    const [slots, setSlots] = useState<string[]>(availableSlots);
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [loadingProvider, setLoadingProvider] = useState<'telegram' | 'max' | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (serverErrors && Object.keys(serverErrors).length > 0) {
            setErrors(serverErrors);
        }
    }, [serverErrors]);

    const canNext =
        (step === 1 && selectedService !== null) ||
        (step === 2 && selectedDate !== null) ||
        (step === 3 && selectedTime !== null);

    // Загрузка слотов при входе на шаг 3
    useEffect(() => {
        if (step === 3 && selectedDate && selectedService) {
            setLoadingSlots(true);
            const params = new URLSearchParams({
                service_id: selectedService.id,
                date: formatDateKey(selectedDate),
            });

            router.get(`/book/${master.master_slug}?${params.toString()}`, {}, {
                preserveState: true,
                preserveScroll: true,
                only: ['availableSlots'],
                onSuccess: (page) => {
                    const props = page.props as { availableSlots?: string[] };
                    setSlots(props.availableSlots ?? []);
                },
                onFinish: () => {
                    setLoadingSlots(false);
                },
            });
        }
    }, [step, selectedDate, selectedService, master.master_slug]);

    function handleSelectService(service: Service) {
        setSelectedService(service);
        setSelectedTime(null);
    }

    function handleSelectDate(date: Date) {
        setSelectedDate(date);
        setSelectedTime(null);
    }

    function handleNext() {
        if (step < TOTAL_STEPS) setStep((s) => (s + 1) as Step);
    }

    function handleBack() {
        if (step > 1) setStep((s) => (s - 1) as Step);
    }

    async function handleSubmit(provider: 'telegram' | 'max') {
        if (!selectedService || !selectedDate || !selectedTime || loadingProvider) return;

        setLoadingProvider(provider);
        setErrors({});

        try {
            const response = await fetch(`/book/${master.master_slug}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
                    ),
                },
                body: JSON.stringify({
                    service_id: selectedService.id,
                    date: formatDateKey(selectedDate),
                    time: selectedTime,
                    provider,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                setErrors(data.errors ?? { time: data.message || 'Ошибка сервера' });
                setLoadingProvider(null);
                return;
            }

            window.location.href = provider === 'max' ? data.max_url : data.telegram_url;
        } catch {
            setErrors({ time: 'Ошибка сети. Попробуйте ещё раз.' });
            setLoadingProvider(null);
        }
    }

    const progress = step <= TOTAL_STEPS ? (step / TOTAL_STEPS) * 100 : 100;
    const showNav = step >= 1 && step <= TOTAL_STEPS;
    const showHeader = step < 5;

    return (
        <>
            <Head title="Запись — Вовремя" />

            <div className="mx-auto flex min-h-screen max-w-md flex-col bg-[#FAF8F5] dark:bg-[#121110]">
                <div className="flex items-center justify-between border-b border-stone-200/50 px-5 py-4 dark:border-stone-800/50">
                    {step > 1 && step <= TOTAL_STEPS ? (
                        <button
                            onClick={handleBack}
                            className="flex size-9 items-center justify-center rounded-full transition-colors hover:bg-stone-200/60 dark:hover:bg-stone-700/60"
                        >
                            <ArrowLeft className="size-5 text-stone-600 dark:text-stone-400" />
                        </button>
                    ) : (
                        <div className="size-9" />
                    )}
                    <span className="text-sm font-medium text-stone-500 dark:text-stone-400">
                        {step <= TOTAL_STEPS ? `Шаг ${step} из ${TOTAL_STEPS}` : 'Готово'}
                    </span>
                    <div className="size-9" />
                </div>

                {showHeader && (
                    <div className="h-0.5 w-full bg-stone-200/50 dark:bg-stone-800/50">
                        <div
                            className="h-full bg-stone-900 transition-all duration-500 ease-out dark:bg-stone-100"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                )}

                {showHeader && <MasterProfileHeader master={master} />}

                {step === 1 && (
                    <StepServices
                        services={services}
                        selected={selectedService}
                        onSelect={handleSelectService}
                    />
                )}
                {step === 2 && selectedService && (
                    <StepDate
                        masterSlug={master.master_slug}
                        serviceId={selectedService.id}
                        selectedDate={selectedDate}
                        onSelectDate={handleSelectDate}
                    />
                )}
                {step === 3 && (
                    <StepTime
                        selectedDate={selectedDate}
                        selectedTime={selectedTime}
                        availableSlots={slots}
                        loadingSlots={loadingSlots}
                        onSelectTime={setSelectedTime}
                    />
                )}
                {step === 4 && (
                    <StepProvider
                        errors={errors}
                        onSubmit={handleSubmit}
                        loadingProvider={loadingProvider}
                        maxBotName={maxBotName}
                    />
                )}
                {step === 5 && selectedService && selectedDate && selectedTime && (
                    <StepConfirmation
                        service={selectedService}
                        date={selectedDate}
                        time={selectedTime}
                    />
                )}

                {showNav && step < TOTAL_STEPS && (
                    <div className="fixed bottom-0 left-0 right-0 z-40">
                        <div className="mx-auto max-w-md px-5 pb-5 pt-3">
                            <Button
                                onClick={handleNext}
                                disabled={!canNext}
                                className="h-13 w-full rounded-2xl bg-stone-900 text-base font-semibold text-white shadow-xl shadow-stone-900/20 transition-all hover:scale-[1.02] hover:shadow-2xl disabled:opacity-30 disabled:hover:scale-100 dark:bg-stone-100 dark:text-stone-900 dark:shadow-stone-100/10"
                            >
                                Далее
                                <ArrowRight className="size-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
