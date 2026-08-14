import { useCallback, useEffect, useState } from 'react';
import { UnauthorizedError } from './api';

interface AsyncState<T> {
    data: T | null;
    loading: boolean;
    error: string | null;
    reload: () => void;
}

/** Дженерик-хук загрузки async-данных */
export function useAsync<T>(fetcher: () => Promise<T>): AsyncState<T> {
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [counter, setCounter] = useState(0);

    const reload = useCallback(() => {
        setData(null);
        setError(null);
        setLoading(true);
        setCounter((c) => c + 1);
    }, []);

    useEffect(() => {
        let cancelled = false;

        fetcher()
            .then((res) => {
                if (!cancelled) {
                    setData(res);
                    setLoading(false);
                }
            })
            .catch((err: unknown) => {
                if (!cancelled) {
                    let msg = 'Ошибка загрузки, попробуйте позже';

                    if (err === 'no_init_data') {
                        msg = 'Откройте внутри MAX';
                    } else if (err instanceof UnauthorizedError) {
                        msg = 'Не удалось авторизоваться';
                    }

                    setError(msg);
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    // counter нужен для reload — реран при изменении счётчика
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [counter]);

    return { data, loading, error, reload };
}
