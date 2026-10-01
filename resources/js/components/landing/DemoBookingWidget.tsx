import { useMemo, useState, type ReactNode } from 'react';

/* Landing v2, section 02 — interactive booking demo.
   Everything is local: no API calls, no router, no appointment is created.
   Visual style follows resources/js/pages/booking/widget.tsx, but no code is shared with it. */

interface DemoService {
    id: string;
    title: string;
    price: number;
    minutes: number;
}

const SERVICES: DemoService[] = [
    { id: 'manicure-base', title: 'Маникюр без покрытия', price: 1100, minutes: 45 },
    { id: 'manicure-gel', title: 'Маникюр + гель-лак', price: 1900, minutes: 90 },
    { id: 'manicure-strengthen', title: 'Маникюр + укрепление', price: 2300, minutes: 105 },
    { id: 'pedicure-base', title: 'Педикюр без покрытия', price: 1700, minutes: 60 },
    { id: 'pedicure-coat', title: 'Педикюр + покрытие', price: 2500, minutes: 90 },
    { id: 'removal', title: 'Снятие покрытия', price: 500, minutes: 30 },
];

const FULL_SLOTS = ['10:00', '11:30', '13:30', '15:00', '17:30'];
const SHORT_SLOTS = ['10:00', '13:30', '15:00', '17:30'];

const WEEK_SHORT = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const WEEK_FULL = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
const MONTH_GEN = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

const TOTAL_STEPS = 4;
const VISIBLE_DAYS = 14;

interface DemoDay {
    date: Date;
    index: number;
    enabled: boolean;
}

/* Local dates only: built with the numeric local constructor, never via ISO strings,
   so the calendar never shifts across timezones. */
function buildDays(): DemoDay[] {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    return Array.from({ length: VISIBLE_DAYS }, (_, index) => {
        const date = new Date(today.getFullYear(), today.getMonth(), today.getDate() + index);
        const enabled = date.getDay() !== 0 && index % 4 !== 1;
        return { date, index, enabled };
    });
}

function slotsFor(day: DemoDay): string[] {
    return day.index % 3 === 2 ? SHORT_SLOTS : FULL_SLOTS;
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
    const days = useMemo(buildDays, []);
    const [step, setStep] = useState(1);
    const [finished, setFinished] = useState(false);
    const [serviceId, setServiceId] = useState<string | null>(null);
    const [day, setDay] = useState<DemoDay | null>(null);
    const [time, setTime] = useState<string | null>(null);

    const service = SERVICES.find((item) => item.id === serviceId) ?? null;
    const canContinue =
        (step === 1 && service !== null) ||
        (step === 2 && day !== null) ||
        (step === 3 && time !== null) ||
        step === 4;

    const selectDay = (next: DemoDay) => {
        setDay(next);
        setTime(null);
    };

    const handleAction = () => {
        if (step < TOTAL_STEPS) setStep(step + 1);
        else setFinished(true);
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
            </div>
        );
    } else if (step === 1) {
        body = (
            <>
                <h3 className="demo-title">Выберите услугу</h3>
                <p className="demo-sub">Выберите один вариант, чтобы перейти к свободному времени.</p>
                <div className="demo-list" role="radiogroup" aria-label="Услуги">
                    {SERVICES.map((item) => {
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
                <div className="demo-days">
                    {days.map((item) => {
                        const selected = day?.index === item.index;
                        return (
                            <button
                                key={item.date.getTime()}
                                type="button"
                                disabled={!item.enabled}
                                aria-pressed={selected}
                                aria-label={formatDateLong(item.date)}
                                className={`demo-day${selected ? ' is-selected' : ''}`}
                                onClick={() => selectDay(item)}
                            >
                                <span className="demo-day-week">{WEEK_SHORT[item.date.getDay()]}</span>
                                <span className="demo-day-num">{item.date.getDate()}</span>
                            </button>
                        );
                    })}
                </div>
            </>
        );
    } else if (step === 3) {
        body = (
            <>
                <h3 className="demo-title">Выберите время</h3>
                <p className="demo-sub">{day ? formatDateLong(day.date) : ''}</p>
                <div className="demo-times">
                    {day &&
                        slotsFor(day).map((slot) => (
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
    } else if (service && day && time) {
        body = (
            <>
                <h3 className="demo-title">Подтверждение</h3>
                <p className="demo-sub">Проверьте данные записи.</p>
                <div className="demo-summary">
                    <div className="demo-summary-row">
                        <span className="demo-summary-label">Услуга</span>
                        <span className="demo-summary-value">{service.title}</span>
                    </div>
                    <div className="demo-summary-row">
                        <span className="demo-summary-label">Дата</span>
                        <span className="demo-summary-value">{formatDateLong(day.date)}</span>
                    </div>
                    <div className="demo-summary-row">
                        <span className="demo-summary-label">Время</span>
                        <span className="demo-summary-value">{time}</span>
                    </div>
                    <div className="demo-summary-row">
                        <span className="demo-summary-label">Стоимость</span>
                        <span className="demo-summary-value">{formatPrice(service.price)}</span>
                    </div>
                    <div className="demo-summary-row">
                        <span className="demo-summary-label">Длительность</span>
                        <span className="demo-summary-value">{formatDuration(service.minutes)}</span>
                    </div>
                </div>
            </>
        );
    }

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
                        <p className="demo-master-role">Мастер маникюра</p>
                    </div>
                </div>
            </div>

            <div className="demo-body">{body}</div>

            <div className="demo-dock">
                {finished ? (
                    <a className="demo-cta" href="/auth/login">
                        Начать бесплатно
                    </a>
                ) : (
                    <button type="button" className="demo-cta" onClick={handleAction} disabled={!canContinue}>
                        {step === TOTAL_STEPS ? 'Записаться' : 'Далее'}
                    </button>
                )}
            </div>
        </div>
    );
}
