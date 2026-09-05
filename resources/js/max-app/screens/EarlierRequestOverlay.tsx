import { useCallback, useState } from 'react';
import { MaxDatePicker } from '../components/MaxDatePicker';
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
        <>
            <div className="sheet-backdrop" onClick={loading ? undefined : onCancel} />
            <section className="sheet" role="dialog" aria-label="Хочу раньше">
                <div className="sheet-handle" />
                <div className="sheet-head">
                    <div>
                        <div className="sheet-title">Хочу раньше</div>
                        <div className="sheet-sub">Когда вам удобно?</div>
                    </div>
                    <button type="button" className="sheet-close" onClick={onCancel} disabled={loading} aria-label="Закрыть">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="sheet-content">
                    {displayError && (
                        <div className="earlier-error">{displayError}</div>
                    )}

                    <div className="earlier-form">
                        <div className="earlier-row">
                            <label className="earlier-label">
                                С
                                <MaxDatePicker
                                    value={dateFrom}
                                    onChange={setDateFrom}
                                    min={today}
                                    max={appointmentDate}
                                    disabled={loading}
                                />
                            </label>
                            <label className="earlier-label">
                                До
                                <MaxDatePicker
                                    value={dateTo}
                                    onChange={setDateTo}
                                    min={today}
                                    max={appointmentDate}
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

                    <div className="sheet-actions">
                        <button
                            type="button"
                            className="sheet-btn-primary"
                            onClick={handleSubmit}
                            disabled={loading}
                        >
                            {loading ? 'Сохранение…' : existingRequest ? 'Сохранить' : 'Сообщить, если появится'}
                        </button>
                        <button
                            type="button"
                            className="sheet-btn-secondary"
                            onClick={onCancel}
                            disabled={loading}
                        >
                            Назад
                        </button>
                    </div>
                </div>
            </section>
        </>
    );
}
