import { Button, Spinner, Typography } from '@maxhub/max-ui';
import { getProfile, type Profile } from '../lib/api';
import { useAsync } from '../lib/useAsync';

function formatPhone(raw: string): string {
    const digits = raw.replace(/\D/g, '');
    let local: string;
    if (digits.length === 11 && (digits[0] === '7' || digits[0] === '8')) {
        local = digits.slice(1);
    } else if (digits.length === 10) {
        local = digits;
    } else {
        return raw;
    }
    return `+7 (${local.slice(0, 3)}) ${local.slice(3, 6)}-${local.slice(6, 8)}-${local.slice(8, 10)}`;
}

export function ProfileScreen() {
    const { data, loading, error, reload } = useAsync<Profile>(getProfile);

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

    return (
        <div className="screen-content">
            <p className="screen-subtitle">Данные доступны только для просмотра</p>

            <article className="profile-card">
                <div className="profile-row">
                    <div className="profile-label">Имя</div>
                    <div className="profile-value">{data?.name ?? '—'}</div>
                </div>
                <div className="profile-row">
                    <div className="profile-label">Телефон</div>
                    <div className="profile-value">{data?.phone ? formatPhone(data.phone) : '—'}</div>
                    {data?.phone && <div className="profile-hint">Получен из мессенджера</div>}
                </div>
            </article>
        </div>
    );
}
