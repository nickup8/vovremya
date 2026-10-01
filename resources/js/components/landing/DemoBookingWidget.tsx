import { useMemo, useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight, MapPin, Search } from 'lucide-react';

/* Landing v2, section 02 — interactive booking demo.
   Visual/UX mirror of resources/js/pages/booking/widget.tsx, but everything is local:
   no API calls, no router, no appointment is created. */

interface DemoService {
    id: string;
    title: string;
    price: number;
    minutes: number;
}

const SERVICES: DemoService[] = [
    { id: 'gel-polish', title: 'Маникюр + гель-лак', price: 1900, minutes: 90 },
    { id: 'strengthening', title: 'Укрепление ногтей', price: 1600, minutes: 60 },
    { id: 'pedicure-coat', title: 'Педикюр + покрытие', price: 2500, minutes: 90 },
    { id: 'nail-art', title: 'Дизайн ногтей', price: 700, minutes: 30 },
];

const MONTH_NAMES = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const DAY_LETTERS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const WEEK_FULL = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
const MONTH_GEN = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

const ALL_SLOTS = [
    '09:00', '09:15', '09:30', '09:45',
    '10:00', '10:15', '10:30', '10:45',
    '11:00', '11:15', '11:30', '11:45',
    '12:00', '12:15', '12:30',
    '14:00', '14:15', '14:30', '14:45',
    '15:00', '15:15', '15:30',
    '16:30', '16:45', '17:00', '17:15', '17:30',
];

const TOTAL_STEPS = 4;

const VK_ICON_PATH =
    'M12.785 16.241s.288-.032.436-.194c.136-.148.132-.427.132-.427s-.02-1.304.587-1.496c.596-.189 1.362 1.259 2.174 1.814.613.42 1.079.328 1.079.328l2.172-.03s1.136-.07.598-.964c-.044-.073-.314-.66-1.618-1.866-1.365-1.264-1.182-1.06.46-3.246.999-1.332 1.398-2.145 1.273-2.496-.119-.334-.852-.246-.852-.246l-2.446.015s-.182-.025-.316.056c-.131.079-.216.263-.216.263s-.388 1.032-.906 1.91c-1.092 1.849-1.529 1.948-1.705 1.832-.415-.273-.311-1.098-.311-1.688 0-1.838.279-2.603-.545-2.804-.274-.067-.476-.112-1.177-.12-.901-.009-1.662.003-2.094.214-.288.142-.508.458-.372.476.17.023.557.104.762.383.265.362.255 1.176.255 1.176s.152 2.253-.355 2.535c-.348.192-.825-.2-1.843-2.004-.523-.928-.917-1.952-.917-1.952s-.076-.186-.212-.286c-.165-.121-.394-.16-.394-.16l-2.323.015s-.349.01-.477.162c-.114.135-.009.415-.009.415s1.822 4.255 3.882 6.403c1.886 1.967 4.032 1.836 4.032 1.836h.972z';

/* Monday-first month grid, same as production. */
function getMonthGrid(year: number, month: number): (Date | null)[] {
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();

    let startOffset = firstDay.getDay() - 1;
    if (startOffset < 0) startOffset = 6;

    const cells: (Date | null)[] = [];
    for (let i = 0; i < startOffset; i++) cells.push(null);
    for (let day = 1; day <= daysInMonth; day++) cells.push(new Date(year, month, day));
    while (cells.length % 7 !== 0) cells.push(null);

    return cells;
}

/* Local availability only — deterministic, no network, no timezone shifts. */
function isDayEnabled(date: Date, today: Date): boolean {
    if (date.getTime() < today.getTime()) return false;
    if (date.getDay() === 0) return false;
    return (date.getDate() + date.getMonth()) % 6 !== 2;
}

function slotsFor(date: Date): string[] {
    const seed = date.getDate() + date.getMonth();
    return ALL_SLOTS.filter((_, index) => (index + seed) % 5 !== 0);
}

function formatDuration(minutes: number): string {
    if (minutes < 60) return `${minutes} мин`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest ? `${hours} ч ${rest} мин` : `${hours} ч`;
}

function formatPrice(price: number): string {
    return `${price.toLocaleString('ru-RU')} ₽`;
}

function formatDateLong(date: Date): string {
    return `${WEEK_FULL[date.getDay()]}, ${date.getDate()} ${MONTH_GEN[date.getMonth()]}`;
}

export default function DemoBookingWidget() {
    const today = useMemo(() => {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }, []);

    const [step, setStep] = useState(1);
    const [finished, setFinished] = useState(false);
    const [search, setSearch] = useState('');
    const [serviceId, setServiceId] = useState<string | null>(null);
    const [date, setDate] = useState<Date | null>(null);
    const [time, setTime] = useState<string | null>(null);
    const [viewMonth, setViewMonth] = useState(today.getMonth());
    const [viewYear, setViewYear] = useState(today.getFullYear());

    const service = SERVICES.find((item) => item.id === serviceId) ?? null;
    const canContinue =
        (step === 1 && service !== null) ||
        (step === 2 && date !== null) ||
        (step === 3 && time !== null);

    const query = search.trim().toLowerCase();
    const filteredServices = query ? SERVICES.filter((item) => item.title.toLowerCase().includes(query)) : SERVICES;

    const cells = useMemo(() => getMonthGrid(viewYear, viewMonth), [viewYear, viewMonth]);

    const shiftMonth = (delta: number) => {
        const next = new Date(viewYear, viewMonth + delta, 1);
        setViewMonth(next.getMonth());
        setViewYear(next.getFullYear());
    };

    const selectDate = (next: Date) => {
        setDate(next);
        setTime(null);
    };

    const handleAction = () => {
        if (step < TOTAL_STEPS) setStep(step + 1);
    };

    const handleBack = () => {
        if (step > 1) setStep(step - 1);
    };

    let body: ReactNode = null;
    if (finished) {
        body = (
            <div className="demo-success">
                <div className="demo-success-icon" aria-hidden="true">
                    ✓
                </div>
                <p>
                    <strong>Демо завершено.</strong> Так клиент проходит онлайн-запись в ИРСИ.
                </p>
                <a className="demo-cta demo-success-cta" href="/auth/login">
                    Начать бесплатно
                </a>
            </div>
        );
    } else if (step === 1) {
        body = (
            <>
                <h3 className="demo-title">Выберите услугу</h3>
                <p className="demo-sub">Выберите один вариант, чтобы перейти к свободному времени.</p>
                <div className="demo-search">
                    <Search className="demo-search-icon" style={{ width: '16px', height: '16px' }} aria-hidden="true" />
                    <input
                        type="text"
                        placeholder="Найти услугу"
                        aria-label="Найти услугу"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <div className="demo-list" role="radiogroup" aria-label="Услуги">
                    {filteredServices.length === 0 && <p className="demo-empty">Ничего не найдено</p>}
                    {filteredServices.map((item) => {
                        const selected = item.id === serviceId;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                className={`demo-service${selected ? ' is-selected' : ''}`}
                                onClick={() => setServiceId(item.id)}
                            >
                                <span className="demo-service-main">
                                    <span className="demo-service-title">{item.title}</span>
                                    <span className="demo-meta">{formatDuration(item.minutes)}</span>
                                </span>
                                <span className="demo-price">{formatPrice(item.price)}</span>
                                <span className="demo-radio" aria-hidden="true">
                                    {selected ? '✓' : ''}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </>
        );
    } else if (step === 2) {
        body = (
            <>
                <h3 className="demo-title">Выберите дату</h3>
                <p className="demo-sub">Недоступные дни отмечены серым.</p>
                <div className="demo-cal">
                    <div className="demo-cal-head">
                        <p className="demo-cal-month">
                            {MONTH_NAMES[viewMonth]} {viewYear}
                        </p>
                        <div className="demo-cal-nav">
                            <button type="button" aria-label="Предыдущий месяц" onClick={() => shiftMonth(-1)}>
                                <ChevronLeft style={{ width: '20px', height: '20px' }} />
                            </button>
                            <button type="button" aria-label="Следующий месяц" onClick={() => shiftMonth(1)}>
                                <ChevronRight style={{ width: '20px', height: '20px' }} />
                            </button>
                        </div>
                    </div>
                    <div className="demo-cal-week" aria-hidden="true">
                        {DAY_LETTERS.map((letter) => (
                            <span key={letter}>{letter}</span>
                        ))}
                    </div>
                    <div className="demo-cal-grid">
                        {cells.map((cell, index) => {
                            if (!cell) return <span key={`empty-${index}`} aria-hidden="true" />;
                            const past = cell.getTime() < today.getTime();
                            const enabled = isDayEnabled(cell, today);
                            const disabled = !enabled;
                            const selected = date !== null && cell.getTime() === date.getTime();
                            const isToday = cell.getTime() === today.getTime();
                            return (
                                <button
                                    key={cell.getTime()}
                                    type="button"
                                    disabled={disabled}
                                    aria-pressed={selected}
                                    className={[
                                        'demo-cal-day',
                                        selected ? 'is-selected' : '',
                                        isToday ? 'is-today' : '',
                                        disabled && !past ? 'is-off' : '',
                                    ]
                                        .filter(Boolean)
                                        .join(' ')}
                                    onClick={() => !disabled && selectDate(cell)}
                                >
                                    {cell.getDate()}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </>
        );
    } else if (step === 3) {
        body = (
            <>
                <h3 className="demo-title">Выберите время</h3>
                <p className="demo-sub">{date ? formatDateLong(date) : ''}</p>
                <div className="demo-times">
                    {date &&
                        slotsFor(date).map((slot) => (
                            <button
                                key={slot}
                                type="button"
                                aria-pressed={time === slot}
                                className={`demo-time${time === slot ? ' is-selected' : ''}`}
                                onClick={() => setTime(slot)}
                            >
                                {slot}
                            </button>
                        ))}
                </div>
            </>
        );
    } else if (service && date && time) {
        body = (
            <>
                <h3 className="demo-title">Подтвердите запись</h3>
                <p className="demo-sub">Выберите мессенджер — там завершится подтверждение.</p>
                <div className="demo-confirm-summary">
                    <p className="demo-confirm-service">{service.title}</p>
                    <div className="demo-confirm-row">
                        <span className="demo-confirm-when">
                            {date.getDate()} {MONTH_GEN[date.getMonth()]} · {time}
                        </span>
                        <span className="demo-confirm-price">{formatPrice(service.price)}</span>
                    </div>
                </div>
                <div className="demo-providers">
                    <button type="button" className="demo-provider is-max" onClick={() => setFinished(true)}>
                        <span className="demo-provider-icon">
                            <img src="/images/providers/max.svg" alt="" width="24" height="24" />
                        </span>
                        <span className="demo-provider-label">MAX</span>
                        <ChevronRight className="demo-provider-chevron" style={{ width: '18px', height: '18px' }} />
                    </button>
                    <button type="button" className="demo-provider is-vk" onClick={() => setFinished(true)}>
                        <span className="demo-provider-icon">
                            <svg viewBox="0 0 24 24" fill="#0077FF" width="20" height="20" aria-hidden="true">
                                <path d={VK_ICON_PATH} />
                            </svg>
                        </span>
                        <span className="demo-provider-label">VK</span>
                        <ChevronRight className="demo-provider-chevron" style={{ width: '18px', height: '18px' }} />
                    </button>
                </div>
                <p className="demo-offer">
                    Нажимая на мессенджер, Вы принимаете{' '}
                    <a href="/offer" target="_blank" rel="noreferrer">
                        Публичную оферту
                    </a>{' '}
                    и{' '}
                    <a href="/privacy" target="_blank" rel="noreferrer">
                        Политику обработки персональных данных
                    </a>
                    .
                </p>
            </>
        );
    }

    const dockLabel = step === 2 ? 'Выбрать время' : 'Далее';
    const showDock = !finished && step < TOTAL_STEPS;

    return (
        <div className="demo" role="region" aria-label="Демо онлайн-записи">
            <div className="demo-head">
                <div className="demo-head-row">
                    <div className="demo-head-left">
                        {step > 1 && !finished && (
                            <button type="button" className="demo-back" onClick={handleBack}>
                                <span aria-hidden="true">←</span> Назад
                            </button>
                        )}
                    </div>
                    <span className="demo-step" aria-live="polite">
                        {finished ? 'Демо завершено' : `Шаг ${step} из ${TOTAL_STEPS}`}
                    </span>
                    <div className="demo-mark">
                        <img src="/images/logo-mark.svg" alt="ИРСИ" width="22" height="22" />
                    </div>
                </div>
                <div className="demo-progress" aria-hidden="true">
                    <i style={{ width: `${finished ? 100 : step * 25}%` }} />
                </div>
                <div className="demo-master">
                    <div className="demo-master-avatar" aria-hidden="true">
                        АК
                    </div>
                    <div className="demo-master-info">
                        <p className="demo-master-name">Анна Крылова</p>
                        <p className="demo-master-address">
                            <MapPin style={{ width: '13px', height: '13px', flexShrink: 0, marginTop: '1px' }} aria-hidden="true" />
                            <span>г. Нижний Новгород, ул. Большая Покровская, д. 25</span>
                        </p>
                    </div>
                </div>
            </div>

            <div className="demo-body">{body}</div>

            {showDock && (
                <div className="demo-dock">
                    <button type="button" className="demo-cta" onClick={handleAction} disabled={!canContinue}>
                        {dockLabel}
                    </button>
                </div>
            )}
        </div>
    );
}
