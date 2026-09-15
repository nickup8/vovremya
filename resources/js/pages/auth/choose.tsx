import { useState, useEffect, useRef } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { MessageCircle, ArrowRight, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/PublicLayout';

Choose.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    telegramBotName: string | null;
    maxBotName: string | null;
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
export default function Choose({ telegramBotName, maxBotName }: PageProps) {
    // 'loading' — запрос токена в процессе
    const [token, setToken] = useState<string | null>(null);
    const [status, setStatus] = useState<AsyncStatus>('loading');
    const [error, setError] = useState<string | null>(null);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const mountedRef = useRef(true);
    const vkFormRef = useRef<HTMLFormElement>(null);
    const { flash } = usePage().props as { flash?: { error?: string | null } };

    // Server-side flash error (e.g. from VK OAuth callback)
    const flashError = flash?.error ?? null;

    // Displayed error: local error takes priority, then flash
    const displayError = error || flashError;

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

    // ─── CSRF token для VK form ───
    useEffect(() => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        const form = vkFormRef.current;
        if (meta && form && !form.querySelector('input[name="_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = meta.getAttribute('content') || '';
            form.appendChild(input);
        }
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

    const maxBotLink = token
        ? `https://max.ru/${maxBotName}?start=${token}`
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
                            Вход в личный кабинет
                        </h1>
                        <p className="mt-2 text-sm text-stone-400 dark:text-stone-500">
                            Выберите удобный способ входа
                        </p>
                    </div>

                    <div className="mt-8 space-y-4">
                        {/* Кнопка входа / Повторить */}
                        {!isError ? (
                            <>
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

                                {maxBotName && (
                                    <a href={maxBotLink} target="_blank" rel="noopener noreferrer">
                                        <Button
                                            size="lg"
                                            disabled={!token || isTokenLoading || isPolling || isSuccess}
                                            className="group h-14 w-full rounded-2xl bg-[#6366F1] text-base font-semibold text-white shadow-lg shadow-[#6366F1]/20 transition-all hover:scale-[1.02] hover:shadow-xl disabled:opacity-50 disabled:hover:scale-100 dark:bg-[#6366F1] dark:text-white"
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
                                                    Войти через MAX
                                                    <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                                </>
                                            )}
                                        </Button>
                                    </a>
                                )}

                                {/* VK ID */}
                                <form ref={vkFormRef} method="POST" action="/auth/vk/start" className="hidden" />
                                <Button
                                    size="lg"
                                    type="button"
                                    onClick={() => vkFormRef.current?.submit()}
                                    className="group h-14 w-full rounded-2xl bg-[#0077FF] text-base font-semibold text-white shadow-lg shadow-[#0077FF]/20 transition-all hover:scale-[1.02] hover:shadow-xl disabled:opacity-50 disabled:hover:scale-100 dark:bg-[#0077FF] dark:text-white"
                                >
                                    <svg className="size-5" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12.785 16.241s.288-.032.436-.194c.136-.148.132-.427.132-.427s-.02-1.304.587-1.496c.598-.189 1.368 1.26 2.184 1.817.617.42 1.087.328 1.087.328l2.184-.03s1.142-.071.6-.964c-.044-.073-.316-.656-1.624-1.854-1.37-1.256-1.186-1.052.464-3.23.999-1.322 1.398-2.13 1.27-2.484-.121-.335-.87-.248-.87-.248l-2.46.015s-.183-.025-.318.056c-.132.079-.217.263-.217.263s-.389 1.035-.907 1.918c-1.094 1.863-1.532 1.96-1.708 1.846-.415-.266-.312-1.073-.312-1.644 0-1.788.271-2.536-.529-2.73-.266-.064-.462-.107-1.143-.114-.874-.008-1.614.003-2.032.207-.279.136-.495.44-.363.457.163.022.533.1.73.364.254.342.245 1.112.245 1.112s.146 2.125-.34 2.394c-.332.184-.789-.192-1.772-1.915-.502-.884-.88-1.858-.88-1.858s-.073-.178-.204-.274c-.159-.117-.38-.154-.38-.154l-2.34.015s-.35.01-.479.162c-.114.134-.009.41-.009.41s1.84 4.298 3.932 6.465c1.912 1.982 4.086 1.854 4.086 1.854h.988z" />
                                    </svg>
                                    Войти через VK
                                    <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                </Button>
                            </>
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
                        {(isError || flashError) && (
                            <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-center dark:border-red-900/50 dark:bg-red-950/30">
                                <p className="text-sm font-medium text-red-700 dark:text-red-300">
                                    {displayError || 'Произошла ошибка. Попробуйте снова.'}
                                </p>
                            </div>
                        )}
                    </div>

                    <p className="mt-8 text-center text-xs text-stone-400 dark:text-stone-500">
                        Нажимая кнопку, вы соглашаетесь с{' '}
                        <Link href="/offer" target="_blank" className="underline hover:text-stone-600 dark:hover:text-stone-300">Публичной офертой</Link>{' '}
                        и{' '}
                        <Link href="/privacy" target="_blank" className="underline hover:text-stone-600 dark:hover:text-stone-300">Политикой обработки персональных данных</Link>
                    </p>
                </div>
            </div>
        </>
    );
}
