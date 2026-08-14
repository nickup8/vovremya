import { Button, Typography } from '@maxhub/max-ui';

interface CancelOverlayProps {
    service: string;
    onConfirm: () => void;
    onCancel: () => void;
    loading: boolean;
    error: string | null;
}

/** Кастомная модалка подтверждения отмены записи (в MAX UI нет Modal) */
export function CancelOverlay({ service, onConfirm, onCancel, loading, error }: CancelOverlayProps) {
    return (
        <div className="overlay-backdrop" onClick={onCancel}>
            <div className="overlay-card" onClick={(e) => e.stopPropagation()}>
                <Typography.Title>Отменить запись?</Typography.Title>
                <Typography.Body className="overlay-service">{service}</Typography.Body>

                {error && (
                    <Typography.Body className="overlay-error">{error}</Typography.Body>
                )}

                <div className="overlay-actions">
                    <Button
                        size="medium"
                        variant="destructive"
                        stretched
                        onClick={onConfirm}
                        loading={loading}
                        disabled={loading}
                    >
                        Отменить запись
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

interface CancelSuccessProps {
    onClose: () => void;
}

/** Экран успешной отмены */
export function CancelSuccess({ onClose }: CancelSuccessProps) {
    return (
        <div className="screen-center">
            <Typography.Title>Запись отменена</Typography.Title>
            <Typography.Body className="overlay-success-text">
                Вы больше не записаны на этот приём
            </Typography.Body>
            <Button
                size="medium"
                variant="secondary"
                stretched
                onClick={onClose}
                style={{ marginTop: 16 }}
            >
                К записям
            </Button>
        </div>
    );
}
