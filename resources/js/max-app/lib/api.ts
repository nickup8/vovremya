import { getInitData } from './maxBridge';
import { createMiniappApi } from '../../shared/api/client';

export type { EarlierRequest, Appointment, Profile } from '../../shared/api/types';
export { UnauthorizedError } from '../../shared/api/types';

const api = createMiniappApi(() => {
    const initData = getInitData();

    return initData !== null
        ? { 'X-Max-Init-Data': initData }
        : null;
});

export const getAppointments = api.getAppointments;
export const getHistory = api.getHistory;
export const getProfile = api.getProfile;
export const cancelAppointment = api.cancelAppointment;
export const saveEarlierRequest = api.saveEarlierRequest;
export const cancelEarlierRequest = api.cancelEarlierRequest;
