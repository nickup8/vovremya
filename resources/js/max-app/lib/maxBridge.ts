// Типы для MAX WebApp Bridge (CDN-скрипт, не npm)
interface MaxBackButton {
    show(): void;
    hide(): void;
    onClick(cb: () => void): void;
    offClick(cb: () => void): void;
}

interface MaxHapticFeedback {
    impactOccurred(style: 'light' | 'medium' | 'heavy' | 'rigid' | 'soft'): void;
    notificationOccurred(type: 'success' | 'error' | 'warning'): void;
}

interface MaxWebApp {
    initData: string;
    initDataUnsafe: Record<string, unknown>;
    platform: string;
    BackButton: MaxBackButton;
    openLink(url: string): void;
    HapticFeedback: MaxHapticFeedback;
    enableClosingConfirmation(): void;
    disableClosingConfirmation(): void;
}

declare global {
    interface Window {
        WebApp?: MaxWebApp;
    }
}

/** Приложение запущено внутри MAX */
export function isInsideMax(): boolean {
    return typeof window !== 'undefined' && typeof window.WebApp !== 'undefined' && !!window.WebApp;
}

/** Строка initData для серверной верификации (null если вне MAX или пусто) */
export function getInitData(): string | null {
    const raw = window.WebApp?.initData;
    if (!raw) return null;
    return raw;
}

/** Платформа: ios | android | desktop | web */
export function getPlatform(): string | null {
    return window.WebApp?.platform ?? null;
}

/** Кнопка «Назад» (safe — no-op вне MAX) */
export const backButton = {
    show(): void {
        window.WebApp?.BackButton?.show();
    },
    hide(): void {
        window.WebApp?.BackButton?.hide();
    },
    onClick(cb: () => void): void {
        window.WebApp?.BackButton?.onClick(cb);
    },
    offClick(cb: () => void): void {
        window.WebApp?.BackButton?.offClick(cb);
    },
};

/** Открыть ссылку во внешнем браузере */
export function openLink(url: string): void {
    window.WebApp?.openLink?.(url);
}

/** Тактильная обратная связь (safe — no-op вне MAX) */
export const haptic = {
    impact(style: 'light' | 'medium' | 'heavy' | 'rigid' | 'soft'): void {
        window.WebApp?.HapticFeedback?.impactOccurred(style);
    },
    notify(type: 'success' | 'error' | 'warning'): void {
        window.WebApp?.HapticFeedback?.notificationOccurred(type);
    },
};

/** Подтверждение при закрытии (safe — no-op вне MAX) */
export const closingConfirmation = {
    enable(): void {
        window.WebApp?.enableClosingConfirmation?.();
    },
    disable(): void {
        window.WebApp?.disableClosingConfirmation?.();
    },
};
