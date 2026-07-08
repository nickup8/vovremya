/* ═══════════════ Telegram WebApp & Login Widget Types ═══════════════ */

interface TelegramUser {
    id: number;
    first_name: string;
    last_name?: string;
    phone_number?: string;
}

interface TelegramWebApp {
    initDataUnsafe?: { user?: TelegramUser };
}

interface TelegramLogin {
    reload(): void;
}

interface Window {
    Telegram?: {
        WebApp?: TelegramWebApp;
        Login?: TelegramLogin;
    };
}
