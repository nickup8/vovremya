import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { renderHook, act } from '@testing-library/react';
import React from 'react';
import { AppointmentDetailDrawer } from '@/pages/admin/components/calendar/AppointmentDetailDrawer';
import { mergeAppointmentUpdate } from '@/pages/admin/components/calendar/helpers';
import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment } from '@/pages/admin/components/calendar/types';

const echoState = vi.hoisted(() => {
    const listeners: Record<string, (payload: unknown) => void> = {};
    const channel = {
        listen: (event: string, cb: (payload: unknown) => void) => {
            listeners[event] = cb;

            return channel;
        },
        stopListening: vi.fn(() => channel),
    };

    return {
        listeners,
        channel,
        echo: () => ({ private: () => channel, leave: () => {} }),
    };
});

vi.mock('@/echo-config', () => ({ echo: echoState.echo }));

const MARK = '✅ Клиент подтвердил визит';

const baseAppointment: Appointment = {
    id: 'appt-1',
    client_name: 'Иван Тестов',
    client_phone: '+79991234567',
    client_avatar_url: null,
    service: 'Стрижка',
    time: '10:00',
    date: '2026-07-28',
    duration: 60,
    price: 1500,
    status: AppointmentStatus.Booked,
    client_confirmed_at: null,
    client_notifications: {
        status: 'sent',
        channel: 'max',
        event_type: 'visit_confirmed',
        event_label: 'Подтверждение визита',
        label: 'Доставлено',
        sent_at: '2026-07-27T12:00:00Z',
    },
    recurring_series_id: 'series-1',
    recurring_series: {
        id: 'series-1',
        start_time: '10:00',
        recurrence_type: 'weekly',
        interval: 1,
        weekdays: [1],
        ends_at: null,
        occurrences_count: 5,
        master_service_id: 'ms-1',
        status: 'active',
    },
};

const defaultProps = {
    open: true,
    onOpenChange: vi.fn(),
    selected: baseAppointment,
    isProcessing: false,
    onUpdateStatus: vi.fn(),
    onReschedule: vi.fn(),
    onDelete: vi.fn(),
};

function renderDrawer(overrides: Record<string, unknown> = {}) {
    return render(
        <AppointmentDetailDrawer
            {...defaultProps}
            {...overrides}
        />
    );
}

beforeEach(() => {
    for (const key of Object.keys(echoState.listeners)) {
        delete echoState.listeners[key];
    }
});

describe('AppointmentDetailDrawer — отметка подтверждения визита', () => {
    it('показывает отметку при непустом client_confirmed_at', () => {
        renderDrawer({
            selected: {
                ...baseAppointment,
                client_confirmed_at: '2026-07-27T12:00:00+00:00',
            },
        });

        expect(screen.getByText(MARK)).toBeInTheDocument();
    });

    it('не показывает отметку при client_confirmed_at = null', () => {
        renderDrawer({ selected: { ...baseAppointment, client_confirmed_at: null } });

        expect(screen.queryByText(MARK)).not.toBeInTheDocument();
    });

    it('не показывает отметку когда поле отсутствует', () => {
        const withoutField = { ...baseAppointment } as Partial<Appointment>;
        delete withoutField.client_confirmed_at;
        renderDrawer({ selected: withoutField as Appointment });

        expect(screen.queryByText(MARK)).not.toBeInTheDocument();
    });
});

describe('mergeAppointmentUpdate — неполный toCalendarArray', () => {
    it('сохраняет client_notifications и серию при отсутствии полей в incoming', () => {
        const incoming = {
            id: 'appt-1',
            client_name: 'Иван Тестов',
            service: 'Стрижка',
            time: '10:00',
            date: '2026-07-28',
            duration: 60,
            price: 1500,
            status: AppointmentStatus.Booked,
            client_confirmed_at: '2026-07-27T12:00:00+00:00',
            reminder_24h_sent_at: '2026-07-26T10:00:00+00:00',
        } as Partial<Appointment>;

        const merged = mergeAppointmentUpdate(baseAppointment, incoming);

        expect(merged.client_confirmed_at).toBe('2026-07-27T12:00:00+00:00');
        expect(merged.client_notifications).toEqual(baseAppointment.client_notifications);
        expect(merged.recurring_series).toEqual(baseAppointment.recurring_series);
        expect(merged.recurring_series_id).toBe('series-1');
        expect(merged.client_phone).toBe(baseAppointment.client_phone);
    });

    it('явный null перезаписывает прежнее значение client_confirmed_at', () => {
        const confirmed: Appointment = {
            ...baseAppointment,
            client_confirmed_at: '2026-07-27T12:00:00+00:00',
        };

        const incoming = {
            id: 'appt-1',
            date: '2026-07-29',
            time: '14:00',
            client_confirmed_at: null,
            reminder_24h_sent_at: null,
        } as Partial<Appointment>;

        const merged = mergeAppointmentUpdate(confirmed, incoming);

        expect(merged.client_confirmed_at).toBeNull();
        expect(merged.date).toBe('2026-07-29');
        expect(merged.time).toBe('14:00');
        expect(merged.client_notifications).toEqual(confirmed.client_notifications);
    });

    it('не трогает другие записи — чистая функция над одной записью', () => {
        const other: Appointment = { ...baseAppointment, id: 'appt-2' };
        const merged = mergeAppointmentUpdate(baseAppointment, {
            client_confirmed_at: '2026-07-27T12:00:00+00:00',
        });

        expect(merged.id).toBe('appt-1');
        expect(other.client_confirmed_at).toBeNull();
    });
});

describe('useCalendarData — WS-обновления без потери данных', () => {
    async function mountCalendar(initial: Appointment[], opts: {
        selectedId?: string;
        onSelectedUpdate?: (a: Appointment) => void;
    } = {}) {
        const { useCalendarData } = await import('@/hooks/useCalendarData');
        const onSelectedUpdate = opts.onSelectedUpdate ?? vi.fn();

        const { result } = renderHook(() =>
            useCalendarData({
                initialAppointments: initial,
                initialBlockedTimes: [],
                authUserId: 'master-1',
                weekDateKeys: ['2026-07-28'],
                selectedId: opts.selectedId,
                onSelectedUpdate,
                onSelectedExpired: vi.fn(),
            }),
        );

        return { result, onSelectedUpdate };
    }

    it('AppointmentVisitConfirmed обновляет нужную запись и открытую карточку', async () => {
        const other: Appointment = { ...baseAppointment, id: 'appt-other', client_confirmed_at: null };
        const onSelectedUpdate = vi.fn();
        const { result } = await mountCalendar([baseAppointment, other], {
            selectedId: 'appt-1',
            onSelectedUpdate,
        });

        expect(result.current.localAppointments[0].client_confirmed_at).toBeNull();

        act(() => {
            echoState.listeners['.AppointmentVisitConfirmed']?.({
                id: 'appt-1',
                client_name: baseAppointment.client_name,
                service: baseAppointment.service,
                time: baseAppointment.time,
                date: baseAppointment.date,
                duration: baseAppointment.duration,
                price: baseAppointment.price,
                status: AppointmentStatus.Booked,
                client_confirmed_at: '2026-07-27T12:00:00+00:00',
                reminder_24h_sent_at: null,
            });
        });

        const updated = result.current.localAppointments.find((a) => a.id === 'appt-1');
        expect(updated?.client_confirmed_at).toBe('2026-07-27T12:00:00+00:00');
        // Неполный payload не затирает client_notifications и серию
        expect(updated?.client_notifications).toEqual(baseAppointment.client_notifications);
        expect(updated?.recurring_series).toEqual(baseAppointment.recurring_series);

        // Другая запись не изменилась
        const untouched = result.current.localAppointments.find((a) => a.id === 'appt-other');
        expect(untouched?.client_confirmed_at).toBeNull();
        expect(untouched?.client_notifications).toEqual(baseAppointment.client_notifications);

        // Открытая карточка обновлена
        expect(onSelectedUpdate).toHaveBeenCalled();
        const selectedArg = onSelectedUpdate.mock.calls.at(-1)?.[0] as Appointment;
        expect(selectedArg.id).toBe('appt-1');
        expect(selectedArg.client_confirmed_at).toBe('2026-07-27T12:00:00+00:00');
        expect(selectedArg.client_notifications).toEqual(baseAppointment.client_notifications);
    });

    it('неполное AppointmentStatusChanged не теряет client_notifications и серию', async () => {
        const { result } = await mountCalendar([baseAppointment]);

        act(() => {
            echoState.listeners['.AppointmentStatusChanged']?.({
                id: 'appt-1',
                client_name: baseAppointment.client_name,
                service: baseAppointment.service,
                time: baseAppointment.time,
                date: baseAppointment.date,
                duration: baseAppointment.duration,
                price: baseAppointment.price,
                status: AppointmentStatus.Prepaid,
                client_confirmed_at: '2026-07-27T12:00:00+00:00',
                reminder_24h_sent_at: null,
                old_status: AppointmentStatus.Booked,
            });
        });

        const updated = result.current.localAppointments[0];
        expect(updated.status).toBe(AppointmentStatus.Prepaid);
        expect(updated.client_confirmed_at).toBe('2026-07-27T12:00:00+00:00');
        expect(updated.client_notifications).toEqual(baseAppointment.client_notifications);
        expect(updated.recurring_series).toEqual(baseAppointment.recurring_series);
    });

    it('явный null client_confirmed_at после переноса убирает отметку', async () => {
        const confirmed: Appointment = {
            ...baseAppointment,
            client_confirmed_at: '2026-07-27T12:00:00+00:00',
        };
        const onSelectedUpdate = vi.fn();
        const { result } = await mountCalendar([confirmed], {
            selectedId: 'appt-1',
            onSelectedUpdate,
        });

        expect(result.current.localAppointments[0].client_confirmed_at).toBeTruthy();

        act(() => {
            echoState.listeners['.AppointmentRescheduled']?.({
                id: 'appt-1',
                client_name: confirmed.client_name,
                service: confirmed.service,
                time: '14:00',
                date: '2026-07-29',
                duration: confirmed.duration,
                price: confirmed.price,
                status: AppointmentStatus.Booked,
                client_confirmed_at: null,
                reminder_24h_sent_at: null,
                old_date: '2026-07-28',
                old_time: '10:00',
            });
        });

        const updated = result.current.localAppointments[0];
        expect(updated.client_confirmed_at).toBeNull();
        expect(updated.date).toBe('2026-07-29');
        expect(updated.time).toBe('14:00');
        // Сводка уведомлений и серия переживают неполный payload переноса
        expect(updated.client_notifications).toEqual(confirmed.client_notifications);
        expect(updated.recurring_series).toEqual(confirmed.recurring_series);

        const selectedArg = onSelectedUpdate.mock.calls.at(-1)?.[0] as Appointment;
        expect(selectedArg.client_confirmed_at).toBeNull();
    });
});
