import { useState, useEffect } from 'react';
import { echo } from '@laravel/echo-react';
import { AppointmentStatus } from '@/types/appointment-status';
import { Appointment, BlockedTime } from '@/pages/admin/components/calendar/types';

interface UseCalendarDataParams {
    initialAppointments: Appointment[];
    initialBlockedTimes: BlockedTime[];
    authUserId?: string;
    weekDateKeys: string[];
    selectedId?: string;
    onSelectedUpdate?: (appointment: Appointment) => void;
    onSelectedExpired?: () => void;
}

export function useCalendarData({
    initialAppointments,
    initialBlockedTimes,
    authUserId,
    weekDateKeys,
    selectedId,
    onSelectedUpdate,
    onSelectedExpired,
}: UseCalendarDataParams) {
    // ═══════════════ State ═══════════════
    const [localAppointments, setLocalAppointments] = useState<Appointment[]>(
        initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled),
    );

    // ═══════════════ Sync with Inertia props ═══════════════
    useEffect(() => {
        setLocalAppointments(
            initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled),
        );
    }, [initialAppointments]);

    // ═══════════════ WebSocket subscription ═══════════════
    useEffect(() => {
        if (!authUserId) return;

        const channelName = `App.Models.User.${authUserId}`;
        const channel = echo<'reverb'>().private(channelName)
            .listen('.AppointmentCreated', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (prev.some((a) => a.id === appointment.id)) return prev;
                    if (appointment.status === AppointmentStatus.Cancelled) return prev;
                    return [...prev, appointment];
                });
            })
            .listen('.AppointmentStatusChanged', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        return prev.filter((a) => a.id !== appointment.id);
                    }
                    return prev.map((a) => (a.id === appointment.id ? appointment : a));
                });
            })
            .listen('.AppointmentRescheduled', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        return prev.filter((a) => a.id !== appointment.id);
                    }
                    return prev.map((a) => (a.id === appointment.id ? appointment : a));
                });
            });

        return () => {
            channel.stopListening('.AppointmentCreated');
            channel.stopListening('.AppointmentStatusChanged');
            channel.stopListening('.AppointmentRescheduled');
            echo<'reverb'>().leave(channelName);
        };
    }, [authUserId]);

    // ═══════════════ Selected appointment sync ═══════════════
    useEffect(() => {
        if (!selectedId || !onSelectedUpdate || !onSelectedExpired) return;

        const updated = localAppointments.find((a) => a.id === selectedId);
        if (updated) {
            onSelectedUpdate(updated);
        } else {
            // Записи нет в localAppointments — проверяем не отменена ли она
            // Если selectedId передан, но записи нет в массиве — значит она была удалена/отменена через WS
            onSelectedExpired();
        }
    }, [localAppointments, selectedId]);

    // ═══════════════ Filters ═══════════════
    function getAppointmentsForDay(dayIndex: number): Appointment[] {
        const key = weekDateKeys[dayIndex];
        return localAppointments.filter((a) => a.date === key);
    }

    function getBlockedTimesForDay(dayIndex: number): BlockedTime[] {
        const key = weekDateKeys[dayIndex];
        return initialBlockedTimes.filter((bt) => {
            const endKey = bt.end_date ?? bt.date;
            return bt.date <= key && key <= endKey;
        });
    }

    // ═══════════════ Return ═══════════════
    return {
        localAppointments,
        getAppointmentsForDay,
        getBlockedTimesForDay,
    };
}
