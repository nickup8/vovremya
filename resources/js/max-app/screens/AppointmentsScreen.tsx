import { Button, CellHeader, CellList, CellSimple, Spinner, Typography } from '@maxhub/max-ui';
import { useCallback, useEffect, useState } from 'react';
import { cancelAppointment, cancelEarlierRequest, getAppointments, saveEarlierRequest, type Appointment } from '../lib/api';
import { backButton, haptic } from '../lib/maxBridge';
import { useAsync } from '../lib/useAsync';
import { CancelOverlay, CancelSuccess } from './CancelOverlay';
import { EarlierRequestOverlay } from './EarlierRequestOverlay';

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
type EarlierPhase = 'idle' | 'form' | 'loading' | 'error';

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

    // ── Earlier request state ─────────────────────────────
    const [earlierPhase, setEarlierPhase] = useState<EarlierPhase>('idle');
    const [earlierTarget, setEarlierTarget] = useState<Appointment | null>(null);
    const [earlierError, setEarlierError] = useState<string | null>(null);

    const handleEarlierClick = useCallback((appointment: Appointment) => {
        haptic.impact('medium');
        setEarlierTarget(appointment);
        setEarlierError(null);
        setEarlierPhase('form');
    }, []);

    const handleEarlierSave = useCallback(async (data: { date_from: string; date_to: string; time_from: string; time_to: string }) => {
        if (!earlierTarget) return;

        setEarlierPhase('loading');
        setEarlierError(null);

        try {
            const result = await saveEarlierRequest(earlierTarget.id, data);

            if ('error' in result) {
                haptic.notify('error');
                setEarlierError(result.error);
                setEarlierPhase('error');
                return;
            }

            haptic.notify('success');
            setEarlierPhase('idle');
            setEarlierTarget(null);
            reload();
        } catch {
            haptic.notify('error');
            setEarlierError('Ошибка сети, попробуйте позже');
            setEarlierPhase('error');
        }
    }, [earlierTarget, reload]);

    const handleEarlierDismiss = useCallback(() => {
        setEarlierPhase('idle');
        setEarlierTarget(null);
        setEarlierError(null);
    }, []);

    const handleEarlierCancel = useCallback(async (appointmentId: string) => {
        try {
            await cancelEarlierRequest(appointmentId);
            haptic.notify('success');
            reload();
        } catch {
            haptic.notify('error');
        }
    }, [reload]);

    // BackButton: показываем при открытом оверлее (confirm/error), скрываем при loading/idle/done
    useEffect(() => {
        const cancelDismissable = cancelPhase === 'confirm' || cancelPhase === 'error';
        const earlierDismissable = earlierPhase === 'form' || earlierPhase === 'error';

        if (cancelDismissable) {
            backButton.show();
            backButton.onClick(handleCancelDismiss);
            return () => {
                backButton.offClick(handleCancelDismiss);
                backButton.hide();
            };
        }

        if (earlierDismissable) {
            backButton.show();
            backButton.onClick(handleEarlierDismiss);
            return () => {
                backButton.offClick(handleEarlierDismiss);
                backButton.hide();
            };
        }

        backButton.hide();
    }, [cancelPhase, handleCancelDismiss, earlierPhase, handleEarlierDismiss]);

    // Экран «Запись отменена»
    if (cancelPhase === 'done') {
        return <CancelSuccess masterSlug={targetAppointment?.master?.master_slug} onClose={handleBackToList} />;
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

                        {/* Active earlier request */}
                        {a.earlier_request && (
                            <div className="earlier-status-block">
                                <Typography.Body className="earlier-status-label">
                                    {a.autofill_available ? 'Ищем время раньше' : 'Поиск приостановлен'}
                                </Typography.Body>
                                <Typography.Body className="earlier-status-detail">
                                    {a.earlier_request.date_from === a.earlier_request.date_to
                                        ? a.earlier_request.date_from
                                        : `${a.earlier_request.date_from} — ${a.earlier_request.date_to}`}
                                    {a.earlier_request.time_from === '00:00' && a.earlier_request.time_to === '23:59'
                                        ? ' · в любое время'
                                        : ` · ${a.earlier_request.time_from}–${a.earlier_request.time_to}`}
                                </Typography.Body>
                                <div className="earlier-status-actions">
                                    {a.autofill_available && (
                                        <Button
                                            size="xsmall"
                                            variant="secondary"
                                            onClick={() => handleEarlierClick(a)}
                                        >
                                            Изменить
                                        </Button>
                                    )}
                                    <Button
                                        size="xsmall"
                                        variant="secondary"
                                        onClick={() => handleEarlierCancel(a.id)}
                                    >
                                        Больше не искать
                                    </Button>
                                </div>
                            </div>
                        )}

                        {/* Action buttons */}
                        <div className="appointment-card-actions">
                            {a.autofill_available && !a.earlier_request && (
                                <Button
                                    size="xsmall"
                                    variant="secondary"
                                    onClick={() => handleEarlierClick(a)}
                                >
                                    Хочу раньше
                                </Button>
                            )}
                            {a.can_cancel && (
                                <Button
                                    size="xsmall"
                                    variant="destructive"
                                    onClick={() => handleCancelClick(a)}
                                >
                                    Отменить
                                </Button>
                            )}
                        </div>
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

            {/* Earlier request overlay */}
            {(earlierPhase === 'form' || earlierPhase === 'loading' || earlierPhase === 'error') && earlierTarget && (
                <EarlierRequestOverlay
                    appointmentDate={earlierTarget.start_at.split(' ')[0]}
                    existingRequest={earlierTarget.earlier_request}
                    onSave={handleEarlierSave}
                    onCancel={handleEarlierDismiss}
                    loading={earlierPhase === 'loading'}
                    error={earlierPhase === 'error' ? earlierError : null}
                />
            )}
        </div>
    );
}
