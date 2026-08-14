import { Button, CellHeader, CellList, CellSimple, Spinner, Typography } from '@maxhub/max-ui';
import { useCallback, useState } from 'react';
import { cancelAppointment, getAppointments, type Appointment } from '../lib/api';
import { haptic } from '../lib/maxBridge';
import { useAsync } from '../lib/useAsync';
import { CancelOverlay, CancelSuccess } from './CancelOverlay';

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

type CancelPhase = 'idle' | 'confirm' | 'loading' | 'done' | 'error';

export function AppointmentsScreen() {
    const { data, loading, error, reload } = useAsync<Appointment[]>(getAppointments);

    // Состояние отмены
    const [cancelPhase, setCancelPhase] = useState<CancelPhase>('idle');
    const [targetAppointment, setTargetAppointment] = useState<Appointment | null>(null);
    const [cancelError, setCancelError] = useState<string | null>(null);

    const handleCancelClick = useCallback((appointment: Appointment) => {
        haptic.impact('medium');
        setTargetAppointment(appointment);
        setCancelError(null);
        setCancelPhase('confirm');
    }, []);

    const handleCancelConfirm = useCallback(async () => {
        if (!targetAppointment) return;

        setCancelPhase('loading');
        setCancelError(null);

        try {
            const result = await cancelAppointment(targetAppointment.id);

            if ('error' in result) {
                // 422 от API: deadline_passed / not_cancellable
                const msg = result.error === 'deadline_passed'
                    ? `Срок отмены истёк (за ${result.deadline_hours} ч.)`
                    : result.error === 'not_cancellable'
                        ? 'Эту запись уже нельзя отменить'
                        : 'Не удалось отменить запись';
                haptic.notify('error');
                setCancelError(msg);
                setCancelPhase('error');
                return;
            }

            haptic.notify('success');
            setCancelPhase('done');
        } catch {
            haptic.notify('error');
            setCancelError('Ошибка сети, попробуйте позже');
            setCancelPhase('error');
        }
    }, [targetAppointment]);

    const handleCancelDismiss = useCallback(() => {
        setCancelPhase('idle');
        setTargetAppointment(null);
        setCancelError(null);
    }, []);

    const handleBackToList = useCallback(() => {
        setCancelPhase('idle');
        setTargetAppointment(null);
        setCancelError(null);
        reload();
    }, [reload]);

    // Экран «Запись отменена»
    if (cancelPhase === 'done') {
        return <CancelSuccess onClose={handleBackToList} />;
    }

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
                    <div key={a.id} className="appointment-card">
                        <CellSimple
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
                        {a.can_cancel && (
                            <div className="appointment-card-actions">
                                <Button
                                    size="xsmall"
                                    variant="destructive"
                                    onClick={() => handleCancelClick(a)}
                                >
                                    Отменить
                                </Button>
                            </div>
                        )}
                    </div>
                ))}
            </CellList>

            {/* Оверлей подтверждения отмены */}
            {cancelPhase === 'confirm' && targetAppointment && (
                <CancelOverlay
                    service={targetAppointment.service}
                    onConfirm={handleCancelConfirm}
                    onCancel={handleCancelDismiss}
                    loading={false}
                    error={cancelError}
                />
            )}
            {cancelPhase === 'loading' && targetAppointment && (
                <CancelOverlay
                    service={targetAppointment.service}
                    onConfirm={handleCancelConfirm}
                    onCancel={handleCancelDismiss}
                    loading={true}
                    error={null}
                />
            )}
            {cancelPhase === 'error' && targetAppointment && (
                <CancelOverlay
                    service={targetAppointment.service}
                    onConfirm={handleCancelConfirm}
                    onCancel={handleCancelDismiss}
                    loading={false}
                    error={cancelError}
                />
            )}
        </div>
    );
}
