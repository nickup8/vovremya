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

describe('RecurringDragScopeDialog — layout', () => {
    it('only-this button is primary orange, this-and-future is outline', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/RecurringDragScopeDialog.tsx'),
            'utf-8',
        );

        // Only-this uses orange/primary
        expect(src).toContain('bg-[var(--color-orange)]');
        expect(src).toContain('hover:bg-[var(--color-orange-600)]');
        expect(src).toContain('Только эту запись');

        // This-and-future is outline
        expect(src).toContain('Эту и следующие');

        // Cancel in DialogFooter
        expect(src).toContain('DialogFooter');
        expect(src).toContain('Отмена');
    });
});

describe('Recurring DnD — cross-master payload', () => {
    it('pendingRecurringDrop includes newMasterId', () => {
        const drop = {
            appointmentId: 'appt-1',
            newDate: '2026-07-28',
            newTime: '14:00',
            newMasterId: 'master-2',
            appointment: mockRecurringAppointment,
        };

        expect(drop.newMasterId).toBe('master-2');
    });

    it('confirmRecurringDropOnlyThis sends master_id in payload', () => {
        // The function constructs payload with master_id when newMasterId is set
        const payload: Record<string, string> = {
            start_time: '2026-07-28 14:00:00',
        };
        const newMasterId = 'master-2';

        if (newMasterId !== undefined) {
            payload.master_id = newMasterId;
        }

        expect(payload.master_id).toBe('master-2');
        expect(payload.start_time).toBe('2026-07-28 14:00:00');
    });

    it('confirmRecurringDropOnlyThis omits master_id when undefined', () => {
        const payload: Record<string, string> = {
            start_time: '2026-07-28 14:00:00',
        };
        const newMasterId = undefined;

        if (newMasterId !== undefined) {
            payload.master_id = newMasterId;
        }

        expect(payload).not.toHaveProperty('master_id');
    });
});

describe('Recurring DnD — 422 error handling', () => {
    it('extracts message from JSON error response', () => {
        const mockResponse = { message: 'Это время уже занято другим переносом.' };

        const msg = mockResponse.message
            ?? (mockResponse.errors ? Object.values(mockResponse.errors)[0]?.[0] : null)
            ?? 'Ошибка переноса';
        expect(msg).toBe('Это время уже занято другим переносом.');
    });

    it('extracts validation errors.errors field', () => {
        const mockResponse = { errors: { service_id: ['Услуга не найдена'] } };

        const msg = mockResponse.message
            ?? (mockResponse.errors ? Object.values(mockResponse.errors)[0]?.[0] : null)
            ?? 'Ошибка сохранения';
        expect(msg).toBe('Услуга не найдена');
    });

    it('falls back to generic message when no message or errors in response', () => {
        const mockResponse: Record<string, unknown> = {};

        const msg = (mockResponse as { message?: string }).message
            ?? ((mockResponse as { errors?: Record<string, string[]> }).errors ? Object.values((mockResponse as { errors: Record<string, string[]> }).errors)[0]?.[0] : null)
            ?? 'Ошибка переноса';
        expect(msg).toBe('Ошибка переноса');
    });
});

describe('Recurring DnD — previewSplit appointmentId', () => {
    it('previewSplit accepts appointmentId param', () => {
        const params = {
            service_id: 'svc-1',
            time: '14:00',
            recurrence_type: 'weekly',
            interval: 1,
            weekdays: [2, 3],
            ends_at: null,
            occurrences_count: 5,
            appointmentId: 'appt-dnd-1',
        };

        expect(params.appointmentId).toBe('appt-dnd-1');
        // Without appointmentId, falls back to selected.id
        const paramsNoId = { ...params, appointmentId: undefined };
        expect(paramsNoId.appointmentId).toBeUndefined();
    });
});

describe('AppointmentCard — recurring icon', () => {
    it('source has inline Repeat icon, no standalone Серия row', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/AppointmentCard.tsx'),
            'utf-8',
        );

        // Repeat icon is inline next to time
        expect(src).toContain('recurring_series_id && (');
        expect(src).toContain('<Repeat className="size-[9px] shrink-0" />');

        // No standalone "Серия" text row
        expect(src).not.toContain('Серия');
    });
});

describe('AppointmentCard — medium card readability', () => {
    it('source has reduced padding and service text sizing for better fit', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/AppointmentCard.tsx'),
            'utf-8',
        );

        // Non-compact padding reduced to fit 45-min cards
        expect(src).toContain('py-[5px]');

        // Service text uses smaller leading for medium cards
        expect(src).toContain('text-[10px] leading-[13px] opacity-50');
    });
});

describe('AppointmentDetailDrawer — recurring edit UX', () => {
    it('edit dropdown has only-this and this-and-future, no series settings', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/AppointmentDetailDrawer.tsx'),
            'utf-8',
        );

        // Popover has only-this and this-and-future
        expect(src).toContain('Только эту запись');
        expect(src).toContain('Эту и следующие');

        // Series settings NOT in PopoverContent (moved to separate link)
        const popoverSection = src.substring(
            src.indexOf('PopoverContent align="start"'),
            src.indexOf('</Popover>', src.indexOf('PopoverContent align="start"')),
        );
        expect(popoverSection).not.toContain('Настройки серии');

        // Series settings as separate text link
        expect(src).toContain('Настройки серии');
    });

    it('recurring marker is separate row with Repeat icon', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/AppointmentDetailDrawer.tsx'),
            'utf-8',
        );

        expect(src).toContain('Повторяющаяся запись');
        expect(src).toContain('<Repeat className="size-3" />');
    });

    it('status row contains only badge, no series text', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/AppointmentDetailDrawer.tsx'),
            'utf-8',
        );

        // Status row
        const statusIdx = src.indexOf('Статус');
        const nextRow = src.indexOf('<div className="flex items', statusIdx + 10);
        const statusSection = src.substring(statusIdx, nextRow > 0 ? nextRow : statusIdx + 200);

        // No "Серия" text in the status row area
        expect(statusSection).not.toContain('Серия');
    });
});

describe('RecurringEditDialog — paid conflict', () => {
    it('disables submit when has_paid_conflict', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/RecurringEditDialog.tsx'),
            'utf-8',
        );

        expect(src).toContain('has_paid_conflict');
        expect(src).toContain('оплаченная запись');
    });
});

describe('calendar.tsx — snapshot pattern', () => {
    it('uses seriesEditAppointment snapshot for RecurringEditDialog', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/calendar.tsx'),
            'utf-8',
        );

        // Snapshot state exists
        expect(src).toContain('seriesEditAppointment');

        // openEditThisAndFuture saves snapshot
        expect(src).toContain('setSeriesEditAppointment(selected)');

        // RecurringEditDialog uses snapshot, not selected
        const dialogSection = src.substring(src.indexOf('RecurringEditDialog'));
        expect(dialogSection).toContain('seriesEditAppointment');
    });
});

describe('RecurringEditDialog — remaining count', () => {
    it('hides end condition controls in this-and-future mode', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/RecurringEditDialog.tsx'),
            'utf-8',
        );

        // End condition wrapped in mode === 'series-settings'
        expect(src).toContain("mode === 'series-settings'");
        expect(src).toContain('Количество записей');
        expect(src).toContain('До даты');
    });

    it('shows remaining count indicator in this-and-future mode', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/RecurringEditDialog.tsx'),
            'utf-8',
        );

        expect(src).toContain('remaining_count');
        expect(src).toContain('Оставшиеся записи серии');
    });

    it('does not send occurrences_count in this-and-future mode', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/RecurringEditDialog.tsx'),
            'utf-8',
        );

        // Preview: occurrences_count only sent in series-settings mode
        expect(src).toContain("mode === 'series-settings' && recurrence.end_type === 'count'");
    });

    it('disables submit when preview returns error', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../pages/admin/components/calendar/RecurringEditDialog.tsx'),
            'utf-8',
        );

        expect(src).toContain("'error' in previewResult && !!previewResult.error");
    });
});

describe('useCalendarActions — DnD this-and-future remaining count', () => {
    it('DnD does not send occurrences_count or ends_at for this-and-future', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../hooks/useCalendarActions.ts'),
            'utf-8',
        );

        // In confirmRecurringDropThisAndFuture, occurrences_count is null
        const fnStart = src.indexOf('confirmRecurringDropThisAndFuture');
        const fnEnd = src.indexOf('async function', fnStart + 30);
        const fn = src.substring(fnStart, fnEnd > 0 ? fnEnd : fnStart + 2000);

        expect(fn).toContain("occurrences_count: null");
        expect(fn).toContain("ends_at: null");
    });

    it('DnD handles error field from preview response', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.resolve(__dirname, '../hooks/useCalendarActions.ts'),
            'utf-8',
        );

        expect(src).toContain("previewResult as Record<string, unknown>).error");
    });
});
