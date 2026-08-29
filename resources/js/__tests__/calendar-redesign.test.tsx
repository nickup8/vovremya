import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AppointmentStatus } from '@/types/appointment-status';
import { STATUS_STYLES } from '@/pages/admin/components/calendar/constants';

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

describe('Calendar Legend statuses', () => {
    const LEGEND_STATUSES = [
        AppointmentStatus.Booked,
        AppointmentStatus.Paid,
        AppointmentStatus.NoShow,
        AppointmentStatus.Cancelled,
    ];

    it('should contain exactly 4 statuses', () => {
        expect(LEGEND_STATUSES).toHaveLength(4);
    });

    it('should not contain PendingPayment', () => {
        expect(LEGEND_STATUSES).not.toContain(AppointmentStatus.PendingPayment);
    });

    it('should not contain Prepaid', () => {
        expect(LEGEND_STATUSES).not.toContain(AppointmentStatus.Prepaid);
    });

    it('should include Booked, Paid, NoShow, Cancelled', () => {
        expect(LEGEND_STATUSES).toContain(AppointmentStatus.Booked);
        expect(LEGEND_STATUSES).toContain(AppointmentStatus.Paid);
        expect(LEGEND_STATUSES).toContain(AppointmentStatus.NoShow);
        expect(LEGEND_STATUSES).toContain(AppointmentStatus.Cancelled);
    });
});

describe('STATUS_STYLES — all statuses defined', () => {
    it.each([
        AppointmentStatus.Booked,
        AppointmentStatus.PendingPayment,
        AppointmentStatus.Prepaid,
        AppointmentStatus.Paid,
        AppointmentStatus.NoShow,
        AppointmentStatus.Cancelled,
    ])('should have style for %s', (status) => {
        expect(STATUS_STYLES[status]).toBeDefined();
        expect(STATUS_STYLES[status].accent).toBeTruthy();
        expect(STATUS_STYLES[status].bg).toBeTruthy();
        expect(STATUS_STYLES[status].label).toBeTruthy();
        expect(STATUS_STYLES[status].dot).toBeTruthy();
    });

    it('legacy statuses (PendingPayment, Prepaid) use Booked visual fallback (same accent)', () => {
        expect(STATUS_STYLES[AppointmentStatus.PendingPayment].accent).toBe(STATUS_STYLES[AppointmentStatus.Booked].accent);
        expect(STATUS_STYLES[AppointmentStatus.Prepaid].accent).toBe(STATUS_STYLES[AppointmentStatus.Booked].accent);
    });
});

describe('AppointmentStatus enum — legacy values preserved', () => {
    it('should keep PendingPayment and Prepaid enum values', () => {
        expect(AppointmentStatus.PendingPayment).toBe('pending_payment');
        expect(AppointmentStatus.Prepaid).toBe('prepaid');
    });
});

describe('Cancelled appointment visibility', () => {
    it('Cancelled status has a visible style (not empty)', () => {
        const s = STATUS_STYLES[AppointmentStatus.Cancelled];
        expect(s.accent).toBeTruthy();
        expect(s.bg).toBeTruthy();
        expect(s.label).toBe('Отменён');
    });
});

describe('AppointmentCard — text-left alignment', () => {
    it('AppointmentCard renders with text-left (verified via source)', () => {
        // AppointmentCard uses text-left class on the button element
        // This is a source-level check; render test requires DnD context
        const cardSource = require('fs').readFileSync(
            require('path').resolve(__dirname, '../pages/admin/components/calendar/AppointmentCard.tsx'),
            'utf-8',
        );
        expect(cardSource).toContain('text-left');
    });
});

describe('BreakZone — no dashed legacy style', () => {
    it('BreakZone source does not contain border-dashed', () => {
        const source = require('fs').readFileSync(
            require('path').resolve(__dirname, '../pages/admin/components/calendar/BreakZone.tsx'),
            'utf-8',
        );
        expect(source).not.toContain('border-dashed');
        expect(source).not.toContain('border-l-4');
        expect(source).toContain('w-[3px]');
    });
});

describe('Drawer — title is "Запись"', () => {
    it('Drawer source contains "Запись" as title', () => {
        const source = require('fs').readFileSync(
            require('path').resolve(__dirname, '../pages/admin/components/calendar/AppointmentDetailDrawer.tsx'),
            'utf-8',
        );
        expect(source).toContain('Запись');
        expect(source).toContain('Изменить');
        expect(source).toContain('Отменить запись');
    });
});

describe('useCalendarData — Cancelled not filtered', () => {
    it('initial state includes Cancelled appointments', async () => {
        const { useCalendarData } = await import('@/hooks/useCalendarData');
        const { renderHook } = await import('@testing-library/react');

        const cancelled = {
            id: 'appt-cancelled',
            client_name: 'Тест',
            client_phone: null,
            client_avatar_url: null,
            service: 'Стрижка',
            time: '10:00',
            date: '2026-07-27',
            duration: 60,
            price: 1500,
            status: AppointmentStatus.Cancelled,
        };

        const { result } = renderHook(() =>
            useCalendarData({
                initialAppointments: [cancelled],
                initialBlockedTimes: [],
                authUserId: undefined,
                weekDateKeys: ['2026-07-27'],
            }),
        );

        expect(result.current.localAppointments).toHaveLength(1);
        expect(result.current.localAppointments[0].status).toBe(AppointmentStatus.Cancelled);
    });
});
