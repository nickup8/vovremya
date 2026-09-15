import { useCallback, useEffect, useState } from 'react';
import { getVkGroupId, getVkLinkToken, requestVkMessagePermission, requestVkPhoneNumber, requestVkUserInfo } from './lib/vkBridge';
import { cancelVkBooking, confirmVkBooking, getVkConsentStatus, linkVkClient, submitVkConsent, VkConsentStatus } from './lib/api';

type Phase = 'consent-not-checked' | 'consent-required' | 'phone-confirm' | 'confirmation' | 'loading' | 'status-error' | 'error' | 'allow-messages' | 'cancelled';

function FlowLayout({ children }: { children: React.ReactNode }) {
    return (
        <div className="vk-onboarding">
            <header className="vk-onboarding-header">
                <img className="vk-onboarding-logo" src="/images/logo.svg" alt="ИРСИ" />
            </header>
            <main className="vk-onboarding-main">
                <section className="vk-flow-card">
                    {children}
                </section>
            </main>
        </div>
    );
}

function FlowIcon({ variant, children }: { variant: 'orange' | 'neutral'; children: React.ReactNode }) {
    return (
        <div className={`vk-flow-icon vk-flow-icon--${variant}`}>
            {children}
        </div>
    );
}

function formatPrice(value: number | string): string {
    const num = Number(value);
    if (Number.isFinite(num)) {
        return num.toLocaleString('ru-RU') + ' ₽';
    }
    return value + ' ₽';
}

export function LinkOnboarding({ onLinked }: { onLinked: () => void }) {
    const [phase, setPhase] = useState<Phase>('consent-not-checked');
    const [error, setError] = useState<string | null>(null);
    const [permissionLoading, setPermissionLoading] = useState(false);
    const [statusData, setStatusData] = useState<VkConsentStatus | null>(null);

    useEffect(() => {
        const token = getVkLinkToken();
        if (!token) {
            setError('Ссылка для привязки недействительна');
            setPhase('error');
            return;
        }

        getVkConsentStatus(token)
            .then((res) => {
                setStatusData(res);
                if (res.consent_required) {
                    setPhase('consent-required');
                } else if (res.phone_required) {
                    setPhase('phone-confirm');
                } else {
                    setPhase('confirmation');
                }
            })
            .catch(() => {
                setError('Не удалось проверить статус согласия');
                setPhase('status-error');
            });
    }, []);

    const handleAllowMessages = useCallback(async (groupId: number) => {
        setPermissionLoading(true);
        await requestVkMessagePermission(groupId);
        onLinked();
    }, [onLinked]);

    const finishLink = useCallback(async (token: string) => {
        const phone = await requestVkPhoneNumber();

        const profile = await requestVkUserInfo();
        const profileData = profile
            ? { vk_profile_id: profile.id, first_name: profile.first_name, last_name: profile.last_name }
            : undefined;

        await linkVkClient(token, phone.phone_number, phone.sign, profileData);

        const groupId = getVkGroupId();
        if (groupId !== null) {
            setPhase('allow-messages');
        } else {
            onLinked();
        }
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
            await submitVkConsent(token);

            if (statusData?.phone_required) {
                await finishLink(token);
            } else {
                setPhase('confirmation');
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
    }, [finishLink, statusData]);

    const handlePhoneConfirm = useCallback(async () => {
        const token = getVkLinkToken();
        if (!token) {
            setError('Ссылка для привязки недействительна');
            setPhase('error');
            return;
        }

        setPhase('loading');
        setError(null);

        try {
            await finishLink(token);
        } catch (e) {
            const msg = e instanceof Error ? e.message : 'link_failed';
            if (msg === 'invalid_token' || msg === 'token_consumed') {
                setError('Ссылка для привязки истекла или уже использована');
            } else if (msg === 'invalid_phone_sign') {
                setError('Не удалось подтвердить номер телефона');
            } else {
                setError('Не удалось привязать аккаунт');
            }
            setPhase('error');
        }
    }, [finishLink]);

    const handleConfirm = useCallback(async () => {
        const token = getVkLinkToken();
        if (!token) {
            setError('Ссылка для привязки недействительна');
            setPhase('error');
            return;
        }

        setPhase('loading');
        setError(null);

        try {
            await confirmVkBooking(token);

            const groupId = getVkGroupId();
            if (groupId !== null) {
                setPhase('allow-messages');
            } else {
                onLinked();
            }
        } catch (e) {
            const msg = e instanceof Error ? e.message : 'confirm_failed';
            if (msg === 'invalid_token' || msg === 'token_consumed') {
                setError('Ссылка для привязки истекла или уже использована');
            } else if (msg === 'pdn_consent_required') {
                setError('Необходимо подтвердить согласие на обработку данных');
            } else if (msg === 'booking_unavailable') {
                setError('Запись недоступна');
            } else {
                setError('Не удалось подтвердить запись');
            }
            setPhase('error');
        }
    }, [onLinked]);

    const handleCancel = useCallback(async () => {
        const token = getVkLinkToken();
        if (!token) {
            setError('Ссылка для привязки недействительна');
            setPhase('error');
            return;
        }

        setPhase('loading');
        setError(null);

        try {
            await cancelVkBooking(token);
            setPhase('cancelled');
        } catch (e) {
            const msg = e instanceof Error ? e.message : 'cancel_failed';
            if (msg === 'invalid_token' || msg === 'token_consumed') {
                setError('Ссылка для привязки истекла или уже использована');
            } else {
                setError('Не удалось отменить запись');
            }
            setPhase('error');
        }
    }, []);

    // ── Loading: consent not checked ──

    if (phase === 'consent-not-checked') {
        return (
            <div className="vk-onboarding">
                <header className="vk-onboarding-header">
                    <img className="vk-onboarding-logo" src="/images/logo.svg" alt="ИРСИ" />
                </header>
                <main className="vk-onboarding-main">
                    <div className="vk-flow-loading">
                        <div className="loader" />
                        <span>Проверяем запись…</span>
                    </div>
                </main>
            </div>
        );
    }

    // ── Loading: phase transition ──

    if (phase === 'loading') {
        return (
            <div className="vk-onboarding">
                <header className="vk-onboarding-header">
                    <img className="vk-onboarding-logo" src="/images/logo.svg" alt="ИРСИ" />
                </header>
                <main className="vk-onboarding-main">
                    <div className="vk-flow-loading">
                        <div className="loader" />
                        <span>Подождите…</span>
                    </div>
                </main>
            </div>
        );
    }

    // ── Status error ──

    if (phase === 'status-error') {
        return (
            <FlowLayout>
                <FlowIcon variant="neutral">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                </FlowIcon>
                <div className="vk-flow-title">Не удалось загрузить запись</div>
                <div className="vk-flow-copy">{error}</div>
            </FlowLayout>
        );
    }

    // ── Cancelled ──

    if (phase === 'cancelled') {
        return (
            <FlowLayout>
                <FlowIcon variant="neutral">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                        <polyline points="22 4 12 14.01 9 11.01" />
                    </svg>
                </FlowIcon>
                <div className="vk-flow-title">Запись отменена</div>
                <div className="vk-flow-copy">Вы больше не записаны на этот приём</div>
            </FlowLayout>
        );
    }

    // ── Allow messages ──

    if (phase === 'allow-messages') {
        const groupId = getVkGroupId()!;
        return (
            <FlowLayout>
                <FlowIcon variant="orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                </FlowIcon>
                <div className="vk-flow-title">Не пропускайте важное</div>
                <div className="vk-flow-copy">Разрешите ИРСИ присылать напоминания о визитах и сообщать об изменениях записи в VK.</div>
                <div className="vk-flow-actions">
                    <button
                        type="button"
                        className="vk-primary-btn"
                        onClick={() => handleAllowMessages(groupId)}
                        disabled={permissionLoading}
                    >
                        {permissionLoading ? 'Подождите…' : 'Разрешить уведомления'}
                    </button>
                    <button
                        type="button"
                        className="vk-secondary-btn"
                        onClick={onLinked}
                    >
                        Позже
                    </button>
                </div>
            </FlowLayout>
        );
    }

    // ── Confirmation ──

    if (phase === 'confirmation' && statusData) {
        const appt = statusData.appointment;
        return (
            <FlowLayout>
                <FlowIcon variant="orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                </FlowIcon>
                <div className="vk-flow-title">Подтвердите запись</div>
                <div className="vk-flow-copy">Проверьте детали перед подтверждением</div>

                <div className="vk-booking-summary">
                    <div className="vk-booking-service">{appt.service}</div>
                    <div className="vk-booking-datetime">{appt.date} · {appt.time}</div>
                </div>

                <div className="vk-detail-list">
                    <div className="vk-detail-row">
                        <span className="vk-detail-label">Стоимость</span>
                        <span className="vk-detail-value">{formatPrice(appt.price)}</span>
                    </div>
                    {appt.address && (
                        <div className="vk-detail-row">
                            <span className="vk-detail-label">Адрес</span>
                            <span className="vk-detail-value">{appt.address}</span>
                        </div>
                    )}
                </div>

                {error && <div className="vk-alert">{error}</div>}

                <div className="vk-flow-actions">
                    <button
                        type="button"
                        className="vk-primary-btn"
                        onClick={handleConfirm}
                    >
                        Подтвердить запись
                    </button>
                    <button
                        type="button"
                        className="vk-danger-link"
                        onClick={handleCancel}
                    >
                        Отменить запись
                    </button>
                </div>
            </FlowLayout>
        );
    }

    // ── Phone confirm ──

    if (phase === 'phone-confirm') {
        return (
            <FlowLayout>
                <FlowIcon variant="orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
                    </svg>
                </FlowIcon>
                <div className="vk-flow-title">Подтвердите номер телефона</div>
                <div className="vk-flow-copy">Номер нужен, чтобы связать запись с вашим профилем и показать ваши визиты.</div>

                {error && <div className="vk-alert">{error}</div>}

                <div className="vk-flow-actions">
                    <button
                        type="button"
                        className="vk-primary-btn"
                        onClick={handlePhoneConfirm}
                    >
                        Подтвердить номер
                    </button>
                </div>
            </FlowLayout>
        );
    }

    // ── Generic error ──

    if (phase === 'error') {
        return (
            <FlowLayout>
                <FlowIcon variant="neutral">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                </FlowIcon>
                <div className="vk-flow-title">Не удалось продолжить</div>
                <div className="vk-flow-copy">{error ?? 'Произошла ошибка'}</div>
            </FlowLayout>
        );
    }

    // ── Consent required (default fallback) ──

    return (
        <FlowLayout>
            <FlowIcon variant="orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
            </FlowIcon>
            <div className="vk-flow-title">Согласие на обработку данных</div>
            <div className="vk-flow-copy">
                Для привязки аккаунта требуется ваше согласие на обработку персональных данных.
            </div>

            <div className="vk-legal-box">
                Нажимая «Принимаю», вы соглашаетесь с{' '}
                <a href="/offer" target="_blank" rel="noopener noreferrer" className="vk-legal-link">Публичной офертой</a>
                {' '}и{' '}
                <a href="/privacy" target="_blank" rel="noopener noreferrer" className="vk-legal-link">Политикой обработки персональных данных</a>.
            </div>

            {error && <div className="vk-alert">{error}</div>}

            <div className="vk-flow-actions">
                <button
                    type="button"
                    className="vk-primary-btn"
                    onClick={handleConsent}
                >
                    Принимаю
                </button>
            </div>
        </FlowLayout>
    );
}
