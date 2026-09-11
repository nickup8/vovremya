import { getVkLaunchParams } from './vkBridge';
import { createMiniappApi } from '../../shared/api/client';

export const api = createMiniappApi(() => {
    const params = getVkLaunchParams();

    return params !== null
        ? { Authorization: `Bearer ${params}` }
        : null;
});
