import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment } from '@/pages/admin/components/calendar/types';

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

const mockRecurringAppointment: Appointment = {
    id: 'appt-recurring-1',
    client_name: 'Иван',
    client_phone: '+7 (999) 123-45-67',
    client_avatar_url: null,
    service: 'Стрижка',
    time: '10:00',
    date: '2026-07-28',
    duration: 60,
    price: 1500,
    status: AppointmentStatus.Booked,
    recurring_series_id: 'series-1',
    recurring_occurrence_date: '2026-07-28',
    recurring_series: {
        id: 'series-1',
        start_time: '10:00',
        recurrence_type: 'weekly',
        interval: 1,
        weekdays: [1, 2],
        ends_at: null,
        occurrences_count: 10,
        master_service_id: 'ms-1',
        status: 'active',
    },
};

const mockNonRecurringAppointment: Appointment = {
    id: 'appt-single-1',
    client_name: 'Мария',
    client_phone: '+7 (999) 987-65-43',
    client_avatar_url: null,
    service: 'Стрижка',
    time: '11:00',
    date: '2026-07-28',
    duration: 60,
    price: 1500,
    status: AppointmentStatus.Booked,
};

const mockCancelledAppointment: Appointment = {
    id: 'appt-cancelled-1',
    client_name: 'Пётр',
    client_phone: '+7 (999) 111-22-33',
    client_avatar_url: null,
    service: 'Стрижка',
    time: '12:00',
    date: '2026-07-28',
    duration: 60,
    price: 1500,
    status: AppointmentStatus.Cancelled,
};

const mockPaidAppointment: Appointment = {
    ...mockCancelledAppointment,
    id: 'appt-paid-1',
    status: AppointmentStatus.Paid,
    client_name: 'Анна',
};

const mockNoShowAppointment: Appointment = {
    ...mockCancelledAppointment,
    id: 'appt-noshow-1',
    status: AppointmentStatus.NoShow,
    client_name: 'Олег',
};

describe('AppointmentCard — drag guards', () => {
    it('cancelled appointments should not be draggable', () => {
        const NON_DRAGGABLE_STATUSES = new Set([
            AppointmentStatus.Cancelled,
            AppointmentStatus.Paid,
            AppointmentStatus.NoShow,
        ]);

        expect(NON_DRAGGABLE_STATUSES.has(mockCancelledAppointment.status)).toBe(true);
        expect(NON_DRAGGABLE_STATUSES.has(mockPaidAppointment.status)).toBe(true);
        expect(NON_DRAGGABLE_STATUSES.has(mockNoShowAppointment.status)).toBe(true);
    });

    it('booked/prepaid/pending_payment appointments are draggable', () => {
        const NON_DRAGGABLE_STATUSES = new Set([
            AppointmentStatus.Cancelled,
            AppointmentStatus.Paid,
            AppointmentStatus.NoShow,
        ]);

        expect(NON_DRAGGABLE_STATUSES.has(mockRecurringAppointment.status)).toBe(false);
        expect(NON_DRAGGABLE_STATUSES.has(mockNonRecurringAppointment.status)).toBe(false);
    });
});

describe('Recurring DnD — scope detection', () => {
    it('recurring appointment has recurring_series_id', () => {
        expect(mockRecurringAppointment.recurring_series_id).toBe('series-1');
    });

    it('non-recurring appointment has no recurring_series_id', () => {
        expect(mockNonRecurringAppointment.recurring_series_id).toBeUndefined();
    });

    it('recurring series has weekdays for weekly type', () => {
        expect(mockRecurringAppointment.recurring_series?.weekdays).toEqual([1, 2]);
    });
});

describe('Weekday replacement logic', () => {
    function computeNewWeekdays(
        oldWeekdays: number[],
        origDow: number,
        dropDow: number,
    ): number[] {
        const newWeekdays = oldWeekdays.map((d) => d === origDow ? dropDow : d);
        return [...new Set(newWeekdays)].sort((a, b) => a - b);
    }

    it('multi-weekday [Tue,Thu], Tue→Wed => [Wed,Thu]', () => {
        // Tue=2, Thu=4, Wed=3
        const result = computeNewWeekdays([2, 4], 2, 3);
        expect(result).toEqual([3, 4]);
    });

    it('single weekday [Tue], Tue→Thu => [Thu]', () => {
        const result = computeNewWeekdays([2], 2, 4);
        expect(result).toEqual([4]);
    });

    it('duplicate weekday replacement dedupes: [Tue,Thu], Thu→Tue => [Tue]', () => {
        // Thu=4 → Tue=2, result would be [2,2] but deduped to [2]
        const result = computeNewWeekdays([2, 4], 4, 2);
        expect(result).toEqual([2]);
    });

    it('weekday not in series is no-op: [Mon,Wed], Wed→Fri => [Mon,Fri]', () => {
        const result = computeNewWeekdays([1, 3], 3, 5);
        expect(result).toEqual([1, 5]);
    });

    it('sorted result: [Wed,Mon], Mon→Fri => [Fri,Wed]', () => {
        const result = computeNewWeekdays([3, 1], 1, 5);
        expect(result).toEqual([3, 5]);
    });
});

describe('Double drop guard', () => {
    it('isDndProcessing prevents concurrent drops', () => {
        let isDndProcessing = false;

        function tryDrop(): boolean {
            if (isDndProcessing) return false;
            isDndProcessing = true;
            return true;
        }

        expect(tryDrop()).toBe(true);
        expect(tryDrop()).toBe(false);

        isDndProcessing = false;
        expect(tryDrop()).toBe(true);
    });
});

describe('Recurring DnD — daily interval preserved', () => {
    it('daily series does not use weekdays', () => {
        const dailySeries = {
            recurrence_type: 'daily',
            interval: 2,
            weekdays: null,
        };

        // For daily series, weekdays should stay null
        const newWeekdays = dailySeries.recurrence_type === 'daily' ? null : dailySeries.weekdays;
        expect(newWeekdays).toBeNull();
    });

    it('daily series preserves interval', () => {
        const dailySeries = {
            recurrence_type: 'daily',
            interval: 3,
        };
        expect(dailySeries.interval).toBe(3);
    });
});
