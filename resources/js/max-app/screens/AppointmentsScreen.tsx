import { useCallback, useEffect, useState } from 'react';
import { cancelAppointment, cancelEarlierRequest, getAppointments, saveEarlierRequest, type Appointment } from '../lib/api';
import { backButton, haptic } from '../lib/maxBridge';
import { useAsync } from '../lib/useAsync';
import { CancelSuccess } from './CancelOverlay';
import { EarlierRequestOverlay } from './EarlierRequestOverlay';

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

function formatDate(raw: string): string {
    const d = new Date(raw.replace(' ', 'T'));
    if (isNaN(d.getTime())) return raw;
    return `${d.getDate()} ${RUSSIAN_MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

function formatTime(raw: string): string {
    const d = new Date(raw.replace(' ', 'T'));
    if (isNaN(d.getTime())) return raw;
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
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
        default:
            return status;
    }
}

function statusVariant(status: string): 'green' | 'blue' | 'red' | 'neutral' {
    switch (status) {
        case 'booked':
            return 'blue';
        case 'paid':
        case 'prepaid':
            return 'green';
        case 'no_show':
            return 'red';
        default:
            return 'neutral';
    }
}

type CancelPhase = 'idle' | 'confirm' | 'loading' | 'done' | 'error';
type EarlierPhase = 'idle' | 'form' | 'loading' | 'error';

function formatDateShort(raw: string): string {
    const d = new Date(raw.replace(' ', 'T'));
    if (isNaN(d.getTime())) return raw;
    return `${d.getDate()} ${RUSSIAN_MONTHS[d.getMonth()]} в ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function AppointmentsScreen() {
    const { data, loading, error, reload } = useAsync<Appointment[]>(getAppointments);

    const [cancelPhase, setCancelPhase] = useState<CancelPhase>('idle');
    const [targetAppointment, setTargetAppointment] = useState<Appointment | null>(null);
    const [cancelError, setCancelError] = useState<string | null>(null);

    const handleCancelConfirm = useCallback(async () => {
        if (!targetAppointment) return;

        setCancelPhase('loading');
        setCancelError(null);

        try {
            const result = await cancelAppointment(targetAppointment.id);

            if ('error' in result) {
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

    const [earlierPhase, setEarlierPhase] = useState<EarlierPhase>('idle');
    const [earlierTarget, setEarlierTarget] = useState<Appointment | null>(null);
    const [earlierError, setEarlierError] = useState<string | null>(null);

    const [selectedAppointment, setSelectedAppointment] = useState<Appointment | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);

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

    const handleDetailsOpen = useCallback((appointment: Appointment) => {
        setSelectedAppointment(appointment);
        setDetailsOpen(true);
    }, []);

    const handleDetailsClose = useCallback(() => {
        setDetailsOpen(false);
        setSelectedAppointment(null);
    }, []);

    const handleCancelFromSheet = useCallback(() => {
        if (!selectedAppointment) return;
        haptic.impact('medium');
        setTargetAppointment(selectedAppointment);
        setCancelError(null);
        setDetailsOpen(false);
        setCancelPhase('confirm');
    }, [selectedAppointment]);

    useEffect(() => {
        const cancelDismissable = cancelPhase === 'confirm' || cancelPhase === 'error';
        const earlierDismissable = earlierPhase === 'form' || earlierPhase === 'error';

        if (detailsOpen) {
            backButton.show();
            backButton.onClick(handleDetailsClose);
            return () => {
                backButton.offClick(handleDetailsClose);
                backButton.hide();
            };
        }

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
    }, [detailsOpen, handleDetailsClose, cancelPhase, handleCancelDismiss, earlierPhase, handleEarlierDismiss]);

    if (cancelPhase === 'done') {
        return <CancelSuccess masterSlug={targetAppointment?.master?.master_slug} onClose={handleBackToList} />;
    }

    if (loading) {
        return (
            <div className="screen-center">
                <div className="loader" />
            </div>
        );
    }

    if (error) {
        return (
            <div className="screen-center">
                <p className="error-text">{error}</p>
                <button type="button" className="retry-btn" onClick={reload}>Повторить</button>
            </div>
        );
    }

    if (!data || data.length === 0) {
        return (
            <div className="screen-center">
                <svg className="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M8 2v4M16 2v4M3 10h18" />
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                </svg>
                <div className="empty-state-title">Пока нет записей</div>
                <div className="empty-state-sub">Здесь появятся ваши предстоящие визиты</div>
            </div>
        );
    }

    return (
        <div className="screen-content">
            <p className="screen-subtitle">Предстоящие визиты</p>

            <div className="section-header">
                <span className="section-header-label">Предстоящие</span>
                <span className="section-header-count">{pluralizeRecord(data.length)}</span>
            </div>

            {data.map((a) => (
                <article key={a.id} className="appt-card">
                    <div className="appt-card-top">
                        <div>
                            <div className="appt-card-service">{a.service}</div>
                            {a.master?.name && <div className="appt-card-master">{a.master.name}</div>}
                        </div>
                        <span className={`status-badge status-badge--${statusVariant(a.status)}`}>
                            {statusLabel(a.status)}
                        </span>
                    </div>

                    <div className="appt-card-info">
                        <div className="appt-info-row">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M8 2v4M16 2v4M3 10h18" />
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                            </svg>
                            <span>{formatDate(a.start_at)}</span>
                        </div>
                        <div className="appt-info-row">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v5l3 2" />
                            </svg>
                            <span>{formatTime(a.start_at)}</span>
                        </div>
                        {a.master?.address && (
                            <div className="appt-info-row">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" />
                                    <circle cx="12" cy="10" r="2.5" />
                                </svg>
                                <span>{a.master.address}</span>
                            </div>
                        )}
                    </div>

                    <div className="appt-card-footer">
                        <span className="appt-card-price">{formatPrice(a.price)}</span>
                        <button type="button" className="appt-detail-link" onClick={() => handleDetailsOpen(a)}>Подробнее</button>
                    </div>

                    {a.earlier_request && (
                        <div className="earlier-status-block">
                            <div className="earlier-status-label">
                                {a.autofill_available ? 'Ищем время раньше' : 'Поиск приостановлен'}
                            </div>
                            <div className="earlier-status-detail">
                                {a.earlier_request.date_from === a.earlier_request.date_to
                                    ? a.earlier_request.date_from
                                    : `${a.earlier_request.date_from} — ${a.earlier_request.date_to}`}
                                {a.earlier_request.time_from === '00:00' && a.earlier_request.time_to === '23:59'
                                    ? ' · в любое время'
                                    : ` · ${a.earlier_request.time_from}–${a.earlier_request.time_to}`}
                            </div>
                            <div className="earlier-status-actions">
                                {a.autofill_available && (
                                    <button
                                        type="button"
                                        className="btn-sm btn-secondary"
                                        onClick={() => handleEarlierClick(a)}
                                    >
                                        Изменить
                                    </button>
                                )}
                                <button
                                    type="button"
                                    className="btn-sm btn-secondary"
                                    onClick={() => handleEarlierCancel(a.id)}
                                >
                                    Больше не искать
                                </button>
                            </div>
                        </div>
                    )}

                    <div className="appt-card-actions">
                        {a.autofill_available && !a.earlier_request && (
                            <button
                                type="button"
                                className="appt-booking-btn"
                                onClick={() => handleEarlierClick(a)}
                            >
                                Хочу раньше
                            </button>
                        )}
                    </div>
                </article>
            ))}

            {detailsOpen && selectedAppointment && (
                <>
                    <div className="sheet-backdrop" onClick={handleDetailsClose} />
                    <section className="sheet" role="dialog" aria-label="Детали записи">
                        <div className="sheet-handle" />
                        <div className="sheet-head">
                            <div>
                                <div className="sheet-title">Детали записи</div>
                                <div className="sheet-sub">Предстоящий визит</div>
                            </div>
                            <button type="button" className="sheet-close" onClick={handleDetailsClose} aria-label="Закрыть">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M18 6 6 18M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div className="sheet-content">
                            <div className="detail-list">
                                <div className="detail-row"><span>Услуга</span><strong>{selectedAppointment.service}</strong></div>
                                {selectedAppointment.master?.name && (
                                    <div className="detail-row"><span>Мастер</span><strong>{selectedAppointment.master.name}</strong></div>
                                )}
                                <div className="detail-row"><span>Дата</span><strong>{formatDate(selectedAppointment.start_at)}</strong></div>
                                <div className="detail-row"><span>Время</span><strong>{formatTime(selectedAppointment.start_at)}</strong></div>
                                {selectedAppointment.master?.address && (
                                    <div className="detail-row"><span>Адрес</span><strong>{selectedAppointment.master.address}</strong></div>
                                )}
                                <div className="detail-row"><span>Стоимость</span><strong>{formatPrice(selectedAppointment.price)}</strong></div>
                            </div>
                            {selectedAppointment.can_cancel && (
                                <div className="danger-zone">
                                    <button type="button" className="danger-ghost" onClick={handleCancelFromSheet}>
                                        Отменить запись
                                    </button>
                                </div>
                            )}
                        </div>
                    </section>
                </>
            )}

            {cancelPhase === 'confirm' && targetAppointment && (
                <div className="modal-backdrop" onClick={handleCancelDismiss}>
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <div className="modal-title">Отменить запись?</div>
                        <div className="modal-copy">
                            Запись на {formatDateShort(targetAppointment.start_at)} будет отменена. Это действие нельзя отменить.
                        </div>
                        <div className="modal-actions">
                            <button type="button" className="modal-btn" onClick={handleCancelDismiss} disabled={false}>
                                Не отменять
                            </button>
                            <button type="button" className="modal-btn modal-btn--danger" onClick={handleCancelConfirm}>
                                Отменить запись
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {cancelPhase === 'loading' && targetAppointment && (
                <div className="modal-backdrop">
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <div className="modal-title">Отменить запись?</div>
                        <div className="modal-copy">
                            Запись на {formatDateShort(targetAppointment.start_at)} будет отменена. Это действие нельзя отменить.
                        </div>
                        <div className="modal-actions">
                            <button type="button" className="modal-btn" disabled>
                                Не отменять
                            </button>
                            <button type="button" className="modal-btn modal-btn--danger" disabled style={{ opacity: 0.6 }}>
                                Отмена...
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {cancelPhase === 'error' && targetAppointment && (
                <div className="modal-backdrop" onClick={handleCancelDismiss}>
                    <div className="modal" onClick={(e) => e.stopPropagation()}>
                        <div className="modal-title">Отменить запись?</div>
                        <div className="modal-copy">
                            {cancelError && <span style={{ color: 'var(--red)', display: 'block', marginBottom: 8 }}>{cancelError}</span>}
                            Запись на {formatDateShort(targetAppointment.start_at)} будет отменена. Это действие нельзя отменить.
                        </div>
                        <div className="modal-actions">
                            <button type="button" className="modal-btn" onClick={handleCancelDismiss}>
                                Не отменять
                            </button>
                            <button type="button" className="modal-btn modal-btn--danger" onClick={handleCancelConfirm}>
                                Повторить
                            </button>
                        </div>
                    </div>
                </div>
            )}

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
