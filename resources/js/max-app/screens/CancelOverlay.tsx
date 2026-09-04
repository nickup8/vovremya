import { openLink } from '../lib/maxBridge';

interface CancelSuccessProps {
    masterSlug?: string | null;
    onClose: () => void;
}

export function CancelSuccess({ masterSlug, onClose }: CancelSuccessProps) {
    const handleBookAgain = () => {
        if (masterSlug) {
            openLink(`${window.location.origin}/book/${masterSlug}`);
        }
    };

    return (
        <div className="screen-center">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--green)" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" style={{ marginBottom: 10 }}>
                <circle cx="12" cy="12" r="9" />
                <path d="m9 12 2 2 4-4" />
            </svg>
            <div className="success-title">Запись отменена</div>
            <div className="success-sub">Вы больше не записаны на этот приём</div>

            {masterSlug && (
                <button type="button" className="success-btn-primary" onClick={handleBookAgain}>
                    Записаться снова
                </button>
            )}
            <button type="button" className="success-btn-secondary" onClick={onClose}>
                К записям
            </button>
        </div>
    );
}
