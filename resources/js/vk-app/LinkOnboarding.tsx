import { useCallback, useState } from 'react';
import { getVkLinkToken, requestVkPhoneNumber } from './lib/vkBridge';
import { linkVkClient } from './lib/api';

type Phase = 'idle' | 'loading' | 'error';

export function LinkOnboarding({ onLinked }: { onLinked: () => void }) {
    const [phase, setPhase] = useState<Phase>('idle');
    const [error, setError] = useState<string | null>(null);

    const handleClick = useCallback(async () => {
        const token = getVkLinkToken();
        if (!token) {
            setError('Ссылка для привязки недействительна');
            setPhase('error');
            return;
        }

        setPhase('loading');
        setError(null);

        try {
            const phone = await requestVkPhoneNumber();

            await linkVkClient(token, phone.phone_number, phone.sign);
            onLinked();
        } catch (e) {
            const msg = e instanceof Error ? e.message : 'link_failed';
            setError(
                msg === 'invalid_token' || msg === 'token_consumed'
                    ? 'Ссылка для привязки истекла или уже использована'
                    : msg === 'invalid_phone_sign'
                        ? 'Не удалось подтвердить номер телефона'
                        : 'Не удалось привязать аккаунт',
            );
            setPhase('error');
        }
    }, [onLinked]);

    return (
        <div className="screen-center">
            <svg className="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M20 21a8 8 0 0 0-16 0" />
                <circle cx="12" cy="8" r="4" />
            </svg>
            <div className="empty-state-title">Подтвердите номер телефона</div>
            <div className="empty-state-sub">Чтобы увидеть свои записи</div>
            {error && (
                <p style={{ color: 'var(--red)', marginTop: 12, fontSize: 14 }}>{error}</p>
            )}
            <button
                type="button"
                className="retry-btn"
                style={{ marginTop: 16 }}
                onClick={handleClick}
                disabled={phase === 'loading'}
            >
                {phase === 'loading' ? 'Подождите…' : 'Подтвердить номер'}
            </button>
        </div>
    );
}
