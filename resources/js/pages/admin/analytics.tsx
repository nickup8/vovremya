import { useState, useMemo, useCallback, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowDownTrayIcon, CalendarIcon, ChevronLeftIcon, ChevronRightIcon,
    ArrowTrendingUpIcon, ArrowTrendingDownIcon, ClockIcon, CalendarDaysIcon,
    ExclamationTriangleIcon, MagnifyingGlassIcon, PaperAirplaneIcon,
    ArrowsRightLeftIcon, CheckIcon,
} from '@heroicons/react/24/outline';
import AdminLayout from '@/layouts/AdminLayout';
import { ChannelsTab, TopChannelsBlock } from '@/components/admin/ChannelsAnalytics';
import type { ChannelSource, TrackingLinkItem } from '@/components/admin/ChannelsAnalytics';

/* ═══════════════ Types ═══════════════ */

interface Metrics {
    revenue: number;
    total_visits: number;
    operational_total_visits?: number;
    avg_check: number;
    attendance_rate: number;
    lost_revenue: number;
    cancelled_count: number;
    no_show_count: number;
    new_clients_count: number;
    returning_clients_count: number;
    first_visit_conversion: number | null;
    top_services: Array<{ name: string; count: number; percentage: number }>;
    utilization_percentage: number;
}

interface ChartPoint {
    label: string;
    value: number;
    count: number;
    percent: number;
}

interface AuthUser {
    name: string;
    tariff_name?: string;
    [key: string]: unknown;
}

interface AutofillMetrics {
    requests_created: number;
    offers_sent: number;
    offers_accepted: number;
    acceptance_rate: number;
    median_time_to_accept_seconds: number | null;
}

interface PageProps {
    metrics: Metrics;
    trends: Record<string, number>;
    prev_metrics: Record<string, number>;
    chartData: ChartPoint[];
    activePeriod: string;
    dateFrom: string | null;
    dateTo: string | null;
    auth?: { user?: AuthUser };
    activeTab?: string;
    channels_feature?: boolean;
    top_channels?: ChannelSource[] | null;
    channels?: ChannelSource[] | null;
    tracking_links?: TrackingLinkItem[] | null;
    autofill_feature?: boolean;
    autofill?: AutofillMetrics | null;
    [key: string]: unknown;
}

/* ═══════════════ Constants ═══════════════ */

// Полный набор prop-ключей, обновляемых при partial navigation (стабильная ссылка).
const RELOAD_ONLY = ['metrics', 'trends', 'prev_metrics', 'chartData', 'activePeriod', 'dateFrom', 'dateTo', 'activeTab', 'channels_feature', 'top_channels', 'channels', 'tracking_links', 'autofill_feature', 'autofill'];

const PERIOD_TABS: { key: string; label: string }[] = [
    { key: 'day', label: 'День' },
    { key: 'week', label: 'Неделя' },
    { key: 'month', label: 'Месяц' },
    { key: 'year', label: 'Год' },
    { key: 'custom', label: 'Период' },
];

/* ═══════════════ Helpers ═══════════════ */

/** Форматирует строку 'YYYY-MM-DD' в локальную дату без сдвига часового пояса */
function safeFormatDate(dateStr: string): string {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('T')[0].split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** Преобразует Date в строку 'YYYY-MM-DD' в локальном часовом поясе (без UTC-сдвига) */
function toLocalDateString(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/** Форматирует секунды в человекочитаемый формат */
function formatMedianSeconds(seconds: number | null): string {
    if (seconds === null || seconds === undefined) return '—';
    if (seconds < 60) return `${seconds} сек.`;
    if (seconds < 3600) return `${Math.round(seconds / 60)} мин.`;
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return m > 0 ? `${h} ч ${m} мин.` : `${h} ч`;
}

/** Вычисляет границы периода по его типу и смещению */
function computePeriodDates(period: string, offset: number): { from: string; to: string } | null {
    if (period === 'custom') return null;

    const now = new Date();
    let start: Date;
    let end: Date;

    switch (period) {
        case 'day': {
            start = new Date(now);
            start.setDate(now.getDate() + offset);
            end = new Date(start);
            break;
        }
        case 'week': {
            const day = now.getDay();
            const diff = day === 0 ? 6 : day - 1;
            start = new Date(now);
            start.setDate(now.getDate() - diff + offset * 7);
            end = new Date(start);
            end.setDate(start.getDate() + 6);
            break;
        }
        case 'month': {
            start = new Date(now.getFullYear(), now.getMonth() + offset, 1);
            end = new Date(now.getFullYear(), now.getMonth() + offset + 1, 0);
            break;
        }
        case 'year': {
            start = new Date(now.getFullYear() + offset, 0, 1);
            end = new Date(now.getFullYear() + offset, 11, 31);
            break;
        }
        default:
            return null;
    }

    return { from: toLocalDateString(start), to: toLocalDateString(end) };
}

const revenueSubtitle: Record<string, string> = {
    day: 'Почасовая выручка за сегодня',
    week: 'Выручка по дням недели',
    month: 'Ежедневная выручка за месяц',
    year: 'Ежемесячная выручка за год',
    custom: 'Выручка за выбранный период',
};

/* ═══════════════ Trend Chip ═══════════════ */

function TrendChip({ value, prevValue, format = 'percent' }: { value: number; prevValue?: number; format?: 'currency' | 'percent' | 'number' }) {
    const suffix = format === 'currency' ? ' ₽' : format === 'percent' ? '%' : '';
    const tooltip = prevValue !== undefined ? `В прошлом периоде: ${prevValue.toLocaleString('ru-RU')}${suffix}` : '';

    if (value === 0) {
        return (
            <span title={tooltip} className="inline-flex min-h-[22px] w-max items-center rounded-[7px] bg-[var(--color-surface-hover)] px-[7px] text-[10.5px] font-bold text-[var(--color-graphite)]">
                0{suffix}
            </span>
        );
    }

    const isPositive = value > 0;
    const cls = isPositive
        ? 'bg-[var(--color-paid-bg)] text-[var(--color-paid)]'
        : 'bg-[var(--color-noshow-bg)] text-[var(--color-noshow)]';

    return (
        <span title={tooltip} className={`inline-flex min-h-[22px] w-max items-center gap-0.5 rounded-[7px] px-[7px] text-[10.5px] font-bold ${cls}`}>
            {isPositive ? '↑' : '↓'} {Math.abs(value).toLocaleString('ru-RU')}{suffix}
        </span>
    );
}

/* ═══════════════ KPI Card ═══════════════ */

type KpiTone = 'positive' | 'neutral' | 'booked' | 'danger';

function KpiCard({ icon: Icon, tone, label, value, change, meta }: {
    icon: React.ElementType;
    tone: KpiTone;
    label: string;
    value: string;
    change?: React.ReactNode;
    meta?: string;
}) {
    const iconTone: Record<KpiTone, string> = {
        positive: 'bg-[var(--color-paid-bg)] text-[var(--color-paid)]',
        booked: 'bg-[var(--color-warm)] text-[var(--color-graphite)]',
        neutral: 'bg-[var(--color-warm)] text-[var(--color-graphite)]',
        danger: 'bg-[var(--color-noshow-bg)] text-[var(--color-noshow)]',
    };

    return (
        <article className="grid min-h-[150px] content-start gap-2 rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-[18px]">
            <div className={`mb-1 grid size-9 place-items-center rounded-[10px] ${iconTone[tone]}`}>
                <Icon className="size-[18px]" />
            </div>
            <p className="text-[11.5px] leading-4 text-[var(--color-graphite)]">{label}</p>
            <p className="text-[26px] font-bold leading-8 tracking-[-.035em] text-[var(--color-ink)]">{value}</p>
            {change}
            {meta && <p className="text-[11px] leading-4 text-[var(--color-graphite)]">{meta}</p>}
        </article>
    );
}

/* ═══════════════ Main Analytics Page ═══════════════ */

export default function AnalyticsPage() {
    const props = usePage<PageProps>().props;
    const metrics = props.metrics || { revenue: 0, total_visits: 0, avg_check: 0, attendance_rate: 0, lost_revenue: 0, cancelled_count: 0, no_show_count: 0, new_clients_count: 0, returning_clients_count: 0, first_visit_conversion: null, top_services: [], utilization_percentage: 0 };
    const trends = props.trends || { revenue: 0, avg_check: 0, utilization: 0 };
    const prev_metrics = props.prev_metrics || { revenue: 0, avg_check: 0, utilization: 0 };
    const chartData = props.chartData || [];
    const activePeriod = props.activePeriod || 'week';
    const auth = props.auth;

    const activeTab = props.activeTab === 'channels' ? 'channels' : props.activeTab === 'autofill' ? 'autofill' : 'overview';
    const channelsFeature = props.channels_feature === true;
    const autoFillFeature = props.autofill_feature === true;
    const autoFill = props.autofill;

    // Текущие query-параметры периода (сохраняются при переключении вкладок).
    function currentPeriodParams(): Record<string, string> {
        const p: Record<string, string> = { period: activePeriod };
        if (props.dateFrom) p.date_from = props.dateFrom;
        if (props.dateTo) p.date_to = props.dateTo;
        return p;
    }

    function switchTab(tab: 'overview' | 'channels' | 'autofill') {
        router.get('/admin/analytics', { ...currentPeriodParams(), tab }, {
            preserveState: true,
            preserveScroll: true,
            only: RELOAD_ONLY,
        });
    }

    const [activePoint, setActivePoint] = useState<ChartPoint | null>(null);
    const [dates, setDates] = useState({
        from: props.dateFrom || '',
        to: props.dateTo || '',
    });
    const [periodOffset, setPeriodOffset] = useState(0);

    useEffect(() => {
        if (!props.dateFrom && !props.dateTo) {
            setPeriodOffset(0);
        }
    }, [props.dateFrom, props.dateTo]);

    const totalValue = chartData.reduce((sum: number, point: ChartPoint) => sum + point.value, 0);
    const totalCount = chartData.reduce((sum: number, point: ChartPoint) => sum + point.count, 0);

    // Вычисления для карточки «Клиентская база»
    const totalClients = metrics.new_clients_count + metrics.returning_clients_count;
    const returningPct = totalClients > 0 ? Math.round((metrics.returning_clients_count / totalClients) * 100) : 0;
    const newPct = totalClients > 0 ? 100 - returningPct : 0;

    // Вычисления для карточки «Воронка визитов»
    const opVisits = metrics.operational_total_visits ?? metrics.total_visits;
    const funnelTotal = opVisits + metrics.cancelled_count + metrics.no_show_count;
    const paidPct = funnelTotal > 0 ? Math.round((opVisits / funnelTotal) * 100) : 0;
    const cancelPct = funnelTotal > 0 ? Math.round((metrics.cancelled_count / funnelTotal) * 100) : 0;
    const noShowPct = funnelTotal > 0 ? Math.round((metrics.no_show_count / funnelTotal) * 100) : 0;

    function handlePeriodChange(period: string) {
        setPeriodOffset(0);

        const bounds = computePeriodDates(period, 0);

        router.get('/admin/analytics', {
            period,
            tab: activeTab,
            ...(bounds ? { date_from: bounds.from, date_to: bounds.to } : {}),
        }, {
            preserveState: true,
            preserveScroll: true,
            only: RELOAD_ONLY,
        });
    }

    function handleCustomApply() {
        if (! dates.from || ! dates.to) return;

        router.get('/admin/analytics', {
            period: 'custom',
            tab: activeTab,
            date_from: dates.from,
            date_to: dates.to,
        }, {
            preserveState: true,
            preserveScroll: true,
            only: RELOAD_ONLY,
        });
    }

    const computedDates = useMemo(() => {
        return computePeriodDates(activePeriod, periodOffset);
    }, [activePeriod, periodOffset]);

    const presetDateRange = useMemo(() => {
        if (!computedDates) return null;
        return `${safeFormatDate(computedDates.from)} — ${safeFormatDate(computedDates.to)}`;
    }, [computedDates]);

    const handleOffsetChange = useCallback((delta: number) => {
        const newOffset = periodOffset + delta;
        setPeriodOffset(newOffset);

        if (activePeriod === 'custom') return;

        const bounds = computePeriodDates(activePeriod, newOffset);

        if (!bounds) return;

        router.get('/admin/analytics', {
            period: activePeriod,
            tab: activeTab,
            date_from: bounds.from,
            date_to: bounds.to,
        }, {
            preserveState: true,
            preserveScroll: true,
            only: RELOAD_ONLY,
        });
    }, [periodOffset, activePeriod, activeTab]);

    const kpis = [
        {
            icon: trends.avg_check >= 0 ? ArrowTrendingUpIcon : ArrowTrendingDownIcon,
            tone: (trends.avg_check >= 0 ? 'positive' : 'danger') as KpiTone,
            label: 'Средний чек',
            value: Math.round(metrics.avg_check).toLocaleString('ru-RU') + ' ₽',
            change: <TrendChip value={trends.avg_check} prevValue={prev_metrics.avg_check} format="percent" />,
        },
        {
            icon: ClockIcon,
            tone: 'booked' as KpiTone,
            label: 'Посещаемость',
            value: metrics.attendance_rate + '%',
            meta: `${opVisits} из ${funnelTotal} визитов состоялись`,
        },
        {
            icon: CalendarDaysIcon,
            tone: (metrics.utilization_percentage > 100 ? 'danger' : 'positive') as KpiTone,
            label: 'Заполняемость графика',
            value: `${metrics.utilization_percentage}%`,
            change: <TrendChip value={trends.utilization} prevValue={prev_metrics.utilization} format="percent" />,
        },
        {
            icon: ExclamationTriangleIcon,
            tone: 'danger' as KpiTone,
            label: 'Потенциальные потери',
            value: Math.round(metrics.lost_revenue).toLocaleString('ru-RU') + ' ₽',
            meta: `${metrics.cancelled_count} отмен / ${metrics.no_show_count} неявок`,
        },
    ];

    return (
        <>
            <Head title="Аналитика — Вовремя" />

            <AdminLayout title="Аналитика" auth={auth} hideNewAppointment fullBleed>
                <div className="min-h-full bg-[var(--color-admin-page-bg)] p-3 md:p-7">
                    <div className="grid max-w-[1320px] gap-4">
                        {/* ─── Period Controls + Export ─── */}
                        <section className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-[14px] md:p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex w-max max-w-full items-center gap-1.5 overflow-x-auto rounded-[14px] bg-[var(--color-warm)] p-1.5 shadow-[inset_0_0_0_1px_var(--color-line-soft)]">
                                    {PERIOD_TABS.map(({ key, label }) => (
                                        <button
                                            key={key}
                                            onClick={() => handlePeriodChange(key)}
                                            className={`h-9 shrink-0 rounded-[10px] px-4 text-sm font-semibold transition-colors max-md:min-h-11 ${
                                                activePeriod === key
                                                    ? 'bg-[var(--color-orange)] text-white shadow-[0_8px_16px_rgba(255,90,31,0.18)]'
                                                    : 'text-[var(--color-graphite)] hover:bg-[var(--color-surface-elevated)] hover:text-[var(--color-ink)]'
                                            }`}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                <button className="flex h-10 shrink-0 items-center justify-center gap-2 rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] px-3.5 text-sm font-semibold text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-hover)] max-md:size-11 max-md:min-h-11 max-md:min-w-11 max-md:px-0">
                                    <ArrowDownTrayIcon className="size-[18px]" />
                                    <span className="max-md:hidden">Экспорт</span>
                                </button>
                            </div>

                            {/* ─── Custom Date Range ─── */}
                            {activePeriod === 'custom' && (
                                <div className="mt-3.5 flex flex-wrap items-center gap-3 border-t border-[var(--color-line)] pt-3.5">
                                    <div className="flex items-center gap-2">
                                        <CalendarIcon className="size-4 text-[var(--color-graphite)]" />
                                        <span className="text-xs font-medium text-[var(--color-graphite)]">С</span>
                                        <input
                                            type="date"
                                            value={dates.from}
                                            onChange={(e) => setDates((d) => ({ ...d, from: e.target.value }))}
                                            className="rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-2 text-xs text-[var(--color-ink)] focus:border-[var(--color-orange)] focus:outline-none focus:ring-2 focus:ring-[var(--color-orange-100)] max-md:min-h-11"
                                        />
                                    </div>
                                    <span className="text-xs text-[var(--color-graphite)]">—</span>
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-medium text-[var(--color-graphite)]">По</span>
                                        <input
                                            type="date"
                                            value={dates.to}
                                            onChange={(e) => setDates((d) => ({ ...d, to: e.target.value }))}
                                            className="rounded-[10px] border border-[var(--color-line)] bg-[var(--color-surface)] px-3 py-2 text-xs text-[var(--color-ink)] focus:border-[var(--color-orange)] focus:outline-none focus:ring-2 focus:ring-[var(--color-orange-100)] max-md:min-h-11"
                                        />
                                    </div>
                                    <button
                                        onClick={handleCustomApply}
                                        disabled={!dates.from || !dates.to}
                                        className="rounded-[10px] bg-[var(--color-orange)] px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-[var(--color-orange-600)] disabled:cursor-not-allowed disabled:opacity-50 max-md:min-h-11"
                                    >
                                        Применить
                                    </button>
                                </div>
                            )}

                            {activePeriod !== 'custom' && presetDateRange && (
                                <div className="mt-3.5 flex items-center gap-2 border-t border-[var(--color-line)] pt-3.5">
                                    <button
                                        onClick={() => handleOffsetChange(-1)}
                                        className="grid size-10 shrink-0 place-items-center rounded-[10px] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] max-md:size-11"
                                        aria-label="Предыдущий период"
                                    >
                                        <ChevronLeftIcon className="size-[18px]" />
                                    </button>
                                    <p className="text-[15px] font-semibold text-[var(--color-ink)]">{presetDateRange}</p>
                                    <button
                                        onClick={() => handleOffsetChange(1)}
                                        className="grid size-10 shrink-0 place-items-center rounded-[10px] text-[var(--color-graphite)] transition-colors hover:bg-[var(--color-surface-hover)] max-md:size-11"
                                        aria-label="Следующий период"
                                    >
                                        <ChevronRightIcon className="size-[18px]" />
                                    </button>
                                </div>
                            )}
                        </section>
                        {/* ─── Tabs ─── */}
                        <div role="tablist" aria-label="Раздел аналитики" className="flex h-[43px] items-center gap-6 overflow-x-auto overflow-y-hidden border-b border-[var(--color-line)] max-md:h-11">
                            <button
                                role="tab"
                                aria-selected={activeTab === 'overview'}
                                onClick={() => switchTab('overview')}
                                className={`relative h-[43px] shrink-0 px-0.5 text-[13.5px] font-semibold transition-colors max-md:h-11 ${
                                    activeTab === 'overview'
                                        ? 'text-[var(--color-ink)] after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:rounded-t-[3px] after:bg-[var(--color-orange)]'
                                        : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                                }`}
                            >
                                Обзор
                            </button>
                            <button
                                role="tab"
                                aria-selected={activeTab === 'channels'}
                                onClick={() => switchTab('channels')}
                                className={`relative h-[43px] shrink-0 px-0.5 text-[13.5px] font-semibold transition-colors max-md:h-11 ${
                                    activeTab === 'channels'
                                        ? 'text-[var(--color-ink)] after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:rounded-t-[3px] after:bg-[var(--color-orange)]'
                                        : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                                }`}
                            >
                                Каналы записи
                            </button>
                            {autoFillFeature && (
                                <button
                                    role="tab"
                                    aria-selected={activeTab === 'autofill'}
                                    onClick={() => switchTab('autofill')}
                                    className={`relative h-[43px] shrink-0 px-0.5 text-[13.5px] font-semibold transition-colors max-md:h-11 ${
                                        activeTab === 'autofill'
                                            ? 'text-[var(--color-ink)] after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:rounded-t-[3px] after:bg-[var(--color-orange)]'
                                            : 'text-[var(--color-graphite)] hover:text-[var(--color-ink)]'
                                    }`}
                                >
                                    Автозаполнение
                                </button>
                            )}
                        </div>
                        {/* ─── Channels Tab ─── */}
                        {activeTab === 'channels' && (
                            <ChannelsTab
                                feature={channelsFeature}
                                channels={props.channels ?? null}
                                trackingLinks={props.tracking_links ?? null}
                            />
                        )}
                        {/* ─── AutoFill Tab ─── */}
                        {activeTab === 'autofill' && autoFillFeature && (
                            <div className="grid gap-4">
                                {/* Process chain — 3 события */}
                                <article className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                    <div className="mb-4 flex items-start justify-between gap-4">
                                        <div>
                                            <h2 className="text-[18px] font-bold leading-6 tracking-[-.02em] text-[var(--color-ink)]">Автозаполнение</h2>
                                            <p className="mt-1 text-xs leading-[17px] text-[var(--color-graphite)]">Как сервис помогает переносить записи на освободившееся более раннее время</p>
                                        </div>
                                        <span className="inline-flex h-7 shrink-0 items-center rounded-full bg-[var(--color-warm)] px-2.5 text-[11px] font-semibold text-[var(--color-graphite)]">За выбранный период</span>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        {[
                                            { icon: MagnifyingGlassIcon, tone: 'neutral', label: 'Запросов на поиск', value: autoFill?.requests_created ?? 0, hint: 'Клиенты попросили найти время раньше' },
                                            { icon: PaperAirplaneIcon, tone: 'booked', label: 'Предложений отправлено', value: autoFill?.offers_sent ?? 0, hint: 'Клиентам отправлены найденные варианты' },
                                            { icon: ArrowsRightLeftIcon, tone: 'paid', label: 'Переносов выполнено', value: autoFill?.offers_accepted ?? 0, hint: 'Записи перенесены на более раннее время' },
                                        ].map((ev) => {
                                            const iconTone = ev.tone === 'booked'
                                                ? 'bg-[var(--color-warm)] text-[var(--color-graphite)]'
                                                : ev.tone === 'paid'
                                                    ? 'bg-[var(--color-paid-bg)] text-[var(--color-paid)]'
                                                    : 'bg-[var(--color-warm)] text-[var(--color-graphite)]';
                                            return (
                                                <div key={ev.label} className="grid min-h-[142px] grid-cols-[38px_minmax(0,1fr)] content-start gap-3 rounded-[14px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-4">
                                                    <div className={`grid size-[38px] place-items-center rounded-[10px] ${iconTone}`}>
                                                        <ev.icon className="size-[18px]" />
                                                    </div>
                                                    <div className="grid content-start">
                                                        <span className="text-xs leading-[17px] text-[var(--color-graphite)]">{ev.label}</span>
                                                        <strong className="mt-1 text-[30px] font-bold leading-[34px] tracking-[-.035em] text-[var(--color-ink)]">{ev.value}</strong>
                                                        <small className="mt-1.5 text-[11px] leading-4 text-[var(--color-graphite)]">{ev.hint}</small>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </article>

                                {/* 2 метрики */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <article className="grid min-h-[180px] content-start gap-3 rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                        <div className="flex items-start justify-between gap-3.5">
                                            <div>
                                                <div className="text-xs leading-[17px] text-[var(--color-graphite)]">Доля принятых</div>
                                                <div className="mt-1.5 text-[34px] font-bold leading-[38px] tracking-[-.04em] text-[var(--color-ink)]">{autoFill ? `${autoFill.acceptance_rate}%` : '—'}</div>
                                            </div>
                                            <div className="grid size-[38px] place-items-center rounded-[10px] bg-[var(--color-paid-bg)] text-[var(--color-paid)]">
                                                <CheckIcon className="size-[18px]" />
                                            </div>
                                        </div>
                                        <div className="h-2.5 overflow-hidden rounded-full bg-[var(--color-warm)]">
                                            <span className="block h-full rounded-full bg-[var(--color-paid)]" style={{ width: `${autoFill?.acceptance_rate ?? 0}%` }} />
                                        </div>
                                        <p className="text-xs leading-[17px] text-[var(--color-graphite)]">Доля отправленных предложений, принятых клиентами.</p>
                                    </article>

                                    <article className="grid min-h-[180px] content-start gap-3 rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                        <div className="flex items-start justify-between gap-3.5">
                                            <div>
                                                <div className="text-xs leading-[17px] text-[var(--color-graphite)]">Медианное время ответа</div>
                                                <div className="mt-1.5 text-[34px] font-bold leading-[38px] tracking-[-.04em] text-[var(--color-ink)]">{formatMedianSeconds(autoFill?.median_time_to_accept_seconds ?? null)}</div>
                                            </div>
                                            <div className="grid size-[38px] place-items-center rounded-[10px] bg-[var(--color-warm)] text-[var(--color-graphite)]">
                                                <ClockIcon className="size-[18px]" />
                                            </div>
                                        </div>
                                        <p className="text-xs leading-[17px] text-[var(--color-graphite)]">Насколько быстро клиенты реагируют на предложение о переносе.</p>
                                    </article>
                                </div>
                            </div>
                        )}
                        {/* ─── Overview Tab ─── */}
                        {activeTab === 'overview' && (
                            <div className="grid gap-4">
                                {/* KPI grid */}
                                <div className="grid grid-cols-1 gap-4 min-[601px]:grid-cols-2 min-[1181px]:grid-cols-4">
                                    {kpis.map((kpi) => (
                                        <KpiCard key={kpi.label} {...kpi} />
                                    ))}
                                </div>

                                {/* Row 1: Revenue + Ranking */}
                                <div className="grid grid-cols-1 gap-4 min-[1181px]:grid-cols-2">
                                    {/* Выручка по периодам */}
                                    <article className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                        <header className="mb-[18px]">
                                            <h2 className="text-[15px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">Выручка по периодам</h2>
                                            <p className="mt-[3px] text-[11.5px] leading-4 text-[var(--color-graphite)]">{revenueSubtitle[activePeriod]}</p>
                                        </header>

                                        {/* Dynamic summary */}
                                        <div className="flex min-h-[3.75rem] flex-col items-start justify-end">
                                            <div className="flex flex-wrap items-center gap-2 transition-all duration-300">
                                                <strong className="text-[28px] font-bold leading-[34px] tracking-[-.035em] text-[var(--color-ink)]">
                                                    {activePoint ? `${Math.round(activePoint.value).toLocaleString('ru-RU')} ₽` : `${Math.round(totalValue).toLocaleString('ru-RU')} ₽`}
                                                </strong>
                                                {!activePoint && (
                                                    <>
                                                        <TrendChip value={trends.revenue} prevValue={prev_metrics.revenue} format="percent" />
                                                        <span className="text-[11px] text-[var(--color-graphite)]">к прошлому периоду</span>
                                                    </>
                                                )}
                                            </div>
                                            <div className="mt-0.5 text-[11px] text-[var(--color-graphite)] transition-all duration-300">
                                                {activePoint ? (
                                                    <>{activePoint.label} <span className="mx-1.5 text-[var(--color-line)]">·</span> Записей: {activePoint.count}</>
                                                ) : (
                                                    <>Итого за период <span className="mx-1.5 text-[var(--color-line)]">·</span> Записей: {totalCount}</>
                                                )}
                                            </div>
                                        </div>

                                        {Array.isArray(chartData) && chartData.length > 0 ? (
                                            <div className="relative mt-4 w-full overflow-x-auto scrollbar-none">
                                                {/* Gridlines */}
                                                <div className="pointer-events-none absolute inset-0 flex flex-col justify-between">
                                                    {[...Array(4)].map((_, i) => (
                                                        <div key={i} className="w-full border-t border-[var(--color-line-soft)]" />
                                                    ))}
                                                </div>
                                                <div
                                                    className={`relative z-10 flex items-end gap-2 px-2 pb-8 pt-2 ${chartData.length > 15 ? 'min-w-[700px]' : ''}`}
                                                    style={{ height: '212px' }}
                                                    onMouseLeave={() => setActivePoint(null)}
                                                >
                                                    {chartData.map((point, i) => (
                                                        <div key={i} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2">
                                                            <div
                                                                role="button"
                                                                tabIndex={0}
                                                                aria-label={`${point.label}: ${Math.round(point.value).toLocaleString('ru-RU')} ₽`}
                                                                data-active={activePoint?.label === point.label ? 'true' : 'false'}
                                                                className={`w-full max-w-8 cursor-default rounded-t-[8px] transition-all duration-300 ${
                                                                    activePoint
                                                                        ? activePoint.label === point.label
                                                                            ? 'bg-[var(--color-orange)] opacity-100'
                                                                            : 'bg-[var(--color-graphite)] opacity-25'
                                                                        : 'bg-[var(--color-graphite)] opacity-60'
                                                                }`}
                                                                style={{ height: `${point.percent}%`, minHeight: point.percent > 0 ? '4px' : '0' }}
                                                                onMouseEnter={() => setActivePoint(point)}
                                                                onClick={() => setActivePoint(point)}
                                                            />
                                                            <span className="w-full truncate text-center text-[10.5px] font-medium text-[var(--color-graphite)]">
                                                                {point.label}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="flex h-48 items-center justify-center">
                                                <p className="text-sm text-[var(--color-graphite)]">Нет данных за период</p>
                                            </div>
                                        )}
                                    </article>

                                    {/* Рейтинг услуг */}
                                    <article className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                        <header className="mb-[18px]">
                                            <h2 className="text-[15px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">Рейтинг услуг</h2>
                                            <p className="mt-[3px] text-[11.5px] leading-4 text-[var(--color-graphite)]">Популярность процедур</p>
                                        </header>
                                        <div className="grid gap-4">
                                            {metrics.top_services.length > 0 ? metrics.top_services.map((service: { name: string; count: number; percentage: number }, index: number) => (
                                                <div key={index} className="grid gap-2">
                                                    <div className="flex items-center justify-between gap-3 text-[12.5px]">
                                                        <strong className="truncate font-semibold text-[var(--color-ink)]">{service.name}</strong>
                                                        <span className="shrink-0 font-bold text-[var(--color-ink)]">{service.percentage}% <small className="text-[10.5px] font-medium text-[var(--color-graphite)]">({service.count})</small></span>
                                                    </div>
                                                    <div className="h-2 overflow-hidden rounded-full bg-[var(--color-warm)]">
                                                        <span className="block h-full rounded-full bg-[var(--color-orange)] transition-all duration-500" style={{ width: `${service.percentage}%` }} />
                                                    </div>
                                                </div>
                                            )) : (
                                                <p className="py-4 text-center text-sm text-[var(--color-graphite)]">Нет данных за выбранный период</p>
                                            )}
                                        </div>
                                    </article>
                                </div>

                                {/* Row 2: Client base + Funnel */}
                                <div className="grid grid-cols-1 gap-4 min-[1181px]:grid-cols-2">
                                    {/* Клиентская база */}
                                    <article className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                        <header className="mb-[18px]">
                                            <h2 className="text-[15px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">Клиентская база</h2>
                                            <p className="mt-[3px] text-[11.5px] leading-4 text-[var(--color-graphite)]">Новые и постоянные клиенты за период</p>
                                        </header>
                                        <div className="flex flex-col items-center gap-6 sm:flex-row sm:gap-6">
                                            <div
                                                className="relative grid size-[150px] shrink-0 place-items-center rounded-full"
                                                style={{ background: `conic-gradient(var(--color-orange) 0 ${newPct}%, var(--color-graphite) ${newPct}% 100%)` }}
                                                aria-label={`Всего ${totalClients} клиентов`}
                                            >
                                                <div className="grid size-[104px] place-items-center rounded-full bg-[var(--color-surface-elevated)]">
                                                    <div className="text-center">
                                                        <div className="text-2xl font-bold tracking-[-.04em] text-[var(--color-ink)]">{totalClients}</div>
                                                        <div className="text-[11px] text-[var(--color-graphite)]">Всего</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="grid w-full flex-1 gap-3.5">
                                                <div className="flex items-center justify-between gap-4 text-[12.5px]">
                                                    <span className="flex items-center gap-2 text-[var(--color-graphite)]"><i className="size-2 rounded-full bg-[var(--color-graphite)]" />Постоянные</span>
                                                    <strong className="font-bold text-[var(--color-ink)]">{returningPct}% <small className="text-[10.5px] font-medium text-[var(--color-graphite)]">({metrics.returning_clients_count})</small></strong>
                                                </div>
                                                <div className="flex items-center justify-between gap-4 text-[12.5px]">
                                                    <span className="flex items-center gap-2 text-[var(--color-graphite)]"><i className="size-2 rounded-full bg-[var(--color-orange)]" />Новые</span>
                                                    <strong className="font-bold text-[var(--color-ink)]">{newPct}% <small className="text-[10.5px] font-medium text-[var(--color-graphite)]">({metrics.new_clients_count})</small></strong>
                                                </div>
                                                {metrics.first_visit_conversion !== null && metrics.first_visit_conversion > 0 && (
                                                    <div className="mt-1 rounded-[10px] bg-[var(--color-warm)] p-3">
                                                        <div className="text-[11px] text-[var(--color-graphite)]">Конверсия первого визита</div>
                                                        <div className="mt-0.5 text-[12.5px] font-medium text-[var(--color-ink)]">{metrics.first_visit_conversion}% новых клиентов записываются повторно</div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </article>

                                    {/* Воронка визитов */}
                                    <article className="rounded-[16px] border border-[var(--color-line)] bg-[var(--color-surface-elevated)] p-5">
                                        <header className="mb-[18px]">
                                            <h2 className="text-[15px] font-bold leading-[21px] tracking-[-.015em] text-[var(--color-ink)]">Воронка визитов</h2>
                                            <p className="mt-[3px] text-[11.5px] leading-4 text-[var(--color-graphite)]">Статусы записей и упущенная выгода</p>
                                        </header>
                                        <div className="grid gap-5">
                                            {[
                                                { dot: 'var(--color-paid)', label: 'Успешно завершены', pct: paidPct, count: opVisits },
                                                { dot: 'var(--color-pending)', label: 'Отменены клиентом', pct: cancelPct, count: metrics.cancelled_count },
                                                { dot: 'var(--color-noshow)', label: 'Неявки (No-show)', pct: noShowPct, count: metrics.no_show_count },
                                            ].map((row) => (
                                                <div key={row.label} className="grid gap-2">
                                                    <div className="flex items-center justify-between gap-3 text-[12.5px]">
                                                        <span className="flex items-center gap-2 font-semibold text-[var(--color-ink)]"><i className="size-[7px] rounded-full" style={{ background: row.dot }} />{row.label}</span>
                                                        <strong className="font-bold text-[var(--color-ink)]">{row.pct}% <small className="text-[10.5px] font-medium text-[var(--color-graphite)]">({row.count})</small></strong>
                                                    </div>
                                                    <div className="h-2 overflow-hidden rounded-full bg-[var(--color-warm)]">
                                                        <span className="block h-full rounded-full" style={{ width: `${row.pct}%`, background: row.dot }} />
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </article>
                                </div>

                                {/* TOP-5 каналов */}
                                <TopChannelsBlock
                                    channels={props.top_channels ?? null}
                                    feature={channelsFeature}
                                    onSeeAll={() => switchTab('channels')}
                                />
                            </div>
                        )}
                    </div>
                </div>
            </AdminLayout>
        </>
    );
}
