import { Button, Spinner, Typography } from '@maxhub/max-ui';
import { getProfile, type Profile } from '../lib/api';
import { useAsync } from '../lib/useAsync';

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
                    <div className="profile-value">{data?.phone ?? '—'}</div>
                    {data?.phone && <div className="profile-hint">Получен из мессенджера</div>}
                </div>
            </article>
        </div>
    );
}
