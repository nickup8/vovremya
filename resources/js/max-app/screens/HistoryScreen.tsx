import { Button, CellHeader, CellList, CellSimple, Spinner, Typography } from '@maxhub/max-ui';
import { getHistory, type Appointment } from '../lib/api';
import { useAsync } from '../lib/useAsync';

function formatPrice(price: number): string {
    return `${price.toLocaleString('ru-RU')} ₽`;
}

/** Статус записи — человекочитаемая метка для истории */
function statusLabel(status: string): string {
    switch (status) {
        case 'paid':
            return 'Оплачено';
        case 'cancelled':
            return 'Отменено';
        case 'no_show':
            return 'Неявка';
        case 'booked':
            return 'Подтверждено';
        default:
            return status;
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
                <Typography.Body>История пуста</Typography.Body>
            </div>
        );
    }

    return (
        <div className="screen-content">
            <CellList mode="island" header={<CellHeader>Завершённые</CellHeader>}>
                {data.map((a) => (
                    <CellSimple
                        key={a.id}
                        title={a.service}
                        subtitle={a.start_at_human}
                        after={
                            <Typography.Body>
                                {formatPrice(a.price)} · {statusLabel(a.status)}
                            </Typography.Body>
                        }
                        showChevron={false}
                    />
                ))}
            </CellList>
        </div>
    );
}
