import { useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/layouts/PublicLayout';

Choose.layout = (page: React.ReactNode) => <PublicLayout children={page} />;

interface PageProps {
    telegramBotName: string | null;
    [key: string]: unknown;
}

/**
 * Страница авторизации через Telegram Login Widget.
 *
 * Виджет — официальный iframe от Telegram, который:
 * 1. Показывает кнопку «Войти через Telegram»
 * 2. После клика пользователя открывает pop-up с подтверждением
 * 3. Перенаправляет на data-auth-url (наш /auth/telegram/callback) с GET-параметрами:
 *    id, first_name, last_name, username, photo_url, auth_date, hash
 * 4. Бэкенд проверяет HMAC-SHA256 подпись и авторизует пользователя
 */
export default function Choose({ telegramBotName }: PageProps) {
    const widgetRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        // Telegram Login Widget загружается через <script>.
        // После монтирования React-компонента нужно повторно инициализировать скрипт,
        // если Telegram Widget SDK уже был загружен ранее (hot reload / SPA-навигация).
        if (widgetRef.current && window.Telegram && window.Telegram.Login) {
            window.Telegram.Login.reload();
        }
    }, []);

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

    return (
        <>
            <Head title="Вход — Вовремя" />

            {/* Скрипт Telegram Login Widget */}
            <script
                async
                src="https://telegram.org/js/telegram-widget.js?22"
                data-telegram-login={telegramBotName}
                data-size="large"
                data-auth-url="/auth/telegram/callback"
                data-request-access="write"
            />

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
                            Нажмите кнопку ниже и подтвердите вход в Telegram
                        </p>
                    </div>

                    {/* Контейнер для Telegram Login Widget */}
                    <div className="mt-8 flex justify-center" ref={widgetRef}>
                        <div id="telegram-login-widget" />
                    </div>

                    <p className="mt-8 text-center text-xs text-stone-400 dark:text-stone-500">
                        Нажимая кнопку, вы соглашаетесь с условиями сервиса
                    </p>
                </div>
            </div>
        </>
    );
}
