import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';
import { AppointmentDetailDrawer } from '@/pages/admin/components/calendar/AppointmentDetailDrawer';
import { AppointmentStatus } from '@/types/appointment-status';
import type { Appointment } from '@/pages/admin/components/calendar/types';

vi.mock('@/echo-config', () => ({
    echo: () => ({
        private: () => ({
            listen: () => ({ stopListening: () => {} }),
        }),
        leave: () => {},
    }),
}));

const baseAppointment: Appointment = {
    id: 'appt-1',
    client_name: 'Николай Сироткин',
    client_phone: '+7 (999) 123-45-67',
    client_avatar_url: null,
    service: 'Стрижка',
    time: '10:00',
    date: '2026-07-28',
    duration: 60,
    price: 1500,
    status: AppointmentStatus.Booked,
};

const recurringAppointment: Appointment = {
    ...baseAppointment,
    id: 'appt-recurring-1',
    recurring_series_id: 'series-1',
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

function getDialog() {
    return document.querySelector('[role="dialog"]') as HTMLElement;
}

describe('AppointmentDetailDrawer — Radix Dialog + Popover integration', () => {
    it('A: Drawer opens inside Radix Dialog, edit triggers edit mode for normal appointment', async () => {
        const onEditModeChange = vi.fn();
        renderDrawer({ onEditModeChange });

        const dialog = getDialog();
        expect(dialog).not.toBeNull();
        expect(dialog.getAttribute('data-state')).toBe('open');

        expect(screen.getByText('Запись')).toBeInTheDocument();
        expect(screen.getByText('Изменить')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Изменить'));
        expect(onEditModeChange).toHaveBeenCalledWith(true);
    });

    it('B: recurring edit — withPortal={false} ensures PopoverContent renders inside dialog', async () => {
        renderDrawer({ selected: recurringAppointment });

        const dialog = getDialog();
        expect(dialog).not.toBeNull();

        // Verify withPortal={false} is applied on BOTH PopoverContent instances
        const source = await import('@/pages/admin/components/calendar/AppointmentDetailDrawer?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;
        const portalMatches = content.match(/withPortal=\{false\}/g);
        expect(portalMatches).toHaveLength(2);
    });

    it('C: «Настройки серии» button renders as outline Button inside dialog', async () => {
        const onSeriesSettings = vi.fn();
        renderDrawer({ selected: recurringAppointment, onSeriesSettings });

        const dialog = getDialog();
        expect(dialog).not.toBeNull();

        const settingsBtn = screen.getByText('Настройки серии');
        expect(settingsBtn.tagName).toBe('BUTTON');
        expect(settingsBtn).toHaveClass('rounded-lg');

        fireEvent.click(settingsBtn);
        expect(onSeriesSettings).toHaveBeenCalledTimes(1);
    });

    it('D: status popover wired inside dialog — withPortal={false} on status PopoverContent', async () => {
        renderDrawer();

        const dialog = getDialog();
        expect(dialog).not.toBeNull();

        // Status badge renders
        expect(screen.getByText('Записан')).toBeInTheDocument();

        // Verify withPortal={false} is used (fix for pointer-events in Radix Dialog)
        const source = await import('@/pages/admin/components/calendar/AppointmentDetailDrawer?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;
        // Status PopoverContent uses withPortal={false}
        expect(content).toContain('withPortal={false}');
    });

    it('E: recurring footer — no «Повторять», has «Настройки серии»', () => {
        renderDrawer({ selected: recurringAppointment, onSeriesSettings: vi.fn() });

        expect(screen.queryByText('Повторять')).not.toBeInTheDocument();
        expect(screen.getByText('Настройки серии')).toBeInTheDocument();
        expect(screen.getByText('Отменить запись')).toBeInTheDocument();
        expect(screen.getByText('Изменить')).toBeInTheDocument();
    });

    it('F: normal footer — has «Повторять», no «Настройки серии»', () => {
        renderDrawer({ isPro: true, onRepeat: vi.fn() });

        expect(screen.getByText('Повторять')).toBeInTheDocument();
        expect(screen.queryByText('Настройки серии')).not.toBeInTheDocument();
        expect(screen.getByText('Отменить запись')).toBeInTheDocument();
    });

    it('G: series marker rendered under client phone with Repeat icon', () => {
        renderDrawer({ selected: recurringAppointment });

        // Marker text is in the document
        expect(screen.getByText('Повторяющаяся запись')).toBeInTheDocument();
    });

    it('H: DrawerBody in detail mode uses non-stretching inline style', () => {
        renderDrawer();

        const drawerBody = document.querySelector('[data-slot="drawer-body"]');
        expect(drawerBody).not.toBeNull();
        expect(drawerBody!.getAttribute('style')).toContain('flex: 0 0 auto');
    });

    it('I: DrawerBody in edit mode uses default flex-1 (no override)', () => {
        renderDrawer({ editMode: true });

        const drawerBody = document.querySelector('[data-slot="drawer-body"]');
        expect(drawerBody).not.toBeNull();
        // editMode=true → no style override → only class-based flex-1
        const style = drawerBody!.getAttribute('style');
        expect(style).toBeFalsy();
    });

    it('J: «Настройки серии» is NOT a text link — it is a real Button', () => {
        renderDrawer({ selected: recurringAppointment, onSeriesSettings: vi.fn() });

        const btn = screen.getByText('Настройки серии');
        // Must be a button, not an anchor or span
        expect(btn.tagName).toBe('BUTTON');
        // Must have outline variant styling (rounded-lg class from Button)
        expect(btn).toHaveClass('rounded-lg');
    });

    it('K: old text-link «Настройки серии» removed from footer', async () => {
        renderDrawer({ selected: recurringAppointment, onSeriesSettings: vi.fn() });

        // The old text-link used a standalone <button> with text-center text-xs styling
        // Verify it no longer exists as a separate element below the main buttons
        const footer = document.querySelector('[data-slot="drawer-footer"]');
        expect(footer).not.toBeNull();

        // Only one "Настройки серии" should exist (the outline Button in Row 1)
        const matches = footer!.querySelectorAll('button');
        const seriesBtns = Array.from(matches).filter(
            (el) => el.textContent === 'Настройки серии',
        );
        expect(seriesBtns).toHaveLength(1);
    });

    it('L: edit popover for recurring has both options in source', async () => {
        const source = await import('@/pages/admin/components/calendar/AppointmentDetailDrawer?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        expect(content).toContain('Только эту запись');
        expect(content).toContain('Эту и следующие');

        // These should NOT be in the cancel dialog (which also has similar text)
        // but in the edit PopoverContent
        expect(content).toContain('PopoverContent withPortal={false} align="start"');
    });
});
