/* ═══════════════ Telegram WebApp Types ═══════════════ */

interface TelegramUser {
    id: number;
    first_name: string;
    last_name?: string;
    phone_number?: string;
}

interface TelegramWebApp {
    initDataUnsafe?: { user?: TelegramUser };
}

interface Window {
    Telegram?: {
        WebApp?: TelegramWebApp;
    };
}
