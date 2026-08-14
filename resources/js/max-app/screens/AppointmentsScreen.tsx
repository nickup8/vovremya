import { Button, CellHeader, CellList, CellSimple, Spinner, Typography } from '@maxhub/max-ui';
import { getAppointments, type Appointment } from '../lib/api';
import { useAsync } from '../lib/useAsync';

function formatPrice(price: number): string {
    return `${price.toLocaleString('ru-RU')} ₽`;
}

/** Статус записи — короткая метка */
function statusLabel(status: string): string {
    switch (status) {
        case 'booked':
            return 'Подтверждено';
        case 'pending_payment':
            return 'Ожидает оплаты';
        case 'prepaid':
            return 'Предоплата';
        default:
            return status;
    }
}

export function AppointmentsScreen() {
    const { data, loading, error, reload } = useAsync<Appointment[]>(getAppointments);

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
                <Typography.Body>У вас пока нет записей</Typography.Body>
            </div>
        );
    }

    return (
        <div className="screen-content">
            <CellList mode="island" header={<CellHeader>Предстоящие</CellHeader>}>
                {data.map((a) => (
                    <CellSimple
                        key={a.id}
                        title={a.service}
                        subtitle={a.start_at_human}
                        overline={a.master?.name ?? undefined}
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
