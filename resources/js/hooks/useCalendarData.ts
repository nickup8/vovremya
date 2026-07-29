import { useState, useEffect, useRef } from 'react';
import { echo } from '@laravel/echo-react';
import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment, BlockedTime } from '@/pages/admin/components/calendar/types';

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

    // ═══════════════ Optimistic update state ═══════════════
    const pendingOptimisticIds = useRef<Set<string>>(new Set());
    const rollbackCache = useRef<Map<string, { date: string; time: string }>>(new Map());
    const wsAddedIds = useRef<Set<string>>(new Set());

    function applyOptimisticMove(id: string, newDate: string, newTime: string) {
        setLocalAppointments((prev) => {
            const appt = prev.find((a) => a.id === id);

            if (!appt) {
return prev;
}

            if (!pendingOptimisticIds.current.has(id)) {
                rollbackCache.current.set(id, { date: appt.date, time: appt.time });
            }

            pendingOptimisticIds.current.add(id);

            return prev.map((a) => (a.id === id ? { ...a, date: newDate, time: newTime } : a));
        });
    }

    function rollbackAppointment(id: string) {
        setLocalAppointments((prev) => {
            const old = rollbackCache.current.get(id);

            if (!old) {
                return prev;
            }

            pendingOptimisticIds.current.delete(id);
            rollbackCache.current.delete(id);

            return prev.map((a) => (a.id === id ? { ...a, date: old.date, time: old.time } : a));
        });
    }

    function confirmOptimistic(id: string) {
        pendingOptimisticIds.current.delete(id);
        rollbackCache.current.delete(id);
    }

    function clearOptimisticState() {
        pendingOptimisticIds.current.clear();
        rollbackCache.current.clear();
        wsAddedIds.current.clear();
    }

    // ═══════════════ Sync with Inertia props ═══════════════
    useEffect(() => {
        setLocalAppointments((prev) => {
            const incoming = initialAppointments.filter((a) => a.status !== AppointmentStatus.Cancelled);
            const incomingIds = new Set(incoming.map((a) => a.id));
            const wsAdded = prev.filter(
                (a) =>
                    wsAddedIds.current.has(a.id) &&
                    !incomingIds.has(a.id) &&
                    a.status !== AppointmentStatus.Cancelled,
            );
            incomingIds.forEach((id) => wsAddedIds.current.delete(id));

            const filteredIncoming = incoming.filter(
                (a) => !pendingOptimisticIds.current.has(a.id),
            );

            const keptOptimistic = prev.filter(
                (a) => incomingIds.has(a.id) && pendingOptimisticIds.current.has(a.id),
            );

            return [...filteredIncoming, ...keptOptimistic, ...wsAdded];
        });
    }, [initialAppointments]);

    // Cleanup on unmount
    useEffect(() => {
        return () => clearOptimisticState();
    }, []);

    // ═══════════════ WebSocket subscription ═══════════════
    useEffect(() => {
        if (!authUserId) {
return;
}

        const channelName = `App.Models.User.${authUserId}`;
        const channel = echo<'reverb'>().private(channelName)
            .listen('.AppointmentCreated', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (prev.some((a) => a.id === appointment.id)) {
return prev;
}

                    if (appointment.status === AppointmentStatus.Cancelled) {
return prev;
}

                    wsAddedIds.current.add(appointment.id);
                    return [...prev, appointment];
                });
            })
            .listen('.AppointmentStatusChanged', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        wsAddedIds.current.delete(appointment.id);
                        return prev.filter((a) => a.id !== appointment.id);
                    }

                    return prev.map((a) => (a.id === appointment.id ? appointment : a));
                });
            })
            .listen('.AppointmentRescheduled', (appointment: Appointment) => {
                setLocalAppointments((prev) => {
                    if (appointment.status === AppointmentStatus.Cancelled) {
                        wsAddedIds.current.delete(appointment.id);
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
        if (!selectedId || !onSelectedUpdate || !onSelectedExpired) {
return;
}

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
        setLocalAppointments,
        getAppointmentsForDay,
        getBlockedTimesForDay,
        applyOptimisticMove,
        rollbackAppointment,
        confirmOptimistic,
    };
}
