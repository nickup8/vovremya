import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { useAvailableSlots } from '@/hooks/useAvailableSlots';

// ─── useAvailableSlots hook tests ────────────────────────────

describe('useAvailableSlots', () => {
    const mockSlots = { freeSlots: ['10:00', '10:30', '11:00'], outsideSlots: ['08:00', '18:30'] };

    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('fetches slots with date only (no service_id)', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            json: () => Promise.resolve(mockSlots),
        });

        const { result } = renderHook(() => useAvailableSlots('2026-07-28', undefined));

        expect(result.current.loading).toBe(true);

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(result.current.slots.freeSlots).toEqual(['10:00', '10:30', '11:00']);
        expect(result.current.slots.outsideSlots).toEqual(['08:00', '18:30']);
        expect(fetch).toHaveBeenCalledWith(
            '/admin/calendar/available-slots?date=2026-07-28',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
    });

    it('fetches slots with date + service_id', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            json: () => Promise.resolve(mockSlots),
        });

        const { result } = renderHook(() => useAvailableSlots('2026-07-28', 'svc-1'));

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(fetch).toHaveBeenCalledWith(
            '/admin/calendar/available-slots?date=2026-07-28&service_id=svc-1',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
    });

    it('future date: morning slots visible (no past-time filtering by frontend)', async () => {
        const futureSlots = { freeSlots: ['09:00', '09:30', '10:00'], outsideSlots: [] };
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            json: () => Promise.resolve(futureSlots),
        });

        const { result } = renderHook(() => useAvailableSlots('2026-12-15', 'svc-1'));

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(result.current.slots.freeSlots).toContain('09:00');
    });

    it('returns empty slots on fetch error', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

        const { result } = renderHook(() => useAvailableSlots('2026-07-28', undefined));

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(result.current.slots.freeSlots).toEqual([]);
        expect(result.current.slots.outsideSlots).toEqual([]);
    });

    it('clears slots when date is empty', () => {
        const { result } = renderHook(() => useAvailableSlots('', undefined));

        expect(result.current.slots.freeSlots).toEqual([]);
        expect(result.current.slots.outsideSlots).toEqual([]);
        expect(result.current.loading).toBe(false);
    });

    it('refetches when serviceId changes', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            json: () => Promise.resolve(mockSlots),
        });

        const { result, rerender } = renderHook(
            ({ date, serviceId }) => useAvailableSlots(date, serviceId),
            { initialProps: { date: '2026-07-28', serviceId: 'svc-1' } },
        );

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(fetch).toHaveBeenCalledTimes(1);

        rerender({ date: '2026-07-28', serviceId: 'svc-2' });

        await waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(2);
        });

        expect(fetch).toHaveBeenLastCalledWith(
            '/admin/calendar/available-slots?date=2026-07-28&service_id=svc-2',
            expect.anything(),
        );
    });

    it('aborts previous request when date changes rapidly', async () => {
        let resolveFirst: (v: unknown) => void;
        const firstPromise = new Promise((r) => { resolveFirst = r; });
        (fetch as ReturnType<typeof vi.fn>)
            .mockReturnValueOnce(firstPromise)
            .mockResolvedValueOnce({ json: () => Promise.resolve(mockSlots) });

        const { result, rerender } = renderHook(
            ({ date, serviceId }) => useAvailableSlots(date, serviceId),
            { initialProps: { date: '2026-07-28', serviceId: 'svc-1' } },
        );

        expect(result.current.loading).toBe(true);

        // Change date before first resolves
        rerender({ date: '2026-07-29', serviceId: 'svc-1' });

        // First request should be aborted
        resolveFirst!(null);

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(fetch).toHaveBeenCalledTimes(2);
    });
});

// ─── Source-level structural tests ──────────────────────────

describe('RecurringEditDialog — source-level', () => {
    it('uses IrsiTimeSelect, not plain Select for time', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        expect(content).toContain('IrsiTimeSelect');
        expect(content).toContain('useAvailableSlots');
        expect(content).toContain('timeGroups');
        expect(content).toContain('slots.freeSlots');
        expect(content).toContain('slots.outsideSlots');
    });

    it('uses appointment date for slot availability (not today fallback)', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        expect(content).toContain('recurring_occurrence_date');
        expect(content).toContain('appointment?.date');
    });

    it('clears time when no longer in available slots after service change', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        expect(content).toContain('allSlots.includes(time)');
        expect(content).toContain("setTime('')");
    });

    it('no longer accepts timeOptions prop', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // timeOptions should NOT be in Props interface or used
        expect(content).not.toMatch(/timeOptions:\s*string\[\]/);
    });

    it('both modes use same availability mechanism', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // useAvailableSlots is called at the top level, not conditionally per mode
        const useSlotsIdx = content.indexOf('useAvailableSlots');
        const modeCheckIdx = content.indexOf("mode === 'this-and-future'");
        // useAvailableSlots should be before any mode-specific code
        expect(useSlotsIdx).toBeGreaterThan(0);
        if (modeCheckIdx > 0) {
            expect(useSlotsIdx).toBeLessThan(modeCheckIdx);
        }
    });
});

// ─── calendar.tsx source tests ──────────────────────────────

describe('calendar.tsx — RecurringEditDialog wiring', () => {
    it('does NOT pass timeOptions to RecurringEditDialog', async () => {
        const source = await import('@/pages/admin/calendar?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // Find the RecurringEditDialog section and verify no timeOptions
        const dialogIdx = content.indexOf('<RecurringEditDialog');
        const dialogEndIdx = content.indexOf('/>', dialogIdx);
        const dialogSection = content.substring(dialogIdx, dialogEndIdx);
        expect(dialogSection).not.toContain('timeOptions');
    });

    it('still passes timeOptions to RescheduleDialog and AppointmentDetailDrawer', async () => {
        const source = await import('@/pages/admin/calendar?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // RescheduleDialog still uses naive timeOptions
        expect(content).toContain('RescheduleDialog');
        expect(content).toContain('timeOptions={timeOptions}');
    });
});

// ─── New Appointment & Recurring Edit share endpoint ────────

describe('Shared availability — same backend endpoint', () => {
    it('useCalendarActions and useAvailableSlots both fetch from available-slots', async () => {
        const actionsSource = require('fs').readFileSync(
            require('path').resolve(__dirname, '../hooks/useCalendarActions.ts'),
            'utf-8',
        );
        const slotsSource = require('fs').readFileSync(
            require('path').resolve(__dirname, '../hooks/useAvailableSlots.ts'),
            'utf-8',
        );

        expect(actionsSource).toContain('/admin/calendar/available-slots');
        expect(slotsSource).toContain('/admin/calendar/available-slots');
    });
});

// ─── Race condition regression tests ────────────────────────

describe('RecurringEditDialog — service_id race regression', () => {
    it('effectiveServiceId derives from series without waiting for useEffect', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // effectiveServiceId must be computed synchronously from series
        expect(content).toContain('series?.master_service_id');
        expect(content).toContain('effectiveServiceId');
        expect(content).toContain('serviceId || seriesServiceId');
    });

    it('useAvailableSlots never called with empty serviceId when open', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // Hook call must guard: only fetch when effectiveServiceId exists
        expect(content).toContain('open && effectiveServiceId');
    });

    it('no fetch without service_id in the hook call path', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // The useAvailableSlots call must NOT pass serviceId as bare undefined
        // It must use effectiveServiceId (which is never empty when open)
        const hookCallMatch = content.match(/useAvailableSlots\([\s\S]*?\)/);
        expect(hookCallMatch).not.toBeNull();
        const hookCall = hookCallMatch![0];
        expect(hookCall).toContain('effectiveServiceId');
    });

    it('syncs serviceId on appointment change via useEffect', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // Must have useEffect that re-initializes when open && series changes
        expect(content).toContain('setServiceId(cfg.serviceId)');
        expect(content).toContain('setTime(cfg.time)');
        expect(content).toContain('setRecurrence(cfg.recurrence)');
    });

    it('Select displays effectiveServiceId (not stale serviceId)', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // Select value should use effectiveServiceId to avoid showing stale value
        expect(content).toContain('value={effectiveServiceId}');
    });

    it('no stale slots shown before effectiveServiceId resolves', async () => {
        const source = await import('@/pages/admin/components/calendar/RecurringEditDialog?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;

        // When effectiveServiceId is empty (shouldn't happen, but guard), date is ''
        // which causes useAvailableSlots to return empty slots
        expect(content).toContain("open && effectiveServiceId ? slotDate : ''");
    });
});
