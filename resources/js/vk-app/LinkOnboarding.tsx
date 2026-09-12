import { useCallback, useState } from 'react';
import { getVkGroupId, getVkLinkToken, requestVkMessagePermission, requestVkPhoneNumber } from './lib/vkBridge';
import { linkVkClient, submitVkConsent } from './lib/api';

type Phase = 'idle' | 'loading' | 'error' | 'allow-messages';

export function LinkOnboarding({ onLinked }: { onLinked: () => void }) {
    const [phase, setPhase] = useState<Phase>('idle');
    const [error, setError] = useState<string | null>(null);
    const [permissionLoading, setPermissionLoading] = useState(false);

    const handleAllowMessages = useCallback(async (groupId: number) => {
        setPermissionLoading(true);
        await requestVkMessagePermission(groupId);
        onLinked();
    }, [onLinked]);

    const handleConsent = useCallback(async () => {
        const token = getVkLinkToken();
        if (!token) {
            setError('Ссылка для привязки недействительна');
            setPhase('error');
            return;
        }

        setPhase('loading');
        setError(null);

        try {
            await submitVkConsent();

            const phone = await requestVkPhoneNumber();

            await linkVkClient(token, phone.phone_number, phone.sign);

            const groupId = getVkGroupId();
            if (groupId !== null) {
                setPhase('allow-messages');
            } else {
                onLinked();
            }
        } catch (e) {
            const msg = e instanceof Error ? e.message : 'link_failed';
            if (msg === 'consent_failed') {
                setError('Не удалось подтвердить согласие. Попробуйте ещё раз');
            } else if (msg === 'invalid_token' || msg === 'token_consumed') {
                setError('Ссылка для привязки истекла или уже использована');
            } else if (msg === 'invalid_phone_sign') {
                setError('Не удалось подтвердить номер телефона');
            } else {
                setError('Не удалось привязать аккаунт');
            }
            setPhase('error');
        }
    }, [onLinked]);

    if (phase === 'allow-messages') {
        const groupId = getVkGroupId()!;
        return (
            <div className="screen-center">
                <svg className="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
                <div className="empty-state-title">Уведомления о записях</div>
                <div className="empty-state-sub">Разрешите присылать напоминания и важные уведомления в VK</div>
                <button
                    type="button"
                    className="retry-btn"
                    style={{ marginTop: 16 }}
                    onClick={() => handleAllowMessages(groupId)}
                    disabled={permissionLoading}
                >
                    {permissionLoading ? 'Подождите…' : 'Разрешить уведомления'}
                </button>
                <button
                    type="button"
                    className="retry-btn"
                    style={{ marginTop: 8, background: 'transparent', color: 'var(--text-secondary)' }}
                    onClick={onLinked}
                >
                    Позже
                </button>
            </div>
        );
    }

    return (
        <div className="screen-center">
            <svg className="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            </svg>
            <div className="empty-state-title">Согласие на обработку данных</div>
            <div className="empty-state-sub">
                Для привязки аккаунта требуется ваше согласие на обработку персональных данных
            </div>
            <p style={{ marginTop: 12, fontSize: 13, color: 'var(--text-secondary)', textAlign: 'center' }}>
                Нажимая «Принимаю», вы соглашаетесь с{' '}
                <a href="/offer" target="_blank" rel="noopener noreferrer" style={{ color: 'var(--accent)' }}>Публичной офертой</a>
                {' '}и{' '}
                <a href="/privacy" target="_blank" rel="noopener noreferrer" style={{ color: 'var(--accent)' }}>Политикой обработки персональных данных</a>
            </p>
            {error && (
                <p style={{ color: 'var(--red)', marginTop: 12, fontSize: 14 }}>{error}</p>
            )}
            <button
                type="button"
                className="retry-btn"
                style={{ marginTop: 16 }}
                onClick={handleConsent}
                disabled={phase === 'loading'}
            >
                {phase === 'loading' ? 'Подождите…' : 'Принимаю'}
            </button>
        </div>
    );
}
