import { useState, useEffect, useCallback, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { MessageCircle, ArrowRight, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';

Choose.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    telegramBotName: string | null;
    [key: string]: unknown;
}

type AuthStatus = 'idle' | 'loading' | 'waiting' | 'success' | 'error' | 'expired';

/**
 * Страница авторизации через Telegram-бота (Deep Linking + Contact Request).
 *
 * Флоу:
 * 1. При монтировании запрашиваем login_token у бэкенда
 * 2. Рендерим кнопку "Войти через Telegram" → ссылка t.me/BOT?start=auth_TOKEN
 * 3. Запускаем поллинг (каждые 2 сек) — проверяем статус токена
 * 4. Бот получает /start auth_TOKEN → просит поделиться контактом
 * 5. Пользователь отправляет контакт → бот создаёт/находит юзера
 * 6. Бот обновляет статус токена на authenticated
 * 7. Поллинг получает success → редирект на /admin/calendar
 */
export default function Choose({ telegramBotName }: PageProps) {
    const [token, setToken] = useState<string | null>(null);
    const [status, setStatus] = useState<AuthStatus>('idle');
    const [error, setError] = useState<string | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const tokenFetchedRef = useRef(false);

    // ─── Шаг 1: Получаем login_token ───
    useEffect(() => {
        if (tokenFetchedRef.current) {
            return;
        }

        tokenFetchedRef.current = true;

        fetch('/auth/telegram/token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.token) {
                    setToken(data.token);
                    setStatus('idle');
                } else {
                    setStatus('error');
                    setError('Не удалось получить токен авторизации.');
                }
            })
            .catch(() => {
                setStatus('error');
                setError('Ошибка сети. Попробуйте обновить страницу.');
            });
    }, []);

    // ─── Шаг 3: Поллинг статуса ───
    const pollStatus = useCallback(async (pollToken: string) => {
        try {
            const res = await fetch(`/auth/telegram/check/${pollToken}`);
            const data = await res.json();

            if (data.status === 'success') {
                setStatus('success');

                // Небольшая задержка для показа сообщения об успехе
                setTimeout(() => {
                    router.visit('/admin/calendar');
                }, 1000);
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
            // pending — продолжаем поллинг
        } catch {
            // Сетевая ошибка — продолжаем поллинг
        }
    }, []);

    // Запускаем поллинг когда токен получен
    useEffect(() => {
        if (!token) {
            return;
        }

        intervalRef.current = setInterval(() => {
            pollStatus(token);
        }, 2000);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };
    }, [token, pollStatus]);

    // Очистка при размонтировании
    useEffect(() => {
        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, []);

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

    const isProcessing = status === 'loading' || status === 'waiting' || status === 'success';

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
                        {/* Кнопка входа через Telegram */}
                        <a href={botLink} target="_blank" rel="noopener noreferrer">
                            <Button
                                size="lg"
                                disabled={!token || isProcessing}
                                className="group h-14 w-full rounded-2xl bg-[#2AABEE] text-base font-semibold text-white shadow-lg shadow-[#2AABEE]/20 transition-all hover:scale-[1.02] hover:shadow-xl disabled:opacity-50 disabled:hover:scale-100 dark:bg-[#2AABEE] dark:text-white"
                            >
                                {status === 'success' ? (
                                    <>
                                        <span className="size-5">✅</span>
                                        Авторизация успешна!
                                    </>
                                ) : status === 'waiting' || (token && status === 'idle') ? (
                                    <>
                                        <MessageCircle className="size-5" />
                                        Войти через Telegram
                                        <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                    </>
                                ) : (
                                    <>
                                        <Loader2 className="size-5 animate-spin" />
                                        Загрузка...
                                    </>
                                )}
                            </Button>
                        </a>

                        {/* Статус поллинга */}
                        {status === 'waiting' && (
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

                        {status === 'success' && (
                            <div className="rounded-2xl border border-green-200 bg-green-50 p-4 text-center dark:border-green-900/50 dark:bg-green-950/30">
                                <p className="text-sm font-medium text-green-700 dark:text-green-300">
                                    ✅ Авторизация успешна! Переходим в кабинет...
                                </p>
                            </div>
                        )}

                        {(status === 'error' || status === 'expired') && (
                            <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-center dark:border-red-900/50 dark:bg-red-950/30">
                                <p className="text-sm font-medium text-red-700 dark:text-red-300">
                                    {error || 'Произошла ошибка. Попробуйте снова.'}
                                </p>
                                {status === 'expired' && (
                                    <button
                                        onClick={() => {
                                            tokenFetchedRef.current = false;
                                            setToken(null);
                                            setStatus('idle');
                                            setError(null);
                                            // Перезапрос токена произойдёт в useEffect
                                        }}
                                        className="mt-2 text-xs font-medium text-red-600 underline hover:text-red-700 dark:text-red-400"
                                    >
                                        Получить новый токен
                                    </button>
                                )}
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
