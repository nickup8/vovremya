import { useState, useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { MessageCircle, ArrowRight, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';

Choose.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    telegramBotName: string | null;
    [key: string]: unknown;
}

/**
 * Реальные статусы асинхронных операций.
 * 'waiting' выводится в UI как производный: token !== null && status === 'idle'.
 */
type AsyncStatus = 'loading' | 'idle' | 'success' | 'error' | 'expired';

/**
 * Страница авторизации через Telegram-бота (Deep Linking + Contact Request).
 *
 * Флоу:
 * 1. При монтировании запрашиваем login_token у бэкенда (axios POST)
 * 2. Рендерим кнопку "Войти через Telegram" → ссылка t.me/BOT?start=auth_TOKEN
 * 3. Запускаем поллинг (каждые 2 сек) — проверяем статус токена (axios GET)
 * 4. Бот получает /start auth_TOKEN → просит поделиться контактом
 * 5. Пользователь отправляет контакт → бот создаёт/находит юзера
 * 6. Бот обновляет статус токена на authenticated
 * 7. Поллинг получает success → редирект на /admin/calendar
 */
export default function Choose({ telegramBotName }: PageProps) {
    // 'loading' — запрос токена в процессе
    const [token, setToken] = useState<string | null>(null);
    const [status, setStatus] = useState<AsyncStatus>('loading');
    const [error, setError] = useState<string | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const mountedRef = useRef(true);

    // Поллинг активен когда есть токен и статус не финальный
    const isPolling = token !== null && status === 'idle';
    const isTokenLoading = status === 'loading';
    const isSuccess = status === 'success';
    const isError = status === 'error' || status === 'expired';

    // ─── Очистка при размонтировании ───
    useEffect(() => {
        return () => {
            mountedRef.current = false;

            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };
    }, []);

    // ─── Шаг 1: Получаем login_token (axios) ───
    useEffect(() => {
        let cancelled = false;

        axios.post('/auth/telegram/token')
            .then(({ data }) => {
                if (cancelled) {
                    return;
                }

                if (data.token) {
                    setToken(data.token);
                    setStatus('idle');
                } else {
                    setStatus('error');
                    setError('Не удалось получить токен авторизации.');
                }
            })
            .catch((err) => {
                if (cancelled) {
                    return;
                }

                console.error('Ошибка получения токена:', err);
                setStatus('error');

                if (err.response?.status === 419) {
                    setError('Сессия истекла. Обновите страницу.');
                } else {
                    setError('Ошибка сети. Попробуйте обновить страницу.');
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    // ─── Шаг 2: Поллинг статуса (axios) ───
    useEffect(() => {
        if (!token || status === 'success' || status === 'error' || status === 'expired') {
            return;
        }

        const poll = () => {
            if (!mountedRef.current) {
                return;
            }

            axios.get(`/auth/telegram/check/${token}`)
                .then(({ data }) => {
                    if (!mountedRef.current) {
                        return;
                    }

                    if (data.status === 'success') {
                        setStatus('success');

                        if (intervalRef.current) {
                            clearInterval(intervalRef.current);
                            intervalRef.current = null;
                        }

                        setTimeout(() => {
                            window.location.href = '/admin/calendar';
                        }, 800);
                    } else if (data.status === 'expired') {
                        setStatus('expired');
                        setError(data.message || 'Токен истёк. Получите новый.');

                        if (intervalRef.current) {
                            clearInterval(intervalRef.current);
                            intervalRef.current = null;
                        }
                    } else if (data.status === 'error') {
                        setStatus('error');
                        setError(data.message || 'Ошибка авторизации.');

                        if (intervalRef.current) {
                            clearInterval(intervalRef.current);
                            intervalRef.current = null;
                        }
                    }
                })
                .catch((err) => {
                    if (!mountedRef.current) {
                        return;
                    }

                    console.warn('Поллинг: сетевая ошибка', err.response?.status ?? err.message);
                });
        };

        intervalRef.current = setInterval(poll, 2000);
        poll();

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };
    }, [token, status]);

    // ─── Обработчик «Повторить» ───
    function handleRetry() {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }

        setStatus('loading');
        setError(null);

        axios.post('/auth/telegram/token')
            .then(({ data }) => {
                if (!mountedRef.current) {
                    return;
                }

                if (data.token) {
                    setToken(data.token);
                    setStatus('idle');
                } else {
                    setStatus('error');
                    setError('Не удалось получить токен авторизации.');
                }
            })
            .catch((err) => {
                if (!mountedRef.current) {
                    return;
                }

                console.error('Ошибка получения токена:', err);
                setStatus('error');
                setError('Ошибка сети. Попробуйте обновить страницу.');
            });
    }

    // ─── Рендер ───

    if (!telegramBotName) {
        return (
            <>
                <Head title="Вход — Вовремя" />
                <div className="flex min-h-screen items-center justify-center bg-[#FAF8F5] px-5 dark:bg-[#121110]">
                    <p className="text-sm text-red-500">
                        Telegram-бот не настроен. Обратитесь к администратору.
                    </p>
                </div>
            </>
        );
    }

    const botLink = token
        ? `https://t.me/${telegramBotName}?start=${token}`
        : '#';

    return (
        <>
            <Head title="Вход — Вовремя" />

            <div className="flex min-h-screen items-center justify-center bg-[#FAF8F5] px-5 dark:bg-[#121110]">
                <div className="w-full max-w-md">
                    <div className="text-center">
                        <span className="text-xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                            вовремя
                        </span>
                        <h1 className="mt-8 text-2xl font-bold tracking-tight text-stone-900 dark:text-stone-50">
                            Вход через Telegram
                        </h1>
                        <p className="mt-2 text-sm text-stone-400 dark:text-stone-500">
                            Нажмите кнопку и поделитесь номером телефона в боте
                        </p>
                    </div>

                    <div className="mt-8 space-y-4">
                        {/* Кнопка входа / Повторить */}
                        {!isError ? (
                            <a href={botLink} target="_blank" rel="noopener noreferrer">
                                <Button
                                    size="lg"
                                    disabled={!token || isTokenLoading || isPolling || isSuccess}
                                    className="group h-14 w-full rounded-2xl bg-[#2AABEE] text-base font-semibold text-white shadow-lg shadow-[#2AABEE]/20 transition-all hover:scale-[1.02] hover:shadow-xl disabled:opacity-50 disabled:hover:scale-100 dark:bg-[#2AABEE] dark:text-white"
                                >
                                    {isSuccess ? (
                                        <>
                                            <span className="text-base">✅</span>
                                            Авторизация успешна!
                                        </>
                                    ) : isTokenLoading ? (
                                        <>
                                            <Loader2 className="size-5 animate-spin" />
                                            Подготовка...
                                        </>
                                    ) : (
                                        <>
                                            <MessageCircle className="size-5" />
                                            Войти через Telegram
                                            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                        </>
                                    )}
                                </Button>
                            </a>
                        ) : (
                            <Button
                                size="lg"
                                onClick={handleRetry}
                                className="group h-14 w-full rounded-2xl border-2 border-stone-200 bg-white text-base font-semibold text-stone-900 transition-all hover:scale-[1.02] hover:bg-stone-50 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-50 dark:hover:bg-stone-800"
                            >
                                <RotateCcw className="size-5" />
                                Повторить
                            </Button>
                        )}

                        {/* Индикатор поллинга (производный статус) */}
                        {isPolling && (
                            <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-center dark:border-blue-900/50 dark:bg-blue-950/30">
                                <div className="flex items-center justify-center gap-2">
                                    <Loader2 className="size-4 animate-spin text-blue-500" />
                                    <p className="text-sm font-medium text-blue-700 dark:text-blue-300">
                                        Ожидаем подтверждения в Telegram...
                                    </p>
                                </div>
                                <p className="mt-1 text-xs text-blue-500/80 dark:text-blue-400/70">
                                    Откройте бот и поделитесь номером телефона
                                </p>
                            </div>
                        )}

                        {/* Успех */}
                        {isSuccess && (
                            <div className="rounded-2xl border border-green-200 bg-green-50 p-4 text-center dark:border-green-900/50 dark:bg-green-950/30">
                                <p className="text-sm font-medium text-green-700 dark:text-green-300">
                                    ✅ Авторизация успешна! Переходим в кабинет...
                                </p>
                            </div>
                        )}

                        {/* Ошибка */}
                        {isError && (
                            <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-center dark:border-red-900/50 dark:bg-red-950/30">
                                <p className="text-sm font-medium text-red-700 dark:text-red-300">
                                    {error || 'Произошла ошибка. Попробуйте снова.'}
                                </p>
                            </div>
                        )}
                    </div>

                    <p className="mt-8 text-center text-xs text-stone-400 dark:text-stone-500">
                        Нажимая кнопку, вы соглашаетесь с условиями сервиса
                    </p>
                </div>
            </div>
        </>
    );
}
