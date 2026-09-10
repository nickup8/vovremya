import { createContext, useContext, type ReactNode } from 'react';

export interface PlatformBackButton {
    show(): void;
    hide(): void;
    onClick(cb: () => void): void;
    offClick(cb: () => void): void;
}

export interface Platform {
    backButton: PlatformBackButton;
    haptic: {
        impact(style: 'light' | 'medium' | 'heavy' | 'rigid' | 'soft'): void;
        notify(type: 'success' | 'error' | 'warning'): void;
    };
    openLink(url: string): void;
}

const PlatformCtx = createContext<Platform | null>(null);

export function PlatformProvider({ platform, children }: { platform: Platform; children: ReactNode }) {
    return <PlatformCtx.Provider value={platform}>{children}</PlatformCtx.Provider>;
}

export function usePlatform(): Platform {
    const ctx = useContext(PlatformCtx);
    if (!ctx) throw new Error('usePlatform must be used within <PlatformProvider>');
    return ctx;
}
