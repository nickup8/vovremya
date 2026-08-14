import { Button, CellHeader, CellList, CellSimple, Spinner, Typography } from '@maxhub/max-ui';
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
            <CellList mode="island" header={<CellHeader>Профиль</CellHeader>}>
                <CellSimple title="Имя" subtitle={data?.name ?? '—'} showChevron={false} />
                <CellSimple title="Телефон" subtitle={data?.phone ?? '—'} showChevron={false} />
            </CellList>
        </div>
    );
}
