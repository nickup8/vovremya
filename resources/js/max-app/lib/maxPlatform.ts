import type { Platform } from '../../shared/platform/PlatformContext';
import { backButton, haptic, openLink } from './maxBridge';

export const maxPlatform: Platform = {
    appName: 'MAX',
    backButton,
    haptic,
    openLink,
};
