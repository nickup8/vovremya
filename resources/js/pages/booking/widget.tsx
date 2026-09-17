import { useState, useMemo, useCallback, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight, ArrowLeft, Clock,
    CheckCircle2, MessageCircle,
    ChevronLeft, ChevronRight, MapPin, Loader2, Lock, Check,
} from 'lucide-react';
import { getInitials } from '@/lib/utils';

/* ═══════════════ Tokens ═══════════════ */

const C = {
    milk: '#F7F5F1',
    ink: '#181818',
    graphite: '#62615F',
    muted: '#8E8A85',
    line: '#E7E4DF',
    softLine: '#F0EEEA',
    orange: '#FF5A1F',
    orangeHover: '#E94D14',
    softOrange: '#FFF0E8',
    white: '#FFFFFF',
};

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
    availableSlots?: string[];
    selectedDate: string;
    selectedServiceId: string | null;
    maxBotName: string | null;
    preselectedServiceId?: string | null;
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

const dayNamesFull = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
const monthNamesGen = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

/* ═══════════════ Step 1 — Hero Header ═══════════════ */

function Step1Header({ master }: { master: Master }) {
    const initials = getInitials(master.name);

    return (
        <div className="relative" style={{ height: '380px', background: C.milk }}>
            {/* Logo mark */}
            <div className="absolute left-5 top-5 z-10 flex size-12 items-center justify-center rounded-full bg-white shadow-sm">
                <img src="/images/logo-mark.svg" alt="Вовремя" className="size-7" />
            </div>

            {/* Warm decorative shapes */}
            <div className="pointer-events-none absolute -right-8 -top-8 size-48 rounded-full" style={{ background: '#F0E6DB' }} />
            <div className="pointer-events-none absolute -left-10 bottom-20 size-36 rounded-full" style={{ background: '#F5E8DC' }} />

            {/* Floating info card */}
            <div
                className="absolute bottom-0 left-0 right-0 z-10"
                style={{ paddingLeft: '18px', paddingRight: '18px', marginBottom: '-44px' }}
            >
                <div
                    className="flex items-center gap-4 bg-white"
                    style={{ borderRadius: '28px', padding: '20px', boxShadow: '0 4px 24px rgba(0,0,0,0.06)' }}
                >
                    <div className="relative shrink-0">
                        {master.avatar_url ? (
                            <img
                                src={master.avatar_url}
                                alt={master.name}
                                className="rounded-full object-cover"
                                style={{ width: '104px', height: '104px' }}
                            />
                        ) : (
                            <div
                                className="flex items-center justify-center rounded-full text-2xl font-bold text-white"
                                style={{ width: '104px', height: '104px', background: C.ink }}
                            >
                                {initials}
                            </div>
                        )}
                        <div
                            className="absolute -bottom-1 -right-1 flex size-8 items-center justify-center rounded-full"
                            style={{ background: C.orange }}
                        >
                            <Check className="size-4 text-white" />
                        </div>
                    </div>

                    <div className="min-w-0 flex-1">
                        <p className="font-bold leading-tight" style={{ fontSize: '28px', color: C.ink }}>
                            {master.name}
                        </p>
                        {master.address && (
                            <div className="mt-1.5 flex items-start gap-1.5" style={{ color: C.graphite, fontSize: '13px' }}>
                                <MapPin className="mt-0.5 size-3.5 shrink-0" />
                                <span style={{ overflowWrap: 'anywhere' }}>{master.address}</span>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Master Profile Header (Steps 2–4) ═══════════════ */

function MasterProfileHeader({ master, showBack, onBack }: { master: Master; showBack: boolean; onBack: () => void }) {
    const initials = getInitials(master.name);

    return (
        <div className="relative overflow-hidden" style={{ background: `linear-gradient(135deg, ${C.softOrange} 0%, ${C.milk} 60%, ${C.white} 100%)` }}>
            {/* Decorative organic shapes */}
            <div className="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full" style={{ background: `${C.orange}08` }} />
            <div className="pointer-events-none absolute -left-6 bottom-0 size-28 rounded-full" style={{ background: `${C.orange}05` }} />

            <div className="relative px-5 pb-5 pt-4">
                {/* Back button row */}
                {showBack && (
                    <div className="mb-3">
                        <button
                            onClick={onBack}
                            className="flex size-8 items-center justify-center rounded-xl transition-colors hover:bg-white/60"
                            aria-label="Назад"
                        >
                            <ArrowLeft className="size-4" style={{ color: C.graphite }} />
                        </button>
                    </div>
                )}

                <div className="flex items-center gap-4">
                    {master.avatar_url ? (
                        <img
                            src={master.avatar_url}
                            alt={master.name}
                            className="size-[72px] shrink-0 rounded-full object-cover ring-3 ring-white/80"
                        />
                    ) : (
                        <div
                            className="flex size-[72px] shrink-0 items-center justify-center rounded-full text-lg font-bold text-white ring-3 ring-white/80"
                            style={{ background: C.ink }}
                        >
                            {initials}
                        </div>
                    )}
                    <div className="min-w-0 flex-1">
                        <p className="text-lg font-bold tracking-tight" style={{ color: C.ink }}>
                            {master.name}
                        </p>
                        {master.specialty && (
                            <p className="mt-0.5 text-sm" style={{ color: C.graphite }}>
                                {master.specialty}
                            </p>
                        )}
                        {master.address && (
                            <div className="mt-1 flex items-center gap-1.5 text-xs" style={{ color: C.muted }}>
                                <MapPin className="size-3" />
                                {master.address}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ═══════════════ Progress ═══════════════ */

function ProgressBar({ step, total }: { step: number; total: number }) {
    return (
        <div className="px-5 pb-1 pt-3">
            <div className="flex items-center justify-between">
                <span className="text-xs font-medium" style={{ color: C.muted }}>
                    Шаг {step} из {total}
                </span>
            </div>
            <div className="mt-2 h-1 w-full rounded-full" style={{ background: C.line }}>
                <div
                    className="h-full rounded-full transition-all duration-500 ease-out"
                    style={{ width: `${(step / total) * 100}%`, background: C.orange }}
                />
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
            <div className="px-5 pt-12 pb-4">
                <h2 className="text-2xl font-bold tracking-tight" style={{ color: C.ink }}>
                    Выберите услугу
                </h2>
            </div>

            <div className="flex flex-col gap-3.5 px-5">
                {services.map((service, index) => {
                    const isActive = selected?.id === service.id;
                    const num = String(index + 1).padStart(2, '0');

                    return (
                        <button
                            key={service.id}
                            onClick={() => onSelect(service)}
                            aria-pressed={isActive}
                            className="relative w-full text-left transition-all"
                            style={{
                                background: isActive ? C.softOrange : C.white,
                                border: `1px solid ${isActive ? C.orange : C.line}`,
                                borderLeft: isActive ? `4px solid ${C.orange}` : `1px solid ${C.line}`,
                                borderRadius: '20px',
                                minHeight: '98px',
                                padding: '16px',
                                boxShadow: isActive ? '0 4px 16px rgba(255,90,31,0.08)' : 'none',
                            }}
                        >
                            {isActive && (
                                <div
                                    className="absolute flex items-center justify-center"
                                    style={{
                                        top: '12px', right: '12px',
                                        width: '24px', height: '24px',
                                        borderRadius: '8px',
                                        background: C.orange,
                                    }}
                                >
                                    <Check className="size-3.5 text-white" />
                                </div>
                            )}

                            <div className="grid items-center" style={{ gridTemplateColumns: '42px 1fr auto', gap: '12px' }}>
                                <div
                                    className="flex items-center justify-center self-center"
                                    style={{
                                        width: '42px', height: '42px',
                                        borderRadius: '13px',
                                        background: isActive ? C.orange : C.softLine,
                                        color: isActive ? C.white : C.graphite,
                                        fontSize: '13px',
                                        fontWeight: 700,
                                    }}
                                >
                                    {num}
                                </div>

                                <div className="min-w-0" style={{ minWidth: 0 }}>
                                    <p
                                        className="leading-tight"
                                        style={{
                                            fontSize: '17px',
                                            fontWeight: 600,
                                            color: isActive ? '#D4450F' : C.ink,
                                            overflowWrap: 'anywhere',
                                            whiteSpace: 'normal',
                                        }}
                                    >
                                        {service.title}
                                    </p>
                                    <div className="mt-1 flex items-center gap-1.5" style={{ color: C.muted, fontSize: '12px' }}>
                                        <Clock className="size-3 shrink-0" />
                                        {service.duration_minutes} мин
                                    </div>
                                </div>

                                <span
                                    className="shrink-0 self-center"
                                    style={{
                                        fontWeight: 700,
                                        fontSize: '17px',
                                        color: isActive ? '#D4450F' : C.ink,
                                        whiteSpace: 'nowrap',
                                    }}
                                >
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

    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();

        setLoadingDates(true);
        fetch(`/book/${masterSlug}/available-dates?service_id=${serviceId}&year=${viewYear}&month=${viewMonth + 1}`, {
            signal: controller.signal,
        })
            .then((res) => res.json())
            .then((data: { dates: string[] }) => {
                if (!cancelled) {
                    setAvailableDates(new Set(data.dates ?? []));
                }
            })
            .catch((error: unknown) => {
                if (error instanceof Error && error.name === 'AbortError') return;
                if (!cancelled) setAvailableDates(new Set());
            })
            .finally(() => {
                if (!cancelled) setLoadingDates(false);
            });

        return () => { cancelled = true; controller.abort(); };
    }, [masterSlug, serviceId, viewYear, viewMonth]);

    const isPast = (d: Date) => d < new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const isAvailable = (d: Date) => availableDates.has(formatDateKey(d));
    const isSelected = (d: Date) => selectedDate?.toDateString() === d.toDateString();

    function prevMonth() {
        if (viewMonth === 0) { setViewMonth(11); setViewYear((y) => y - 1); } else { setViewMonth((m) => m - 1); }
    }

    function nextMonth() {
        if (viewMonth === 11) { setViewMonth(0); setViewYear((y) => y + 1); } else { setViewMonth((m) => m + 1); }
    }

    return (
        <div className="flex-1 overflow-y-auto pb-32">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-2xl font-bold tracking-tight" style={{ color: C.ink }}>
                    Выберите дату
                </h2>
                <p className="mt-1 text-sm" style={{ color: C.muted }}>
                    Серым отмечены дни без свободных слотов
                </p>
            </div>

            <div className="px-5">
                <div className="flex items-center justify-between">
                    <button
                        onClick={prevMonth}
                        className="flex size-9 items-center justify-center rounded-xl transition-colors hover:bg-black/5"
                    >
                        <ChevronLeft className="size-5" style={{ color: C.graphite }} />
                    </button>
                    <p className="text-base font-semibold" style={{ color: C.ink }}>
                        {MONTH_NAMES[viewMonth]} {viewYear}
                    </p>
                    <button
                        onClick={nextMonth}
                        className="flex size-9 items-center justify-center rounded-xl transition-colors hover:bg-black/5"
                    >
                        <ChevronRight className="size-5" style={{ color: C.graphite }} />
                    </button>
                </div>

                <div className="mt-4 grid grid-cols-7 gap-1">
                    {DAY_LETTERS.map((l) => (
                        <div key={l} className="py-1 text-center text-xs font-medium" style={{ color: C.muted }}>
                            {l}
                        </div>
                    ))}
                </div>

                <div className="mt-1 grid grid-cols-7 gap-1">
                    {loadingDates && (
                        <div className="col-span-7 flex justify-center py-8">
                            <Loader2 className="size-5 animate-spin" style={{ color: C.muted }} />
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
                                className="flex aspect-square items-center justify-center rounded-full text-sm font-medium transition-all"
                                style={{
                                    color: disabled
                                        ? past ? '#D1CFC9' : '#D1CFC9'
                                        : selected ? C.white : C.ink,
                                    background: selected ? C.orange : 'transparent',
                                    cursor: disabled ? (past ? 'default' : 'not-allowed') : 'pointer',
                                    opacity: disabled && !past ? 0.5 : 1,
                                }}
                                onMouseEnter={(e) => { if (!disabled && !selected) e.currentTarget.style.background = C.softLine; }}
                                onMouseLeave={(e) => { if (!selected) e.currentTarget.style.background = selected ? C.orange : 'transparent'; }}
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
    return (
        <div className="flex-1 overflow-y-auto pb-32">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-2xl font-bold tracking-tight" style={{ color: C.ink }}>
                    Выберите время
                </h2>
                {selectedDate && (
                    <p className="mt-1 text-sm" style={{ color: C.muted }}>
                        {dayNamesFull[selectedDate.getDay()]}, {selectedDate.getDate()} {monthNamesGen[selectedDate.getMonth()]}
                    </p>
                )}
            </div>

            <div className="px-5">
                {loadingSlots ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="size-5 animate-spin" style={{ color: C.muted }} />
                    </div>
                ) : availableSlots.length === 0 ? (
                    <div className="rounded-2xl border py-8 text-center" style={{ borderColor: C.line, background: C.white }}>
                        <p className="text-sm" style={{ color: C.muted }}>Нет свободных слотов на эту дату</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        {availableSlots.map((t) => {
                            const active = selectedTime === t;

                            return (
                                <button
                                    key={t}
                                    onClick={() => onSelectTime(t)}
                                    className="rounded-xl border py-2.5 text-sm font-medium transition-all"
                                    style={{
                                        background: active ? C.orange : C.white,
                                        borderColor: active ? C.orange : C.line,
                                        color: active ? C.white : C.ink,
                                    }}
                                    onMouseEnter={(e) => { if (!active) { e.currentTarget.style.background = C.softOrange; e.currentTarget.style.borderColor = C.orange; } }}
                                    onMouseLeave={(e) => { if (!active) { e.currentTarget.style.background = C.white; e.currentTarget.style.borderColor = C.line; } }}
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
    onSubmit: (provider: 'telegram' | 'max' | 'vk') => void;
    loadingProvider: 'telegram' | 'max' | 'vk' | null;
    maxBotName: string | null;
}) {
    const providers: { key: 'telegram' | 'max' | 'vk'; label: string; color: string; show: boolean }[] = [
        { key: 'telegram', label: 'Telegram', color: '#2AABEE', show: true },
        { key: 'max', label: 'MAX', color: '#6366F1', show: !!maxBotName },
        { key: 'vk', label: 'VK', color: '#0077FF', show: true },
    ];

    return (
        <div className="flex-1 overflow-y-auto pb-32">
            <div className="px-5 pt-6 pb-4">
                <h2 className="text-2xl font-bold tracking-tight" style={{ color: C.ink }}>
                    Выберите мессенджер
                </h2>
                <p className="mt-1 text-sm" style={{ color: C.muted }}>
                    В нём мы завершим подтверждение записи
                </p>
            </div>

            <div className="space-y-3 px-5">
                {errors.limit && (
                    <div className="rounded-2xl border px-4 py-3" style={{ borderColor: '#F5D0A0', background: '#FFF8F0' }}>
                        <p className="text-sm" style={{ color: '#B8860B' }}>{errors.limit}</p>
                    </div>
                )}
                {errors.time && (
                    <div className="rounded-2xl border px-4 py-3" style={{ borderColor: '#F0C0C0', background: '#FFF5F5' }}>
                        <p className="text-sm" style={{ color: '#C44351' }}>{errors.time}</p>
                    </div>
                )}

                <div className="space-y-2.5 pt-1">
                    {providers.filter((p) => p.show).map((p) => (
                        <button
                            key={p.key}
                            onClick={() => onSubmit(p.key)}
                            disabled={loadingProvider !== null}
                            className="flex w-full items-center gap-3 rounded-2xl border p-4 text-left transition-all disabled:opacity-50"
                            style={{
                                background: C.white,
                                borderColor: C.line,
                            }}
                            onMouseEnter={(e) => { if (!loadingProvider) e.currentTarget.style.borderColor = p.color; }}
                            onMouseLeave={(e) => { e.currentTarget.style.borderColor = C.line; }}
                        >
                            <div
                                className="flex size-10 shrink-0 items-center justify-center rounded-xl"
                                style={{ background: `${p.color}15` }}
                            >
                                {loadingProvider === p.key ? (
                                    <Loader2 className="size-5 animate-spin" style={{ color: p.color }} />
                                ) : (
                                    <MessageCircle className="size-5" style={{ color: p.color }} />
                                )}
                            </div>
                            <div className="flex-1">
                                <p className="font-semibold" style={{ color: C.ink }}>
                                    {loadingProvider === p.key ? 'Отправка...' : p.label}
                                </p>
                            </div>
                            <ArrowRight className="size-4 shrink-0" style={{ color: C.muted }} />
                        </button>
                    ))}
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
    return (
        <div className="flex flex-1 flex-col items-center justify-center px-5 py-12 text-center">
            <div className="flex size-16 items-center justify-center rounded-full" style={{ background: C.softOrange }}>
                <CheckCircle2 className="size-8" style={{ color: C.orange }} />
            </div>

            <h2 className="mt-5 text-xl font-bold tracking-tight" style={{ color: C.ink }}>
                Заявка создана!
            </h2>

            <div className="mt-4 w-full rounded-2xl border p-4" style={{ borderColor: C.line, background: C.white }}>
                <p className="text-sm font-medium" style={{ color: C.ink }}>{service.title}</p>
                <p className="mt-1 text-xs" style={{ color: C.muted }}>
                    {dayNamesFull[date.getDay()]}, {date.getDate()} {monthNamesGen[date.getMonth()]} · {time}
                </p>
            </div>

            <div className="mt-5 flex w-full items-start gap-2.5 rounded-2xl p-4 text-left" style={{ background: C.softLine }}>
                <MessageCircle className="mt-0.5 size-4 shrink-0" style={{ color: C.muted }} />
                <p className="text-sm leading-relaxed" style={{ color: C.graphite }}>
                    Откройте чат с ботом и нажмите кнопку
                    <strong style={{ color: C.ink }}> «Поделиться номером»</strong> для завершения бронирования.
                </p>
            </div>

            <p className="mt-4 text-xs" style={{ color: C.muted }}>
                Слот временно зарезервирован в календаре мастера
            </p>
        </div>
    );
}

/* ═══════════════ Step 1 — CTA ═══════════════ */

function Step1CTA({
    canNext,
    handleNext,
}: {
    canNext: boolean;
    handleNext: () => void;
}) {
    return (
        <div
            className="fixed bottom-0 left-0 right-0 z-40"
            style={{ background: `linear-gradient(to top, ${C.milk} 60%, transparent)` }}
        >
            <div className="mx-auto max-w-md px-5 pb-5 pt-6">
                <button
                    onClick={handleNext}
                    disabled={!canNext}
                    className="flex w-full items-center justify-between text-left transition-all disabled:cursor-not-allowed"
                    style={{
                        height: '64px',
                        borderRadius: '20px',
                        background: canNext ? C.orange : C.line,
                        paddingLeft: '24px',
                        paddingRight: '14px',
                        color: canNext ? C.white : C.muted,
                        boxShadow: canNext ? '0 4px 18px rgba(255, 90, 31, 0.22)' : 'none',
                    }}
                    onMouseEnter={(e) => { if (canNext) e.currentTarget.style.background = C.orangeHover; }}
                    onMouseLeave={(e) => { e.currentTarget.style.background = canNext ? C.orange : C.line; }}
                >
                    <div>
                        <div className="text-base font-semibold" style={{ color: canNext ? C.white : C.muted }}>
                            Продолжить
                        </div>
                        <div className="text-xs" style={{ opacity: canNext ? 0.85 : 0.6 }}>
                            к выбору даты
                        </div>
                    </div>

                    <div
                        className="flex items-center justify-center"
                        style={{
                            width: '44px', height: '44px',
                            borderRadius: '14px',
                            background: canNext ? C.white : C.softLine,
                        }}
                    >
                        <ArrowRight className="size-5" style={{ color: canNext ? C.orange : C.muted }} />
                    </div>
                </button>
            </div>
        </div>
    );
}

/* ═══════════════ Main Widget ═══════════════ */

type Step = 1 | 2 | 3 | 4 | 5;
const TOTAL_STEPS = 4;

export default function Widget() {
    const { master, services, availableSlots, selectedDate: initialDate, selectedServiceId: initialServiceId, maxBotName, preselectedServiceId } = usePage<PageProps>().props;
    const pageProps = usePage<{ errors: Record<string, string> }>().props;
    const serverErrors = (pageProps as Record<string, unknown>).errors as Record<string, string> | undefined;

    const effectiveServiceId = preselectedServiceId ?? initialServiceId;
    const hasPreselectedService = effectiveServiceId !== null && effectiveServiceId !== undefined;

    const [step, setStep] = useState<Step>(() => hasPreselectedService ? 2 : 1);
    const [selectedService, setSelectedService] = useState<Service | null>(() =>
        effectiveServiceId ? services.find((svc: Service) => svc.id === effectiveServiceId) ?? null : null
    );
    const [selectedDate, setSelectedDate] = useState<Date | null>(() =>
        initialDate ? new Date(initialDate + 'T00:00:00') : null
    );
    const [selectedTime, setSelectedTime] = useState<string | null>(null);
    const [slots, setSlots] = useState<string[]>(availableSlots ?? []);
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [loadingProvider, setLoadingProvider] = useState<'telegram' | 'max' | 'vk' | null>(null);
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

    function buildUrlWithParams(extraParams: Record<string, string>): string {
        const params = new URLSearchParams(extraParams);
        return `/book/${master.master_slug}?${params.toString()}`;
    }

    useEffect(() => {
        if (step === 3 && selectedDate && selectedService) {
            setLoadingSlots(true);
            const url = buildUrlWithParams({
                service_id: selectedService.id,
                date: formatDateKey(selectedDate),
            });

            router.get(url, {}, {
                preserveState: true,
                preserveScroll: true,
                only: ['availableSlots'],
                onSuccess: (page) => {
                    const props = page.props as { availableSlots?: string[] };
                    setSlots(props.availableSlots ?? []);
                },
                onFinish: () => setLoadingSlots(false),
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

    async function handleSubmit(provider: 'telegram' | 'max' | 'vk') {
        if (!selectedService || !selectedDate || !selectedTime || loadingProvider) return;

        setLoadingProvider(provider);
        setErrors({});

        try {
            const response = await fetch(`/book/${master.master_slug}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
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
                if (response.status === 422 && data.errors?.limit) {
                    setErrors({ limit: data.errors.limit });
                } else {
                    setErrors(data.errors ?? { time: data.message || 'Ошибка сервера' });
                }
                setLoadingProvider(null);
                return;
            }

            const redirectUrl = provider === 'vk'
                ? data.vk_url
                : provider === 'max'
                    ? data.max_url
                    : data.telegram_url;

            if (!redirectUrl) {
                setErrors({ time: 'Не удалось получить ссылку для перехода. Попробуйте позже.' });
                setLoadingProvider(null);
                return;
            }

            window.location.href = redirectUrl;
        } catch {
            setErrors({ time: 'Ошибка сети. Попробуйте ещё раз.' });
            setLoadingProvider(null);
        }
    }

    const showHeader = step < 5;

    return (
        <>
            <Head title="Запись — Вовремя" />

            <div className="mx-auto flex min-h-screen max-w-md flex-col" style={{ background: C.milk }}>
                {/* Master header */}
                {step === 1 && <Step1Header master={master} />}
                {step > 1 && showHeader && (
                    <MasterProfileHeader
                        master={master}
                        showBack={step > 1 && step <= TOTAL_STEPS}
                        onBack={handleBack}
                    />
                )}

                {/* Progress */}
                {step > 1 && step <= TOTAL_STEPS && <ProgressBar step={step} total={TOTAL_STEPS} />}

                {/* Steps */}
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

                {/* Bottom CTA */}
                {step === 1 && <Step1CTA canNext={canNext} handleNext={handleNext} />}
                {step > 1 && step < TOTAL_STEPS && (
                    <div className="fixed bottom-0 left-0 right-0 z-40" style={{ background: `linear-gradient(to top, ${C.milk} 60%, transparent)` }}>
                        <div className="mx-auto max-w-md px-5 pb-5 pt-6">
                            <button
                                onClick={handleNext}
                                disabled={!canNext}
                                className="flex w-full items-center justify-center gap-2 rounded-2xl text-base font-semibold text-white transition-all disabled:cursor-not-allowed"
                                style={{
                                    height: '54px',
                                    background: canNext ? C.orange : C.line,
                                    color: canNext ? C.white : C.muted,
                                    boxShadow: canNext ? '0 4px 14px rgba(255, 90, 31, 0.2)' : 'none',
                                }}
                                onMouseEnter={(e) => { if (canNext) e.currentTarget.style.background = C.orangeHover; }}
                                onMouseLeave={(e) => { e.currentTarget.style.background = canNext ? C.orange : C.line; }}
                            >
                                Продолжить
                                <ArrowRight className="size-4" />
                            </button>

                            <div className="mt-3 flex items-center justify-center gap-1.5">
                                <Lock className="size-3" style={{ color: C.muted }} />
                                <span className="text-xs" style={{ color: C.muted }}>Безопасная запись через ИРСИ</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Footer with legal links */}
                <div className="px-5 pb-4 pt-2 text-center">
                    <span className="text-xs" style={{ color: C.muted }}>
                        <Link href="/offer" target="_blank" className="transition-colors hover:underline" style={{ color: C.muted }}>Оферта</Link>
                        <span className="mx-1">·</span>
                        <Link href="/privacy" target="_blank" className="transition-colors hover:underline" style={{ color: C.muted }}>Политика</Link>
                    </span>
                </div>
            </div>
        </>
    );
}
