import { describe, it, expect, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { AppointmentStatus } from '@/types/appointment-status';

const mockAppointment = {
    id: 'appt-1',
    client_name: 'Иван',
    client_phone: '+7 (999) 123-45-67',
    client_avatar_url: null,
    service: 'Стрижка',
    time: '10:00',
    date: '2026-07-27',
    duration: 60,
    price: 1500,
    status: AppointmentStatus.Booked,
};

vi.mock('@/echo-config', () => ({
    echo: () => ({
        private: () => ({
            listen: () => ({
                stopListening: () => {},
            }),
        }),
        leave: () => {},
    }),
}));

describe('useCalendarData — optimistic reschedule', () => {
    it('should apply optimistic move', async () => {
        const { useCalendarData } = await import('@/hooks/useCalendarData');

        const { result } = renderHook(
            ({ initialAppointments }) =>
                useCalendarData({
                    initialAppointments,
                    initialBlockedTimes: [],
                    authUserId: undefined,
                    weekDateKeys: ['2026-07-27', '2026-07-28'],
                    selectedId: undefined,
                }),
            {
                initialProps: { initialAppointments: [mockAppointment] },
            },
        );

        expect(result.current.localAppointments).toHaveLength(1);
        expect(result.current.localAppointments[0].date).toBe('2026-07-27');

        act(() => {
            result.current.applyOptimisticMove('appt-1', '2026-07-28', '14:00');
        });

        expect(result.current.localAppointments[0].date).toBe('2026-07-28');
        expect(result.current.localAppointments[0].time).toBe('14:00');
    });

    it('should rollback on error', async () => {
        const { useCalendarData } = await import('@/hooks/useCalendarData');

        const { result } = renderHook(
            ({ initialAppointments }) =>
                useCalendarData({
                    initialAppointments,
                    initialBlockedTimes: [],
                    authUserId: undefined,
                    weekDateKeys: ['2026-07-27', '2026-07-28'],
                    selectedId: undefined,
                }),
            {
                initialProps: { initialAppointments: [mockAppointment] },
            },
        );

        act(() => {
            result.current.applyOptimisticMove('appt-1', '2026-07-28', '14:00');
        });

        act(() => {
            result.current.rollbackAppointment('appt-1');
        });

        expect(result.current.localAppointments[0].date).toBe('2026-07-27');
        expect(result.current.localAppointments[0].time).toBe('10:00');
    });
});
