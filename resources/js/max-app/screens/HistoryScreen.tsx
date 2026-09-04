import { Button, Spinner, Typography } from '@maxhub/max-ui';
import { getHistory, type Appointment } from '../lib/api';
import { openLink } from '../lib/maxBridge';
import { useAsync } from '../lib/useAsync';

const RUSSIAN_MONTHS = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

function formatPrice(price: number): string {
    return `${price.toLocaleString('ru-RU')} ₽`;
}

function pluralizeRecord(n: number): string {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod100 >= 11 && mod100 <= 14) return `${n} записей`;
    if (mod10 === 1) return `${n} запись`;
    if (mod10 >= 2 && mod10 <= 4) return `${n} записи`;
    return `${n} записей`;
}

function formatDateTime(raw: string): string {
    const d = new Date(raw.replace(' ', 'T'));
    if (isNaN(d.getTime())) return raw;
    const day = d.getDate();
    const month = RUSSIAN_MONTHS[d.getMonth()];
    const year = d.getFullYear();
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${day} ${month} ${year} · ${hh}:${mm}`;
}

function statusLabel(status: string): string {
    switch (status) {
        case 'booked':
            return 'Подтверждено';
        case 'pending_payment':
            return 'Ожидает оплаты';
        case 'prepaid':
            return 'Предоплата';
        case 'paid':
            return 'Оплачено';
        case 'no_show':
            return 'Неявка';
        case 'cancelled':
            return 'Отменено';
        default:
            return status;
    }
}

function statusVariant(status: string): 'green' | 'blue' | 'red' | 'neutral' {
    switch (status) {
        case 'booked':
            return 'green';
        case 'paid':
        case 'prepaid':
            return 'blue';
        case 'no_show':
            return 'red';
        case 'cancelled':
            return 'neutral';
        default:
            return 'neutral';
    }
}

export function HistoryScreen() {
    const { data, loading, error, reload } = useAsync<Appointment[]>(getHistory);

    if (loading) {
        return (
            <div className="screen-center">
                <Spinner size={24} />
            </div>
        );
    }

    if (error) {
        return (
            <div className="screen-center">
                <Typography.Body>{error}</Typography.Body>
                <Button size="small" variant="secondary" onClick={reload} style={{ marginTop: 12 }}>
                    Повторить
                </Button>
            </div>
        );
    }

    if (!data || data.length === 0) {
        return (
            <div className="screen-center">
                <svg className="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M12 8v5l3 2" />
                    <circle cx="12" cy="12" r="9" />
                </svg>
                <div className="empty-state-title">История пока пуста</div>
                <div className="empty-state-sub">Здесь появятся ваши прошедшие записи</div>
            </div>
        );
    }

    return (
        <div className="screen-content">
            <p className="screen-subtitle">Прошедшие записи</p>

            <div className="section-header">
                <span className="section-header-label">Последние</span>
                <span className="section-header-count">{pluralizeRecord(data.length)}</span>
            </div>

            {data.map((a) => (
                <article key={a.id} className="history-card">
                    <div className="history-card-top">
                        <div>
                            <div className="history-card-service">{a.service}</div>
                            {a.master?.name && <div className="history-card-master">{a.master.name}</div>}
                        </div>
                        <span className={`status-badge status-badge--${statusVariant(a.status)}`}>
                            {statusLabel(a.status)}
                        </span>
                    </div>

                    <div className="history-card-meta">{formatDateTime(a.start_at)}</div>

                    <div className="history-card-footer">
                        <span className="history-card-price">{formatPrice(a.price)}</span>
                        {a.master?.master_slug && (
                            <button
                                type="button"
                                className="history-rebook-btn"
                                onClick={() => openLink(`${window.location.origin}/book/${a.master!.master_slug}`)}
                            >
                                Записаться снова
                            </button>
                        )}
                    </div>
                </article>
            ))}
        </div>
    );
}
