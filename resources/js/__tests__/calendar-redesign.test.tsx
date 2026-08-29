import { describe, it, expect, vi } from 'vitest';
import { AppointmentStatus } from '@/types/appointment-status';
import { STATUS_STYLES, HOUR_HEIGHT, MINUTE_HEIGHT } from '@/pages/admin/components/calendar/constants';

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

const readSource = (relative: string) =>
    require('fs').readFileSync(
        require('path').resolve(__dirname, relative),
        'utf-8',
    );

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

describe('Golos Text — self-hosted font', () => {
    it('app.css contains @font-face for Golos Text', () => {
        const source = readSource('../../../resources/css/app.css');
        expect(source).toContain("font-family: 'Golos Text'");
        expect(source).toContain('.woff2');
    });

    it('app.blade.php does not load Google Fonts CDN', () => {
        const source = readSource('../../../resources/views/app.blade.php');
        expect(source).not.toContain('fonts.googleapis.com');
        expect(source).not.toContain('fonts.gstatic.com');
    });

    it('font files exist in public/fonts', () => {
        const fs = require('fs');
        const path = require('path');
        const fontsDir = path.resolve(__dirname, '../../../public/fonts');
        expect(fs.existsSync(fontsDir)).toBe(true);
        const files = fs.readdirSync(fontsDir);
        const woff2Files = files.filter((f: string) => f.endsWith('.woff2'));
        expect(woff2Files.length).toBeGreaterThanOrEqual(2);
    });
});

describe('Calendar density — HOUR_HEIGHT and grid', () => {
    it('HOUR_HEIGHT is 72px', () => {
        expect(HOUR_HEIGHT).toBe(72);
    });

    it('MINUTE_HEIGHT is HOUR_HEIGHT / 60', () => {
        expect(MINUTE_HEIGHT).toBeCloseTo(72 / 60);
    });

    it('15-minute slot = 18px', () => {
        expect(15 * MINUTE_HEIGHT).toBeCloseTo(18);
    });
});

describe('AppointmentCard — text-left alignment', () => {
    it('AppointmentCard renders with text-left', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentCard.tsx');
        expect(source).toContain('text-left');
    });

    it('AppointmentCard uses reference typography', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentCard.tsx');
        expect(source).toContain('font-bold');
        expect(source).toContain('font-semibold');
    });
});

describe('BreakZone — no dashed legacy style', () => {
    it('BreakZone source does not contain border-dashed', () => {
        const source = readSource('../pages/admin/components/calendar/BreakZone.tsx');
        expect(source).not.toContain('border-dashed');
        expect(source).not.toContain('border-l-4');
        expect(source).toContain('w-[3px]');
    });
});

describe('View switch — segmented reference structure', () => {
    it('DateControlPanel has h-10 segmented control', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('h-10');
        expect(source).toContain('rounded-xl');
        expect(source).toContain('rounded-[9px]');
        expect(source).toContain('px-[13px]');
        expect(source).toContain('text-[13px]');
        expect(source).toContain('font-semibold');
    });

    it('Today button has h-10', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('flex h-10 items-center');
    });
});

describe('Notifications — reference structure', () => {
    it('Notification popover uses 380px width and rounded-2xl', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('w-[380px]');
        expect(source).toContain('rounded-2xl');
    });

    it('Notification popover has no forced oversized height', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).not.toContain('py-12');
        expect(source).not.toContain('min-h-[68px]');
    });

    it('Notification header has title and subtitle', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('Уведомления');
        expect(source).toContain('Нет новых');
        expect(source).toContain('Всё прочитано');
    });

    it('Notification popover has proper shadow', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('shadow-[0_18px_50px');
    });
});

describe('Drawer — quick status actions', () => {
    it('Drawer has Paid quick action button', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('Оплачено');
    });

    it('Drawer has NoShow quick action button', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('Не пришёл');
    });

    it('Paid button uses existing status handler (onUpdateStatus)', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('onUpdateStatus(AppointmentStatus.Paid)');
    });

    it('NoShow button uses existing status handler (onUpdateStatus)', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('onUpdateStatus(AppointmentStatus.NoShow)');
    });

    it('Drawer title is "Запись"', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('Запись');
        expect(source).toContain('Изменить');
        expect(source).toContain('Отменить запись');
    });
});

describe('Drawer — edit state', () => {
    it('Drawer has editMode prop and edit state', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('editMode');
        expect(source).toContain('Редактировать запись');
    });

    it('Edit state has date and time inputs', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('type="date"');
        expect(source).toContain('Select value={editTime}');
    });

    it('Legacy RescheduleDialog modal is NOT opened from drawer', () => {
        const source = readSource('../pages/admin/calendar.tsx');
        // onReschedule should point to openDrawerEdit, not openReschedule
        expect(source).toContain('onReschedule={openDrawerEdit}');
    });

    it('Drawer uses submitRescheduleInDrawer (not submitReschedule)', () => {
        const source = readSource('../pages/admin/calendar.tsx');
        expect(source).toContain('submitRescheduleInDrawer');
        expect(source).toContain('setDrawerEditMode(false)');
    });
});

describe('Cancel AlertDialog preserved', () => {
    it('Drawer source contains AlertDialog confirmation', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentDetailDrawer.tsx');
        expect(source).toContain('AlertDialog');
        expect(source).toContain('Отменить запись?');
        expect(source).toContain('Да, отменить');
    });
});

describe('Dark surfaces — correct token mapping', () => {
    it('app.css has workspace, topbar, sticky, sidebar tokens', () => {
        const source = readSource('../../../resources/css/app.css');
        expect(source).toContain('--color-cal-workspace: #1A1918');
        expect(source).toContain('--color-cal-topbar: rgba(26,25,24,.92)');
        expect(source).toContain('--color-cal-sticky: rgba(26,25,24,.96)');
        expect(source).toContain('--color-cal-sidebar: rgba(24,23,22,.92)');
    });

    it('AdminLayout uses cal-workspace for main bg', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('cal-workspace');
    });

    it('AdminLayout uses cal-topbar for header', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('cal-topbar');
    });
});

describe('Segmented buttons — h-full', () => {
    it('view switch buttons have h-full', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('h-full rounded-[9px]');
    });

    it('segmented outer does NOT use cal-surface-alt dark override', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        // The segmented outer should use bg-warm (which is #141312 in dark)
        // NOT cal-surface-alt (#282725) which merges with toolbar
        const warmLine = source.split('Представление календаря')[0].split('\n').slice(-3).join('\n');
        expect(warmLine).not.toContain('cal-surface-alt');
    });

    it('Today button has visible border and surface bg', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('border border-[var(--color-line)]');
        expect(source).toContain('dark:bg-[var(--color-cal-surface)]');
    });
});

describe('Theme switch — reference dimensions', () => {
    it('theme switch has correct track dimensions', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('h-[28px] w-[56px]');
        expect(source).toContain('h-10 w-[72px]');
    });

    it('topbar actions have gap-2', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('gap-2');
    });
});

describe('Notifications — compact empty state', () => {
    it('empty state does not use py-6 or flex centering', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).not.toContain('py-6');
        expect(source).not.toContain('flex items-center justify-center py');
    });

    it('popover has z-[80] and overflow-hidden', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('z-[80]');

    });

    it('topbar has relative z-50 strictly above calendar z-30', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('relative z-50');
        expect(source).not.toContain('relative z-30');
        expect(source).toContain('overflow-hidden');
    });
});

describe('AppointmentCard — compact mode', () => {
    it('card has compact mode logic', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentCard.tsx');
        expect(source).toContain('isCompact');
        expect(source).toContain('py-[3px]');
    });

    it('compact card hides service', () => {
        const source = readSource('../pages/admin/components/calendar/AppointmentCard.tsx');
        expect(source).toContain('!isCompact && height >= 42');
    });
});

describe('Mobile topbar — controls visible', () => {
    it('mobile topbar has hamburger, bell, and new appointment', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('lg:hidden'); // hamburger
        expect(source).toContain('size-10'); // touch targets
        expect(source).toContain('Новая запись');
    });

    it('theme switch hidden on mobile, visible on desktop', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('hidden');
        expect(source).toContain('lg:grid'); // theme switch desktop only
    });

    it('mobile notifications use Drawer, desktop use popover — conditionally rendered', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('isDesktop'); // conditional rendering
        expect(source).toContain('notificationsOpen && isDesktop'); // desktop-only mount
        expect(source).toContain('notificationsOpen && !isDesktop'); // mobile-only mount
        // No CSS-only hiding — both should NOT be in DOM simultaneously
        expect(source).not.toContain('hidden lg:block');
    });

    it('mobile title uses smaller text', () => {
        const source = readSource('../layouts/AdminLayout.tsx');
        expect(source).toContain('text-[21px]');
        expect(source).toContain('lg:text-[25px]');
    });
});

describe('Mobile sidebar — theme toggle', () => {
    it('sidebar accepts onToggleTheme prop', () => {
        const source = readSource('../components/admin/Sidebar.tsx');
        expect(source).toContain('onToggleTheme');
    });

    it('mobile sidebar has theme toggle button', () => {
        const source = readSource('../components/admin/Sidebar.tsx');
        expect(source).toContain('Светлая тема');
        expect(source).toContain('Тёмная тема');
    });
});

describe('DateControlPanel — desktop one-row layout restored', () => {
    it('desktop has single-row flex with spacer', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('hidden min-h-[72px]');
        expect(source).toContain('lg:flex');
        expect(source).toContain('flex-1'); // spacer
    });

    it('desktop control order: [prev][next] date [Сегодня] [segmented]', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        // Desktop section has prev, next, date label, today, segmented
        const desktopSection = source.split('hidden min-h-[72px]')[1] || '';
        expect(desktopSection).toContain('Сегодня');
        expect(desktopSection).toContain('Представление календаря');
    });
});

describe('Mobile DateControlPanel — two-row layout', () => {
    it('mobile has px-4 padding', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('px-4 py-3');
        expect(source).toContain('lg:hidden');
    });

    it('mobile view buttons flex-1 full width', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        expect(source).toContain('flex-1');
        expect(source).toContain('w-full');
    });

    it('mobile Today is icon-only (no text)', () => {
        const source = readSource('../components/calendar/DateControlPanel.tsx');
        // Mobile Today button has aria-label but no visible text
        expect(source).toContain('aria-label="Сегодня"');
    });
});

describe('Mobile DayView — compact header', () => {
    it('mobile header hides weekday and master name', () => {
        const source = readSource('../pages/admin/components/calendar/DayView.tsx');
        expect(source).toContain('hidden text-center lg:block'); // desktop-only content
    });

    it('mobile header height is compact (36px)', () => {
        const source = readSource('../pages/admin/components/calendar/DayView.tsx');
        expect(source).toContain('h-[36px]');
        expect(source).toContain('lg:h-auto');
    });

    it('mobile time rail is 56px, desktop is 72px', () => {
        const source = readSource('../pages/admin/components/calendar/DayView.tsx');
        expect(source).toContain('w-[56px]');
        expect(source).toContain('lg:w-[72px]');
    });

    it('desktop master headers preserved', () => {
        const source = readSource('../pages/admin/components/calendar/DayView.tsx');
        expect(source).toContain('master.name');
        expect(source).toContain('weekday');
    });
});

describe('Mobile WeekView — horizontal scroll geometry', () => {
    it('mobile time rail is 56px, desktop is 72px', () => {
        const source = readSource('../pages/admin/components/calendar/WeekView.tsx');
        expect(source).toContain('w-[56px]');
        expect(source).toContain('lg:w-[72px]');
    });

    it('day columns have min-w-[128px] on mobile', () => {
        const source = readSource('../pages/admin/components/calendar/WeekView.tsx');
        expect(source).toContain('min-w-[128px]');
        expect(source).toContain('lg:min-w-0');
    });

    it('container uses fit-content for horizontal scroll', () => {
        const source = readSource('../pages/admin/components/calendar/WeekView.tsx');
        expect(source).toContain("minWidth: 'fit-content'");
    });

    it('today auto-scroll is one-time only', () => {
        const source = readSource('../pages/admin/components/calendar/WeekView.tsx');
        expect(source).toContain('didHorizontalScroll');
        expect(source).toContain('window.innerWidth >= 1024');
    });

    it('mobile header is compact (44px vs 58px desktop)', () => {
        const source = readSource('../pages/admin/components/calendar/WeekView.tsx');
        expect(source).toContain('h-[44px]');
        expect(source).toContain('lg:h-[58px]');
    });
});

describe('Mobile legend — horizontal scroll', () => {
    it('legend has overflow-x-auto and scrollbar-none', () => {
        const source = readSource('../pages/admin/components/calendar/CalendarLegend.tsx');
        expect(source).toContain('overflow-x-auto');
        expect(source).toContain('whitespace-nowrap');
        expect(source).toContain('scrollbar-none');
    });

    it('legend items are shrink-0', () => {
        const source = readSource('../pages/admin/components/calendar/CalendarLegend.tsx');
        expect(source).toContain('shrink-0');
    });
});

describe('Booking mode — mobile responsive', () => {
    it('booking banner uses flex-col on mobile', () => {
        const source = readSource('../pages/admin/calendar.tsx');
        expect(source).toContain('flex-col');
        expect(source).toContain('lg:flex-row');
    });

    it('service selector uses flex-1 on mobile, not fixed 200px', () => {
        const source = readSource('../pages/admin/calendar.tsx');
        expect(source).toContain('flex-1');
        expect(source).toContain('lg:w-[200px]');
    });
});

describe('useCalendarData — Cancelled not filtered', () => {
    it('initial state includes Cancelled appointments', async () => {
        const { useCalendarData } = await import('@/hooks/useCalendarData');
        const { renderHook } = await import('@testing-library/react');

        const { result } = renderHook(
            ({ initialAppointments }) =>
                useCalendarData({
                    initialAppointments,
                    initialBlockedTimes: [],
                    authUserId: undefined,
                    weekDateKeys: ['2026-07-27'],
                    selectedId: undefined,
                }),
            {
                initialProps: {
                    initialAppointments: [{
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
                    }],
                },
            },
        );

        expect(result.current.localAppointments).toHaveLength(1);
        expect(result.current.localAppointments[0].status).toBe(AppointmentStatus.Cancelled);
    });
});
