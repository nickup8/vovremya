import { useState, useMemo, useCallback, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight, ArrowLeft, Clock, Search,
    CheckCircle2, MessageCircle, ChevronRight as ChevronRightIcon,
    ChevronLeft, ChevronRight, MapPin, Loader2, Check,
} from 'lucide-react';
import { getInitials } from '@/lib/utils';

/* ═══════════════ Tokens ═══════════════ */

const C = {
    milk: '#F7F5F1',
    ink: '#181818',
    graphite: '#62615F',
    muted: '#96938F',
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

function formatDuration(mins: number): string {
    if (mins < 60) return `${mins} мин`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m ? `${h} ч ${m} мин` : `${h} ч`;
}

/* ═══════════════ Compact Header ═══════════════ */

function CompactHeader({ step, master, showBack, onBack }: { step: number; master: Master; showBack: boolean; onBack: () => void }) {
    const initials = getInitials(master.name);

    return (
        <div
            className="sticky top-0 z-50"
            style={{
                background: 'rgba(255,255,255,.96)',
                backdropFilter: 'blur(8px)',
                WebkitBackdropFilter: 'blur(8px)',
                borderBottom: `1px solid ${C.line}`,
                paddingTop: 'env(safe-area-inset-top)',
            }}
        >
            <div className="px-5 pt-2.5 pb-1">
                <div className="flex items-center justify-between mb-1.5">
                    <span style={{ fontSize: '12px', color: C.muted }}>
                        Шаг {step} из 4
                    </span>
                </div>
                <div className="w-full rounded-full" style={{ height: '3px', background: C.line }}>
                    <div
                        className="h-full rounded-full transition-all duration-500 ease-out"
                        style={{ width: `${step * 25}%`, background: C.orange }}
                    />
                </div>
            </div>

            <div className="flex items-center gap-3" style={{ padding: '14px 20px 16px' }}>
                {showBack ? (
                    <button
                        onClick={onBack}
                        className="flex shrink-0 items-center justify-center"
                        style={{ width: '44px', height: '44px' }}
                        aria-label="Назад"
                    >
                        <ArrowLeft className="size-5" style={{ color: C.graphite }} />
                    </button>
                ) : null}

                <div className="flex items-center gap-3 min-w-0 flex-1">
                    {master.avatar_url ? (
                        <img
                            src={master.avatar_url}
                            alt={master.name}
                            className="shrink-0 rounded-full object-cover"
                            style={{ width: '42px', height: '42px' }}
                        />
                    ) : (
                        <div
                            className="flex shrink-0 items-center justify-center rounded-full font-bold text-white"
                            style={{ width: '42px', height: '42px', fontSize: '13px', background: C.ink }}
                        >
                            {initials}
                        </div>
                    )}

                    <div className="min-w-0 flex-1">
                        <p className="font-bold leading-tight" style={{ fontSize: '14px', color: C.ink }}>
                            {master.name}
                        </p>
                        {master.address && (
                            <div className="flex items-start gap-1 mt-0.5" style={{ color: C.muted, fontSize: '11.5px' }}>
                                <MapPin className="mt-px shrink-0" style={{ width: '13px', height: '13px' }} />
                                <span style={{ overflowWrap: 'anywhere' }}>{master.address}</span>
                            </div>
                        )}
                    </div>
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
    const [serviceSearch, setServiceSearch] = useState('');
    const q = serviceSearch.toLowerCase().trim();
    const filtered = q ? services.filter((s) => s.title.toLowerCase().includes(q)) : services;

    return (
        <div className="flex-1 overflow-y-auto" style={{ paddingBottom: '100px' }}>
            <div className="px-5 pt-5 pb-4">
                <h2 className="font-bold" style={{ fontSize: '22px', lineHeight: '28px', color: C.ink, letterSpacing: '-0.01em' }}>
                    Выберите услугу
                </h2>
                <p className="mt-1" style={{ fontSize: '13px', color: C.muted }}>
                    Выберите один вариант, чтобы перейти к свободному времени.
                </p>
            </div>

            <div className="px-5 pb-3">
                <div className="relative">
                    <Search
                        className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                        style={{ width: '16px', height: '16px', color: C.muted }}
                    />
                    <input
                        type="text"
                        placeholder="Найти услугу"
                        value={serviceSearch}
                        onChange={(e) => setServiceSearch(e.target.value)}
                        className="w-full outline-none"
                        style={{
                            height: '42px',
                            border: `1px solid ${C.line}`,
                            borderRadius: '10px',
                            paddingLeft: '38px',
                            paddingRight: '14px',
                            fontSize: '14px',
                            background: C.white,
                            color: C.ink,
                        }}
                        onFocus={(e) => { e.currentTarget.style.borderColor = C.orange; e.currentTarget.style.boxShadow = '0 0 0 3px rgba(255,90,31,0.1)'; }}
                        onBlur={(e) => { e.currentTarget.style.borderColor = C.line; e.currentTarget.style.boxShadow = 'none'; }}
                    />
                </div>
            </div>

            <div className="flex flex-col px-5" style={{ gap: '8px' }} role="radiogroup">
                {filtered.length === 0 && (
                    <p className="py-8 text-center" style={{ fontSize: '14px', color: C.muted }}>Ничего не найдено</p>
                )}
                {filtered.map((service) => {
                    const isSelected = selected?.id === service.id;

                    return (
                        <button
                            key={service.id}
                            type="button"
                            role="radio"
                            aria-checked={isSelected}
                            onClick={() => onSelect(service)}
                            className="w-full text-left transition-all"
                            style={{
                                minHeight: '64px',
                                border: `1px solid ${isSelected ? C.orange : C.line}`,
                                borderRadius: '12px',
                                background: isSelected ? C.softOrange : C.white,
                                padding: '13px 14px',
                                boxShadow: isSelected ? '0 0 0 1px #FF5A1F' : 'none',
                            }}
                        >
                            <div className="flex items-center" style={{ gap: '14px' }}>
                                <div className="min-w-0 flex-1" style={{ minWidth: 0 }}>
                                    <p
                                        className="leading-tight"
                                        style={{
                                            fontSize: '15px',
                                            fontWeight: 700,
                                            color: C.ink,
                                            lineHeight: '19px',
                                            overflowWrap: 'anywhere',
                                            whiteSpace: 'normal',
                                        }}
                                    >
                                        {service.title}
                                    </p>
                                    <div className="flex items-center gap-1 mt-1" style={{ color: C.muted, fontSize: '12px' }}>
                                        <Clock className="shrink-0" style={{ width: '12px', height: '12px' }} />
                                        {formatDuration(service.duration_minutes)}
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center" style={{ gap: '12px' }}>
                                    <span
                                        className="shrink-0"
                                        style={{
                                            fontWeight: 700,
                                            fontSize: '15px',
                                            color: C.ink,
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {service.price.toLocaleString('ru-RU')} ₽
                                    </span>
                                    <div
                                        className="flex shrink-0 items-center justify-center rounded-full"
                                        style={{
                                            width: '18px',
                                            height: '18px',
                                            border: `2px solid ${isSelected ? C.orange : C.line}`,
                                            background: isSelected ? C.orange : 'transparent',
                                        }}
                                    >
                                        {isSelected && <Check style={{ width: '11px', height: '11px', color: C.white }} />}
                                    </div>
                                </div>
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
        <div className="flex-1 overflow-y-auto" style={{ paddingBottom: '100px' }}>
            <div className="px-5 pt-5 pb-4">
                <h2 className="font-bold" style={{ fontSize: '22px', lineHeight: '28px', color: C.ink, letterSpacing: '-0.01em' }}>
                    Выберите дату
                </h2>
                <p className="mt-1" style={{ fontSize: '13px', color: C.muted }}>
                    Недоступные дни отмечены серым.
                </p>
            </div>

            <div className="px-5">
                <div className="flex items-center justify-between">
                    <p className="font-semibold" style={{ fontSize: '16px', color: C.ink }}>
                        {MONTH_NAMES[viewMonth]} {viewYear}
                    </p>
                    <div className="flex items-center" style={{ gap: '4px' }}>
                        <button
                            onClick={prevMonth}
                            className="flex items-center justify-center transition-colors"
                            style={{ width: '36px', height: '36px', borderRadius: '9px' }}
                        >
                            <ChevronLeft className="size-5" style={{ color: C.graphite }} />
                        </button>
                        <button
                            onClick={nextMonth}
                            className="flex items-center justify-center transition-colors"
                            style={{ width: '36px', height: '36px', borderRadius: '9px' }}
                        >
                            <ChevronRight className="size-5" style={{ color: C.graphite }} />
                        </button>
                    </div>
                </div>

                <div className="mt-4 grid grid-cols-7">
                    {DAY_LETTERS.map((l) => (
                        <div key={l} className="py-1 text-center" style={{ fontSize: '10.5px', color: C.muted }}>
                            {l}
                        </div>
                    ))}
                </div>

                <div className="mt-1 grid grid-cols-7" style={{ gap: '2px' }}>
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
                        const isToday = d.toDateString() === today.toDateString();

                        return (
                            <button
                                key={d.toISOString()}
                                onClick={() => !disabled && onSelectDate(d)}
                                disabled={disabled}
                                className="relative flex items-center justify-center transition-all"
                                style={{
                                    width: '38px',
                                    height: '38px',
                                    margin: '0 auto',
                                    borderRadius: '50%',
                                    fontSize: '14px',
                                    fontWeight: selected ? 700 : (available && !past ? 600 : 400),
                                    color: disabled
                                        ? '#D1CFC9'
                                        : selected ? C.white : C.ink,
                                    background: selected ? C.orange : 'transparent',
                                    cursor: disabled ? 'default' : 'pointer',
                                    opacity: disabled && !past ? 0.5 : 1,
                                }}
                                onMouseEnter={(e) => { if (!disabled && !selected) e.currentTarget.style.background = C.softLine; }}
                                onMouseLeave={(e) => { if (!selected) e.currentTarget.style.background = selected ? C.orange : 'transparent'; }}
                            >
                                {d.getDate()}
                                {isToday && !selected && (
                                    <div
                                        className="absolute"
                                        style={{
                                            bottom: '3px',
                                            width: '4px',
                                            height: '4px',
                                            borderRadius: '50%',
                                            background: C.orange,
                                        }}
                                    />
                                )}
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
        <div className="flex-1 overflow-y-auto" style={{ paddingBottom: '100px' }}>
            <div className="px-5 pt-5 pb-4">
                <h2 className="font-bold" style={{ fontSize: '22px', lineHeight: '28px', color: C.ink, letterSpacing: '-0.01em' }}>
                    Выберите время
                </h2>
                {selectedDate && (
                    <p className="mt-1" style={{ fontSize: '13px', color: C.muted }}>
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
                    <div className="rounded-xl border py-8 text-center" style={{ borderColor: C.line, background: C.white }}>
                        <p style={{ fontSize: '14px', color: C.muted }}>Нет свободных слотов на эту дату</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-4 max-[360px]:grid-cols-3" style={{ gap: '8px' }}>
                        {availableSlots.map((t) => {
                            const active = selectedTime === t;

                            return (
                                <button
                                    key={t}
                                    onClick={() => onSelectTime(t)}
                                    className="flex items-center justify-center font-semibold transition-all"
                                    style={{
                                        height: '44px',
                                        border: `1px solid ${active ? C.orange : C.line}`,
                                        borderRadius: '10px',
                                        background: active ? C.orange : C.white,
                                        color: active ? C.white : C.ink,
                                        fontSize: '13px',
                                        fontWeight: 650,
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

/* ═══════════════ Step 4 — Confirm / Provider ═══════════════ */

function StepProvider({
    errors,
    onSubmit,
    loadingProvider,
    maxBotName,
    selectedService,
    selectedDate,
    selectedTime,
}: {
    errors: Record<string, string>;
    onSubmit: (provider: 'telegram' | 'max' | 'vk') => void;
    loadingProvider: 'telegram' | 'max' | 'vk' | null;
    maxBotName: string | null;
    selectedService: Service | null;
    selectedDate: Date | null;
    selectedTime: string | null;
}) {
    const providers: { key: 'telegram' | 'max' | 'vk'; label: string; color: string; show: boolean; icon: React.ReactNode; softBg: string; softBorder: string }[] = [
        {
            key: 'telegram', label: 'Telegram', color: '#2AABEE', show: true,
            softBg: '#F2FAFF', softBorder: '#D8EEFA',
            icon: (
                <svg viewBox="0 0 24 24" fill="#2AABEE" width="20" height="20">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.07-.2-.08-.06-.19-.04-.27-.02-.12.03-2.02 1.28-5.69 3.77-.54.37-1.03.55-1.47.54-.48-.01-1.4-.27-2.09-.49-.84-.28-1.51-.42-1.45-.89.03-.25.38-.5 1.04-.77 4.07-1.77 6.79-2.94 8.15-3.5 3.88-1.62 4.69-1.9 5.21-1.91.12 0 .37.03.54.17.14.12.18.28.2.45-.01.06.01.24 0 .38z"/>
                </svg>
            ),
        },
        {
            key: 'max', label: 'MAX', color: '#6366F1', show: !!maxBotName,
            softBg: '#F7F3FF', softBorder: '#E4D9FF',
            icon: <img src="/images/providers/max.svg" alt="" style={{ width: '28px', height: '28px' }} />,
        },
        {
            key: 'vk', label: 'VK', color: '#0077FF', show: true,
            softBg: '#F2F7FF', softBorder: '#D7E6FF',
            icon: (
                <svg viewBox="0 0 24 24" fill="#0077FF" width="20" height="20">
                    <path d="M12.785 16.241s.288-.032.436-.194c.136-.148.132-.427.132-.427s-.02-1.304.587-1.496c.596-.189 1.362 1.259 2.174 1.814.613.42 1.079.328 1.079.328l2.172-.03s1.136-.07.598-.964c-.044-.073-.314-.66-1.618-1.866-1.365-1.264-1.182-1.06.46-3.246.999-1.332 1.398-2.145 1.273-2.496-.119-.334-.852-.246-.852-.246l-2.446.015s-.182-.025-.316.056c-.131.079-.216.263-.216.263s-.388 1.032-.906 1.91c-1.092 1.849-1.529 1.948-1.705 1.832-.415-.273-.311-1.098-.311-1.688 0-1.838.279-2.603-.545-2.804-.274-.067-.476-.112-1.177-.12-.901-.009-1.662.003-2.094.214-.288.142-.508.458-.372.476.17.023.557.104.762.383.265.362.255 1.176.255 1.176s.152 2.253-.355 2.535c-.348.192-.825-.2-1.843-2.004-.523-.928-.917-1.952-.917-1.952s-.076-.186-.212-.286c-.165-.121-.394-.16-.394-.16l-2.323.015s-.349.01-.477.162c-.114.135-.009.415-.009.415s1.822 4.255 3.882 6.403c1.886 1.967 4.032 1.836 4.032 1.836h.972z"/>
                </svg>
            ),
        },
    ];

    return (
        <div className="flex-1 overflow-y-auto" style={{ paddingBottom: '100px' }}>
            <div className="px-5 pt-5 pb-4">
                <h2 className="font-bold" style={{ fontSize: '22px', lineHeight: '28px', color: C.ink, letterSpacing: '-0.01em' }}>
                    Подтвердите запись
                </h2>
                <p className="mt-1" style={{ fontSize: '13px', color: C.muted }}>
                    Выберите мессенджер — там завершится подтверждение.
                </p>
            </div>

            {selectedService && selectedDate && selectedTime && (
                <div
                    className="mx-5"
                    style={{
                        padding: '14px 0',
                        borderTop: `1px solid ${C.line}`,
                        borderBottom: `1px solid ${C.line}`,
                    }}
                >
                    <p className="font-bold" style={{ fontSize: '14px', color: C.ink }}>
                        {selectedService.title}
                    </p>
                    <div className="flex items-center justify-between mt-1">
                        <span style={{ fontSize: '12px', color: C.muted }}>
                            {selectedDate.getDate()} {monthNamesGen[selectedDate.getMonth()]} · {selectedTime}
                        </span>
                        <span style={{ fontSize: '14px', fontWeight: 700, color: C.ink }}>
                            {selectedService.price.toLocaleString('ru-RU')} ₽
                        </span>
                    </div>
                </div>
            )}

            <div className="px-5 pt-4 space-y-3">
                {errors.limit && (
                    <div className="rounded-xl border px-4 py-3" style={{ borderColor: '#F5D0A0', background: '#FFF8F0' }}>
                        <p style={{ fontSize: '13px', color: '#B8860B' }}>{errors.limit}</p>
                    </div>
                )}
                {errors.time && (
                    <div className="rounded-xl border px-4 py-3" style={{ borderColor: '#F0C0C0', background: '#FFF5F5' }}>
                        <p style={{ fontSize: '13px', color: '#C44351' }}>{errors.time}</p>
                    </div>
                )}

                <div className="flex flex-col" style={{ gap: '8px' }}>
                    {providers.filter((p) => p.show).map((p) => (
                        <button
                            key={p.key}
                            onClick={() => onSubmit(p.key)}
                            disabled={loadingProvider !== null}
                            className="flex w-full items-center text-left transition-all disabled:opacity-50"
                            style={{
                                minHeight: '58px',
                                border: `1px solid ${p.softBorder}`,
                                borderRadius: '14px',
                                background: p.softBg,
                                padding: '9px 14px',
                                alignItems: 'center',
                                gap: '12px',
                            }}
                            onMouseEnter={(e) => { if (!loadingProvider) e.currentTarget.style.filter = 'brightness(0.97)'; }}
                            onMouseLeave={(e) => { e.currentTarget.style.filter = 'none'; }}
                        >
                            <div
                                className="flex shrink-0 items-center justify-center"
                                style={{ width: '40px', height: '40px', borderRadius: '12px', background: C.white }}
                            >
                                {p.icon}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p style={{ fontSize: '15px', fontWeight: 700, color: C.ink }}>
                                    {loadingProvider === p.key ? 'Отправка...' : p.label}
                                </p>
                            </div>
                            {loadingProvider === p.key ? (
                                <Loader2 className="shrink-0 animate-spin" style={{ width: '18px', height: '18px', color: p.color }} />
                            ) : (
                                <ChevronRightIcon className="shrink-0" style={{ width: '18px', height: '18px', color: C.muted }} />
                            )}
                        </button>
                    ))}
                </div>
            </div>

            <div className="px-5" style={{ marginTop: '14px', paddingBottom: '14px', fontSize: '12px', lineHeight: '18px', color: C.muted, textAlign: 'left' }}>
                Нажимая на мессенджер, вы принимаете{' '}
                <Link href="/offer" target="_blank" style={{ color: C.ink, fontWeight: 500, textDecoration: 'underline', textUnderlineOffset: '2px' }}>Публичную оферту</Link>
                {' '}и{' '}
                <Link href="/privacy" target="_blank" style={{ color: C.ink, fontWeight: 500, textDecoration: 'underline', textUnderlineOffset: '2px' }}>Политику обработки персональных данных</Link>.
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

/* ═══════════════ Action Dock ═══════════════ */

function ActionDock({ step, canNext, onAction }: { step: number; canNext: boolean; onAction: () => void }) {
    const labels: Record<number, string> = {
        1: 'Далее',
        2: 'Выбрать время',
        3: 'Далее',
    };

    return (
        <div
            className="sticky bottom-0 z-50"
            style={{
                background: 'rgba(255,255,255,.96)',
                backdropFilter: 'blur(8px)',
                WebkitBackdropFilter: 'blur(8px)',
                borderTop: `1px solid ${C.line}`,
                padding: '12px 20px calc(12px + env(safe-area-inset-bottom))',
            }}
        >
            <button
                onClick={onAction}
                disabled={!canNext}
                className="flex w-full items-center justify-center transition-all"
                style={{
                    height: '48px',
                    borderRadius: '12px',
                    background: canNext ? C.orange : '#D7D3CE',
                    color: C.white,
                    fontSize: '15px',
                    fontWeight: 700,
                    border: 'none',
                }}
            >
                {labels[step]}
            </button>
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

    const showHeader = step <= TOTAL_STEPS;

    return (
        <>
            <Head title="Запись — Вовремя" />

            <div className="flex min-h-screen flex-col" style={{ background: C.milk }}>
            <div className="mx-auto flex w-full flex-1 flex-col" style={{ maxWidth: '480px', background: C.white }}>
                {showHeader && (
                    <CompactHeader
                        step={step}
                        master={master}
                        showBack={step > 1}
                        onBack={handleBack}
                    />
                )}

                <div className="flex flex-col flex-1" style={{ padding: '0 20px' }}>
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
                            selectedService={selectedService}
                            selectedDate={selectedDate}
                            selectedTime={selectedTime}
                        />
                    )}
                    {step === 5 && selectedService && selectedDate && selectedTime && (
                        <StepConfirmation
                            service={selectedService}
                            date={selectedDate}
                            time={selectedTime}
                        />
                    )}
                </div>

                {step >= 1 && step <= 3 && (
                    <ActionDock step={step} canNext={canNext} onAction={handleNext} />
                )}

            </div>
            </div>
        </>
    );
}
