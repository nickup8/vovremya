import type { Platform } from '../../shared/platform/PlatformContext';
import { backButton, haptic, openLink } from './maxBridge';

export const maxPlatform: Platform = {
    backButton,
    haptic,
    openLink,
};
