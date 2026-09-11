import type { Platform } from '../../shared/platform/PlatformContext';
import bridge from '@vkontakte/vk-bridge';

const IMPACT_MAP: Record<string, 'light' | 'medium' | 'heavy'> = {
    light: 'light',
    medium: 'medium',
    heavy: 'heavy',
    rigid: 'heavy',
    soft: 'light',
};

export const vkPlatform: Platform = {
    appName: 'VK',

    backButton: {
        show(): void {},
        hide(): void {},
        onClick(): void {},
        offClick(): void {},
    },

    haptic: {
        impact(style): void {
            bridge.send('VKWebAppTapticImpactOccurred', { style: IMPACT_MAP[style] ?? 'medium' }).catch(() => {});
        },
        notify(type): void {
            bridge.send('VKWebAppTapticNotificationOccurred', { type }).catch(() => {});
        },
    },

    // VK Bridge v3.0.2 does not provide a typed/supported external-open method
    openLink(url: string): void {
        window.open(url, '_blank', 'noopener,noreferrer');
    },
};
