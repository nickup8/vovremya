import { useState, useMemo } from 'react';
import { router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment, ClientOption, MasterOption, ServiceOption } from '@/pages/admin/components/calendar/types';
import { dateToKey } from '@/pages/admin/components/calendar/helpers';

interface UseCalendarActionsParams {
    clients: ClientOption[];
    services: ServiceOption[];
    slotInterval: number;
    timezone: string;
    prefillClientId?: string;
    selected: Appointment | null;
    setSelected: React.Dispatch<React.SetStateAction<Appointment | null>>;
    sheetOpen: boolean;
    setSheetOpen: React.Dispatch<React.SetStateAction<boolean>>;
    applyOptimisticMove: (id: string, newDate: string, newTime: string) => void;
    rollbackAppointment: (id: string) => void;
    confirmOptimistic: (id: string) => void;
    localAppointments: Appointment[];
    masters: MasterOption[];
    selectedMasterId: string;
}

export function useCalendarActions({
    clients,
    services,
    slotInterval,
    timezone,
    prefillClientId,
    selected,
    setSelected,
    sheetOpen,
    setSheetOpen,
    applyOptimisticMove,
    rollbackAppointment,
    confirmOptimistic,
    localAppointments,
    masters,
    selectedMasterId,
}: UseCalendarActionsParams) {
    const [isProcessing, setIsProcessing] = useState(false);
    const [newAppointmentOpen, setNewAppointmentOpen] = useState(false);
    const [rescheduleOpen, setRescheduleOpen] = useState(false);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleTime, setRescheduleTime] = useState('');
    const [bookingModeServiceId, setBookingModeServiceId] = useState<string>('');
    const [hoveredSlot, setHoveredSlot] = useState<{ date: string; time: string } | null>(null);
    const [preselectedMasterId, setPreselectedMasterId] = useState<string | null>(null);

    // Warning dialog states
    const [breakWarningOpen, setBreakWarningOpen] = useState(false);
    const [breakWarningMessage, setBreakWarningMessage] = useState('');
    const [pendingReschedule, setPendingReschedule] = useState<{ appointmentId: string; date: string; time: string } | null>(null);
    const [outsideHoursOpen, setOutsideHoursOpen] = useState(false);
    const [outsideHoursMessage, setOutsideHoursMessage] = useState('');
    const [pendingOutsideHours, setPendingOutsideHours] = useState<{ appointmentId?: string; date: string; time: string } | null>(null);

    const newAppointmentForm = useForm({
        client_id: '',
        service_id: '',
        date: '',
        time: '',
        ignore_warnings: false,
        confirm_outside_hours: false,
    });

    // ═══════════════ Computed ═══════════════
    const activeBookingClient = prefillClientId
        ? clients.find((c) => String(c.id) === prefillClientId) ?? null
        : null;

    const bookingModeService = bookingModeServiceId
        ? services.find((s) => String(s.id) === bookingModeServiceId) ?? null
        : null;

    function generateTimeOptions(interval: number, dateStr: string, tz: string): string[] {
        const options: string[] = [];
        const now = new Date(new Date().toLocaleString('en-US', { timeZone: tz }));
        const todayStr = dateToKey(now);
        const isToday = dateStr === todayStr;

        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += interval) {
                const timeStr = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;

                if (isToday) {
                    const slotDate = new Date(`${dateStr}T${timeStr}:00`);
                    const slotInTz = new Date(slotDate.toLocaleString('en-US', { timeZone: tz }));

                    if (slotInTz < now) {
continue;
}
                }

                options.push(timeStr);
            }
        }

        return options;
    }

    const timeOptions = useMemo(
        () => generateTimeOptions(slotInterval, rescheduleDate || dateToKey(new Date()), timezone),
        [slotInterval, rescheduleDate, timezone],
    );

    // ═══════════════ Booking Mode ═══════════════
    function cancelBookingMode() {
        setBookingModeServiceId('');
        setHoveredSlot(null);
        router.visit('/admin/calendar', { replace: true, only: [] });
    }

    function clearBookingMode() {
        setPreselectedMasterId(null);
        setBookingModeServiceId('');
        setHoveredSlot(null);

        if (prefillClientId) {
            window.history.replaceState({}, '', '/admin/calendar');
        }
    }

    // ═══════════════ CRUD: Update / Delete ═══════════════
    function updateStatus(status: AppointmentStatus) {
        if (!selected || isProcessing) {
return;
}

        setIsProcessing(true);
        router.patch(`/admin/appointments/${selected.id}/status`, { status }, {
            preserveScroll: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.status) {
                    toast.error(errors.status);
                }
            },
            onFinish: () => {
                setIsProcessing(false);
                setSheetOpen(false);
                setSelected(null);
            },
        });
    }

    function deleteAppointment() {
        if (!selected || isProcessing) {
return;
}

        setIsProcessing(true);
        router.patch(`/admin/appointments/${selected.id}/status`, { status: AppointmentStatus.Cancelled }, {
            preserveScroll: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.status) {
                    toast.error(errors.status);
                }
            },
            onFinish: () => {
                setIsProcessing(false);
                setSheetOpen(false);
                setSelected(null);
            },
        });
    }

    // ═══════════════ CRUD: Reschedule ═══════════════
    function openReschedule() {
        if (!selected) {
return;
}

        setRescheduleDate(selected.date);
        setRescheduleTime(selected.time);
        setRescheduleOpen(true);
        setSheetOpen(false);
    }

    function submitReschedule() {
        if (!selected || !rescheduleDate || !rescheduleTime) {
return;
}

        const apptId = selected.id;
        const prevDate = selected.date;
        const prevTime = selected.time;
        applyOptimisticMove(apptId, rescheduleDate, rescheduleTime);

        router.patch(`/admin/appointments/${apptId}/status`, {
            start_time: `${rescheduleDate} ${rescheduleTime}:00`,
        }, {
            preserveScroll: true,
            preserveState: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.lunch_intersection) {
                    setBreakWarningMessage(errors.lunch_intersection);
                    setPendingReschedule({ appointmentId: apptId, date: rescheduleDate, time: rescheduleTime });
                    setBreakWarningOpen(true);

                    return;
                }

                if (errors.outside_working_hours) {
                    setOutsideHoursMessage(errors.outside_working_hours);
                    setPendingOutsideHours({ appointmentId: apptId, date: rescheduleDate, time: rescheduleTime });
                    setOutsideHoursOpen(true);

                    return;
                }

                rollbackAppointment(apptId);

                if (errors.time) {
                    toast.error(errors.time);
                }
            },
            onSuccess: () => {
                confirmOptimistic(apptId);
                toast.success('Запись перенесена', {
                    action: {
                        label: 'Отменить',
                        onClick: () => rescheduleByDrag(apptId, prevDate, prevTime, undefined, true),
                    },
                });
            },
            onFinish: () => {
                setRescheduleOpen(false);
                setSelected(null);
            },
        });
    }

    // ═══════════════ Warning Dialogs ═══════════════
    function submitNewAppointmentIgnoreBreak() {
        if (!newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time) {
return;
}

        newAppointmentForm.setData('ignore_warnings', true);
        newAppointmentForm.post('/admin/calendar/appointments', {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                if (errors.limit) {
                    toast.error(errors.limit, { duration: 5000 });
                }

                if (errors.time) {
                    toast.error(errors.time);
                }

                if (errors.client_id) {
                    toast.error(errors.client_id);
                }
            },
            onSuccess: () => {
                setNewAppointmentOpen(false);
                setBreakWarningOpen(false);
                newAppointmentForm.reset();
                clearBookingMode();
            },
        });
    }

    function submitNewAppointmentConfirmOutside() {
        if (!newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time) {
return;
}

        newAppointmentForm.setData('confirm_outside_hours', true);
        newAppointmentForm.post('/admin/calendar/appointments', {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                if (errors.limit) {
                    toast.error(errors.limit, { duration: 5000 });
                }

                if (errors.time) {
                    toast.error(errors.time);
                }

                if (errors.client_id) {
                    toast.error(errors.client_id);
                }
            },
            onSuccess: () => {
                setNewAppointmentOpen(false);
                setOutsideHoursOpen(false);
                newAppointmentForm.reset();
                clearBookingMode();
            },
        });
    }

    function confirmRescheduleWithBreak() {
        if (pendingReschedule) {
            const apptId = pendingReschedule.appointmentId;
            applyOptimisticMove(apptId, pendingReschedule.date, pendingReschedule.time);

            router.patch(`/admin/appointments/${apptId}/status`, {
                start_time: `${pendingReschedule.date} ${pendingReschedule.time}:00`,
                ignore_warnings: true,
            }, {
                preserveScroll: true,
                preserveState: true,
                only: ['appointments'],
                onError: (errors: Record<string, string>) => {
                    rollbackAppointment(apptId);

                    if (errors.time) {
                        toast.error(errors.time);
                    }
                },
                onSuccess: () => {
                    confirmOptimistic(apptId);
                },
            });

            setBreakWarningOpen(false);
            setPendingReschedule(null);
            setBreakWarningMessage('');
        } else if (newAppointmentOpen) {
            submitNewAppointmentIgnoreBreak();
        }
    }

    function cancelReschedule() {
        if (pendingReschedule) {
            rollbackAppointment(pendingReschedule.appointmentId);
        }

        setBreakWarningOpen(false);
        setPendingReschedule(null);
        setBreakWarningMessage('');
    }

    function confirmOutsideHours() {
        if (pendingOutsideHours?.appointmentId) {
            const apptId = pendingOutsideHours.appointmentId;
            applyOptimisticMove(apptId, pendingOutsideHours.date, pendingOutsideHours.time);

            router.patch(`/admin/appointments/${apptId}/status`, {
                start_time: `${pendingOutsideHours.date} ${pendingOutsideHours.time}:00`,
                confirm_outside_hours: true,
            }, {
                preserveScroll: true,
                preserveState: true,
                only: ['appointments'],
                onError: (errors: Record<string, string>) => {
                    rollbackAppointment(apptId);

                    if (errors.time) {
                        toast.error(errors.time);
                    }
                },
                onSuccess: () => {
                    confirmOptimistic(apptId);
                },
            });

            setOutsideHoursOpen(false);
            setPendingOutsideHours(null);
            setOutsideHoursMessage('');
        } else if (newAppointmentOpen) {
            submitNewAppointmentConfirmOutside();
        }
    }

    function cancelOutsideHours() {
        if (pendingOutsideHours?.appointmentId) {
            rollbackAppointment(pendingOutsideHours.appointmentId);
        }

        setOutsideHoursOpen(false);
        setPendingOutsideHours(null);
        setOutsideHoursMessage('');
    }

    // ═══════════════ CRUD: New Appointment ═══════════════
    function openNewAppointment() {
        setPreselectedMasterId(selectedMasterId !== 'all' ? selectedMasterId : null);
        newAppointmentForm.reset();
        newAppointmentForm.setData('date', dateToKey(new Date()));
        newAppointmentForm.setData('time', '09:00');
        setNewAppointmentOpen(true);
    }

    function openNewAppointmentForDate(dateKey: string, time?: string, masterId?: string) {
        if (activeBookingClient && !bookingModeServiceId) {
            return;
        }

        setPreselectedMasterId(masterId ?? null);
        newAppointmentForm.reset();
        newAppointmentForm.setData('date', dateKey);
        newAppointmentForm.setData('time', time || '09:00');

        if (activeBookingClient) {
            newAppointmentForm.setData('client_id', activeBookingClient.id);
        }

        if (bookingModeServiceId) {
            newAppointmentForm.setData('service_id', bookingModeServiceId);
        }

        setNewAppointmentOpen(true);
    }

    function submitNewAppointment(e: React.FormEvent) {
        e.preventDefault();

        if (!newAppointmentForm.data.client_id || !newAppointmentForm.data.service_id || !newAppointmentForm.data.date || !newAppointmentForm.data.time) {
return;
}

        newAppointmentForm.post('/admin/calendar/appointments', {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                if (errors.limit) {
                    toast.error(errors.limit, { duration: 5000 });
                }

                if (errors.lunch_intersection) {
                    setBreakWarningMessage(errors.lunch_intersection);
                    setPendingReschedule(null);
                    setBreakWarningOpen(true);
                }

                if (errors.outside_working_hours) {
                    setOutsideHoursMessage(errors.outside_working_hours);
                    setPendingOutsideHours(null);
                    setOutsideHoursOpen(true);
                }

                if (errors.time) {
                    toast.error(errors.time);
                }

                if (errors.client_id) {
                    toast.error(errors.client_id);
                }
            },
            onSuccess: () => {
                setNewAppointmentOpen(false);
                newAppointmentForm.reset();
                clearBookingMode();
            },
        });
    }

    // ═══════════════ Drag & Drop Reschedule ═══════════════
    function rescheduleByDrag(
        apptId: string,
        newDate: string,
        newTime: string,
        newMasterId?: string,
        isUndo = false,
        prevMasterIdArg?: string,
    ) {
        const prev = localAppointments.find((a) => a.id === apptId);
        const prevDate = prev?.date ?? newDate;
        const prevTime = prev?.time ?? newTime;
        const prevMasterId = prevMasterIdArg
            ?? (prev?.master_id != null ? String(prev.master_id) : undefined);

        applyOptimisticMove(apptId, newDate, newTime);

        const payload: Record<string, string> = {
            start_time: `${newDate} ${newTime}:00`,
        };

        if (newMasterId !== undefined) {
            payload.master_id = newMasterId;
        }

        router.patch(`/admin/appointments/${apptId}/status`, payload, {
            preserveScroll: true,
            preserveState: true,
            only: ['appointments'],
            onError: (errors: Record<string, string>) => {
                if (errors.lunch_intersection) {
                    setBreakWarningMessage(errors.lunch_intersection);
                    setPendingReschedule({ appointmentId: apptId, date: newDate, time: newTime });
                    setBreakWarningOpen(true);

                    return;
                }

                if (errors.outside_working_hours) {
                    setOutsideHoursMessage(errors.outside_working_hours);
                    setPendingOutsideHours({ appointmentId: apptId, date: newDate, time: newTime });
                    setOutsideHoursOpen(true);

                    return;
                }

                rollbackAppointment(apptId);

                if (errors.time) {
                    toast.error(errors.time);
                }
            },
            onSuccess: () => {
                confirmOptimistic(apptId);

                if (isUndo) {
                    toast.success('Перенос отменён');
                } else {
                    let msg = 'Запись перенесена';

                    if (newMasterId !== undefined) {
                        const m = masters.find((x) => String(x.id) === String(newMasterId));

                        if (m) {
                            msg = `Запись перенесена к ${m.name}`;
                        }
                    }

                    toast.success(msg, {
                        action: {
                            label: 'Отменить',
                            onClick: () => rescheduleByDrag(apptId, prevDate, prevTime, prevMasterId, true, prevMasterId),
                        },
                    });
                }
            },
        });
    }

    // ═══════════════ Detail ═══════════════
    function openDetail(appointment: Appointment) {
        setSelected(appointment);
        setSheetOpen(true);
    }

    // ═══════════════ Return ═══════════════
    return {
        // State
        selected, setSelected,
        sheetOpen, setSheetOpen,
        isProcessing,
        newAppointmentOpen, setNewAppointmentOpen,
        rescheduleOpen, setRescheduleOpen,
        rescheduleDate, setRescheduleDate,
        rescheduleTime, setRescheduleTime,
        bookingModeServiceId, setBookingModeServiceId,
        hoveredSlot, setHoveredSlot,
        breakWarningOpen, setBreakWarningOpen,
        breakWarningMessage,
        outsideHoursOpen, setOutsideHoursOpen,
        outsideHoursMessage,
        newAppointmentForm,
        timeOptions,

        // Computed
        activeBookingClient,
        bookingModeService,
        preselectedMasterId,

        // Actions
        updateStatus,
        deleteAppointment,
        openReschedule,
        submitReschedule,
        confirmRescheduleWithBreak,
        cancelReschedule,
        confirmOutsideHours,
        cancelOutsideHours,
        rescheduleByDrag,
        openNewAppointment,
        openNewAppointmentForDate,
        submitNewAppointment,
        openDetail,
        cancelBookingMode,
        clearBookingMode,
    };
}
