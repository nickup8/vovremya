import { Button, Typography } from '@maxhub/max-ui';
import { useCallback, useState } from 'react';
import type { EarlierRequest } from '../lib/api';

interface EarlierRequestOverlayProps {
    appointmentDate: string;
    existingRequest: EarlierRequest | null;
    onSave: (data: { date_from: string; date_to: string; time_from: string; time_to: string }) => void;
    onCancel: () => void;
    loading: boolean;
    error: string | null;
}

export function EarlierRequestOverlay({
    appointmentDate,
    existingRequest,
    onSave,
    onCancel,
    loading,
    error,
}: EarlierRequestOverlayProps) {
    const today = new Date().toISOString().split('T')[0];

    const isFullDay = existingRequest
        ? existingRequest.time_from === '00:00' && existingRequest.time_to === '23:59'
        : false;

    const [dateFrom, setDateFrom] = useState(existingRequest?.date_from ?? today);
    const [dateTo, setDateTo] = useState(existingRequest?.date_to ?? appointmentDate);
    const [timeFrom, setTimeFrom] = useState(isFullDay ? '' : (existingRequest?.time_from ?? ''));
    const [timeTo, setTimeTo] = useState(isFullDay ? '' : (existingRequest?.time_to ?? ''));
    const [anyTime, setAnyTime] = useState(isFullDay);

    const [validationError, setValidationError] = useState<string | null>(null);

    const handleSubmit = useCallback(() => {
        setValidationError(null);

        if (!dateFrom || !dateTo) {
            setValidationError('Укажите даты');
            return;
        }

        if (dateFrom > dateTo) {
            setValidationError('Дата «С» не может быть позже даты «До»');
            return;
        }

        let effectiveTimeFrom: string;
        let effectiveTimeTo: string;

        if (anyTime) {
            effectiveTimeFrom = '00:00:00';
            effectiveTimeTo = '23:59:59';
        } else {
            if (!timeFrom || !timeTo) {
                setValidationError('Укажите время или включите «В любое время»');
                return;
            }

            if (timeFrom >= timeTo) {
                setValidationError('Время «С» должно быть раньше времени «До»');
                return;
            }

            effectiveTimeFrom = timeFrom + ':00';
            effectiveTimeTo = timeTo + ':00';
        }

        onSave({
            date_from: dateFrom,
            date_to: dateTo,
            time_from: effectiveTimeFrom,
            time_to: effectiveTimeTo,
        });
    }, [dateFrom, dateTo, timeFrom, timeTo, anyTime, onSave]);

    const displayError = validationError ?? error;

    return (
        <div className="overlay-backdrop" onClick={loading ? undefined : onCancel}>
            <div className="overlay-card overlay-card--wide" onClick={(e) => e.stopPropagation()}>
                <Typography.Title>Хочу раньше</Typography.Title>
                <Typography.Body className="overlay-service">Когда вам удобно?</Typography.Body>

                {displayError && (
                    <Typography.Body className="overlay-error">{displayError}</Typography.Body>
                )}

                <div className="earlier-form">
                    <div className="earlier-row">
                        <label className="earlier-label">
                            С
                            <input
                                type="date"
                                className="earlier-input"
                                value={dateFrom}
                                min={today}
                                max={appointmentDate}
                                onChange={(e) => setDateFrom(e.target.value)}
                                disabled={loading}
                            />
                        </label>
                        <label className="earlier-label">
                            До
                            <input
                                type="date"
                                className="earlier-input"
                                value={dateTo}
                                min={today}
                                max={appointmentDate}
                                onChange={(e) => setDateTo(e.target.value)}
                                disabled={loading}
                            />
                        </label>
                    </div>

                    <label className="earlier-anytime">
                        <input
                            type="checkbox"
                            checked={anyTime}
                            onChange={(e) => setAnyTime(e.target.checked)}
                            disabled={loading}
                        />
                        <span>В любое время</span>
                    </label>

                    {!anyTime && (
                        <div className="earlier-row">
                            <label className="earlier-label">
                                С
                                <input
                                    type="time"
                                    className="earlier-input"
                                    value={timeFrom}
                                    onChange={(e) => setTimeFrom(e.target.value)}
                                    disabled={loading}
                                />
                            </label>
                            <label className="earlier-label">
                                До
                                <input
                                    type="time"
                                    className="earlier-input"
                                    value={timeTo}
                                    onChange={(e) => setTimeTo(e.target.value)}
                                    disabled={loading}
                                />
                            </label>
                        </div>
                    )}
                </div>

                <div className="overlay-actions">
                    <Button
                        size="medium"
                        variant="primary"
                        stretched
                        onClick={handleSubmit}
                        loading={loading}
                        disabled={loading}
                    >
                        {existingRequest ? 'Сохранить' : 'Сообщить, если появится'}
                    </Button>
                    <Button
                        size="medium"
                        variant="secondary"
                        stretched
                        onClick={onCancel}
                        disabled={loading}
                    >
                        Назад
                    </Button>
                </div>
            </div>
        </div>
    );
}
