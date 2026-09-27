import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act, cleanup } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { master_slug: 'test-master' } }),
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
});

Object.defineProperty(navigator, 'share', {
    value: vi.fn().mockResolvedValue(undefined),
    configurable: true,
});

import FreeWindowsDrawer from '@/components/admin/FreeWindowsDrawer';

const SERVICES = [
    { id: 'svc-1', title: 'Маникюр' },
    { id: 'svc-2', title: 'Педикюр' },
];

function getDrawerSource(): string {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const fs = require('fs');
    const path = require('path');
    return fs.readFileSync(
        path.resolve(__dirname, '../components/admin/FreeWindowsDrawer.tsx'),
        'utf-8',
    );
}

/* ═══════════════ Mock fetch helper ═══════════════ */

function mockFetchSuccess(data: unknown) {
    globalThis.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(data),
    }) as never;
}

/* ═══════════════ Sample responses ═══════════════ */

const SERVICE_RESULT = {
    mode: 'service' as const,
    timezone: 'Europe/Moscow',
    date_from: '2025-09-28',
    date_to: '2025-09-28',
    booking_url: 'https://irsi.test/book/test-master?service_id=svc-1',
    days: [
        { date: '2025-09-28', starts: ['09:00', '09:15', '09:30', '10:00'] },
        { date: '2025-09-29', starts: ['11:00', '15:30'] },
    ],
};

const ALL_RESULT = {
    mode: 'all' as const,
    timezone: 'Europe/Moscow',
    date_from: '2025-09-28',
    date_to: '2025-09-29',
    booking_url: 'https://irsi.test/book/test-master',
    days: [
        { date: '2025-09-28', ranges: [{ start: '10:00', end: '13:00' }, { start: '14:00', end: '16:00' }] },
        { date: '2025-09-29', ranges: [{ start: '09:00', end: '12:00' }] },
    ],
};

const EMPTY_RESULT = {
    mode: 'service' as const,
    timezone: 'Europe/Moscow',
    date_from: '2025-09-28',
    date_to: '2025-09-29',
    booking_url: 'https://irsi.test/book/test-master',
    days: [],
};

/* ═══════════════ Helper to render with generation ═══════════════ */

async function renderWithGenerate(fetchResult: unknown) {
    mockFetchSuccess(fetchResult);

    const result = render(
        <FreeWindowsDrawer
            open
            onOpenChange={vi.fn()}
            isPro
            services={SERVICES}
        />,
    );

    // Click "Показать"
    const showBtn = screen.getByRole('button', { name: 'Показать' });
    await act(async () => {
        fireEvent.click(showBtn);
        await vi.waitFor(() => {});
    });

    return result;
}

/* ═══════════════ Tests ═══════════════ */

describe('FreeWindowsDrawer', () => {
    beforeEach(() => {
        cleanup();
        (navigator.clipboard.writeText as ReturnType<typeof vi.fn>).mockClear();
        (navigator.share as ReturnType<typeof vi.fn>).mockClear();
    });

    describe('responsive class source assertions', () => {
        let source: string;

        beforeEach(() => {
            source = getDrawerSource();
        });

        it('actions use grid-cols-1 for mobile stacking', () => {
            expect(source).toContain('grid-cols-1');
        });

        it('actions use sm:flex-row for desktop horizontal layout', () => {
            expect(source).toContain('sm:flex-row');
        });

        it('buttons use w-full for mobile full-width', () => {
            expect(source).toContain('w-full sm:flex-1');
        });

        it('share button uses w-full sm:w-auto', () => {
            expect(source).toContain('w-full sm:w-auto');
        });

        it('DialogContent has overflow-x-hidden', () => {
            expect(source).toContain('overflow-x-hidden');
        });

        it('DialogContent has responsive padding', () => {
            expect(source).toContain('p-4 sm:p-6');
        });

        it('chip container uses flex-wrap gap-2', () => {
            expect(source).toContain('flex flex-wrap gap-2');
        });

        it('does not have overflow-x-auto on chip container', () => {
            expect(source).not.toMatch(/data-testid="chips-container"[^>]*overflow-x-auto/);
        });

        it('does not have whitespace-nowrap on chip container', () => {
            expect(source).not.toMatch(/data-testid="chips-container"[^>]*whitespace-nowrap/);
        });
    });

    describe('render smoke', () => {
        it('renders period controls', () => {
            render(
                <FreeWindowsDrawer open onOpenChange={vi.fn()} isPro services={SERVICES} />,
            );

            expect(screen.getByText('Сегодня')).toBeDefined();
            expect(screen.getByText('Завтра')).toBeDefined();
            expect(screen.getByText('3 дня')).toBeDefined();
            expect(screen.getByText('7 дней')).toBeDefined();
            expect(screen.getByText('Свой период')).toBeDefined();
        });

        it('renders Показать button', () => {
            render(
                <FreeWindowsDrawer open onOpenChange={vi.fn()} isPro services={SERVICES} />,
            );
            expect(screen.getByRole('button', { name: 'Показать' })).toBeDefined();
        });

        it('renders service selector', () => {
            render(
                <FreeWindowsDrawer open onOpenChange={vi.fn()} isPro services={SERVICES} />,
            );
            expect(screen.getByText('Услуга')).toBeDefined();
        });

        it('does not render copy actions before results', () => {
            render(
                <FreeWindowsDrawer open onOpenChange={vi.fn()} isPro services={SERVICES} />,
            );
            expect(screen.queryByText('Скопировать текст')).toBeNull();
            expect(screen.queryByText('Скопировать ссылку')).toBeNull();
        });
    });

    describe('empty backend result', () => {
        it('shows correct message when backend returns 0 days', async () => {
            await renderWithGenerate(EMPTY_RESULT);
            expect(screen.getByText('На выбранный период свободного времени нет')).toBeDefined();
        });
    });

    describe('service mode chips', () => {
        it('renders start chips after generation', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            expect(screen.getByRole('button', { name: 'Скрыть время 09:00' })).toBeDefined();
            expect(screen.getByRole('button', { name: 'Скрыть время 09:15' })).toBeDefined();
            expect(screen.getByRole('button', { name: 'Скрыть время 09:30' })).toBeDefined();
            expect(screen.getByRole('button', { name: 'Скрыть время 10:00' })).toBeDefined();
        });

        it('renders chips for multiple days', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            expect(screen.getByRole('button', { name: 'Скрыть время 11:00' })).toBeDefined();
            expect(screen.getByRole('button', { name: 'Скрыть время 15:30' })).toBeDefined();
        });

        it('renders day headers', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            expect(screen.getByText('28 сентября')).toBeDefined();
            expect(screen.getByText('29 сентября')).toBeDefined();
        });
    });

    describe('service mode chip toggle', () => {
        it('click start chip → hidden (aria-pressed false, line-through)', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть время 09:00' });
            expect(chip.getAttribute('aria-pressed')).toBe('true');

            fireEvent.click(chip);

            const hiddenChip = screen.getByRole('button', { name: 'Вернуть время 09:00' });
            expect(hiddenChip.getAttribute('aria-pressed')).toBe('false');
            expect(hiddenChip.className).toContain('line-through');
            expect(hiddenChip.className).toContain('opacity-60');
        });

        it('hidden start stays in DOM', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть время 09:00' });
            fireEvent.click(chip);

            expect(screen.getByRole('button', { name: 'Вернуть время 09:00' })).toBeDefined();
        });

        it('click hidden chip → restored (aria-pressed true)', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть время 09:00' });
            fireEvent.click(chip);

            const hiddenChip = screen.getByRole('button', { name: 'Вернуть время 09:00' });
            fireEvent.click(hiddenChip);

            const restoredChip = screen.getByRole('button', { name: 'Скрыть время 09:00' });
            expect(restoredChip.getAttribute('aria-pressed')).toBe('true');
            expect(restoredChip.className).not.toContain('line-through');
        });
    });

    describe('all-services mode chips', () => {
        it('renders range chips', async () => {
            await renderWithGenerate(ALL_RESULT);

            expect(screen.getByRole('button', { name: 'Скрыть диапазон 10:00–13:00' })).toBeDefined();
            expect(screen.getByRole('button', { name: 'Скрыть диапазон 14:00–16:00' })).toBeDefined();
        });

        it('range chip toggle', async () => {
            await renderWithGenerate(ALL_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть диапазон 10:00–13:00' });
            fireEvent.click(chip);

            const hiddenChip = screen.getByRole('button', { name: 'Вернуть диапазон 10:00–13:00' });
            expect(hiddenChip.getAttribute('aria-pressed')).toBe('false');
            expect(hiddenChip.className).toContain('line-through');
        });

        it('click hidden range → restored', async () => {
            await renderWithGenerate(ALL_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть диапазон 10:00–13:00' });
            fireEvent.click(chip);

            const hiddenChip = screen.getByRole('button', { name: 'Вернуть диапазон 10:00–13:00' });
            fireEvent.click(hiddenChip);

            const restored = screen.getByRole('button', { name: 'Скрыть диапазон 10:00–13:00' });
            expect(restored.getAttribute('aria-pressed')).toBe('true');
        });
    });

    describe('day toggle', () => {
        it('click "Скрыть день" → day hidden, shows "Вернуть день"', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const hideBtn = screen.getByRole('button', { name: 'Скрыть день 28 сентября' });
            fireEvent.click(hideBtn);

            expect(screen.getByText('Вернуть день')).toBeDefined();
            expect(screen.getByText('День скрыт из публикации')).toBeDefined();
        });

        it('click "Вернуть день" → day restored', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const hideBtn = screen.getByRole('button', { name: 'Скрыть день 28 сентября' });
            fireEvent.click(hideBtn);

            const restoreBtn = screen.getByText('Вернуть день');
            fireEvent.click(restoreBtn);

            expect(screen.getByRole('button', { name: 'Скрыть день 28 сентября' })).toBeDefined();
            expect(screen.getByRole('button', { name: 'Скрыть день 29 сентября' })).toBeDefined();
        });

        it('individual hidden state survives hide-day → restore-day', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            // Hide individual start
            const chip = screen.getByRole('button', { name: 'Скрыть время 09:15' });
            fireEvent.click(chip);
            expect(screen.getByRole('button', { name: 'Вернуть время 09:15' })).toBeDefined();

            // Hide the day
            const hideDayBtn = screen.getByRole('button', { name: 'Скрыть день 28 сентября' });
            fireEvent.click(hideDayBtn);

            // Chips are not visible (day hidden)
            expect(screen.queryByRole('button', { name: 'Вернуть время 09:15' })).toBeNull();

            // Restore the day
            const restoreDayBtn = screen.getByText('Вернуть день');
            fireEvent.click(restoreDayBtn);

            // 09:15 should still be hidden
            expect(screen.getByRole('button', { name: 'Вернуть время 09:15' })).toBeDefined();
            // 09:00 should still be visible
            expect(screen.getByRole('button', { name: 'Скрыть время 09:00' })).toBeDefined();
        });
    });

    describe('copy text filtering', () => {
        it('hidden start is absent from copied text', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));

            fireEvent.click(screen.getByRole('button', { name: 'Скопировать текст' }));

            const writeTextMock = navigator.clipboard.writeText as ReturnType<typeof vi.fn>;
            expect(writeTextMock).toHaveBeenCalledTimes(1);
            const copiedText = writeTextMock.mock.calls[0][0] as string;
            expect(copiedText).not.toContain('09:00');
            expect(copiedText).toContain('09:15');
            expect(copiedText).toContain('09:30');
        });

        it('hidden range is absent from copied text (all-services)', async () => {
            await renderWithGenerate(ALL_RESULT);

            fireEvent.click(screen.getByRole('button', { name: 'Скрыть диапазон 10:00–13:00' }));

            fireEvent.click(screen.getByRole('button', { name: 'Скопировать текст' }));

            const writeTextMock = navigator.clipboard.writeText as ReturnType<typeof vi.fn>;
            expect(writeTextMock).toHaveBeenCalledTimes(1);
            const copiedText = writeTextMock.mock.calls[0][0] as string;
            expect(copiedText).not.toContain('10:00–13:00');
            expect(copiedText).toContain('14:00–16:00');
        });

        it('hidden day is absent from copied text', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            fireEvent.click(screen.getByRole('button', { name: 'Скрыть день 28 сентября' }));

            fireEvent.click(screen.getByRole('button', { name: 'Скопировать текст' }));

            const writeTextMock = navigator.clipboard.writeText as ReturnType<typeof vi.fn>;
            expect(writeTextMock).toHaveBeenCalledTimes(1);
            const copiedText = writeTextMock.mock.calls[0][0] as string;
            expect(copiedText).not.toContain('28 сентября');
            expect(copiedText).toContain('29 сентября');
        });
    });

    describe('share filtering', () => {
        it('share uses visibleDays only', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));

            // Find share button by its class pattern (w-full sm:w-auto button with Share2 icon)
            const shareBtn = document.querySelector('button.w-full.sm\\:w-auto');
            expect(shareBtn).toBeTruthy();
            fireEvent.click(shareBtn!);

            const shareMock = navigator.share as ReturnType<typeof vi.fn>;
            expect(shareMock).toHaveBeenCalledTimes(1);
            const sharedText = shareMock.mock.calls[0][0].text as string;
            expect(sharedText).not.toContain('09:00');
            expect(sharedText).toContain('09:15');
        });
    });

    describe('all-hidden state', () => {
        it('shows all-hidden message when all items hidden', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            // Hide all starts day 1
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:15' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:30' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 10:00' }));

            // Hide all starts day 2
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 11:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 15:30' }));

            expect(screen.getByText('Вы скрыли всё свободное время')).toBeDefined();
            expect(screen.getByText('Верните хотя бы одно время или день, чтобы поделиться публикацией.')).toBeDefined();
        });

        it('copy text disabled when all hidden', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:15' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:30' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 10:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 11:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 15:30' }));

            const copyTextBtn = screen.getByRole('button', { name: /Скопировать текст/ });
            const copyLinkBtn = screen.getByRole('button', { name: /Скопировать ссылку/ });

            expect(copyTextBtn.hasAttribute('disabled')).toBe(true);
            expect(copyLinkBtn.hasAttribute('disabled')).toBe(true);
        });

        it('hiding all days via day toggle shows all-hidden', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const hideButtons = screen.getAllByText('Скрыть день');
            fireEvent.click(hideButtons[0]);
            // After first day hidden, only one "Скрыть день" remains
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть день 29 сентября' }));

            expect(screen.getByText('Вы скрыли всё свободное время')).toBeDefined();
        });
    });

    describe('reset state', () => {
        it('hidden state resets on new generation', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            // Hide an item
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));
            expect(screen.getByRole('button', { name: 'Вернуть время 09:00' })).toBeDefined();

            // Generate again - click the Показать button (there's only one after first gen)
            fireEvent.click(screen.getByRole('button', { name: 'Показать' }));
            await act(async () => {
                await vi.waitFor(() => {});
            });

            // 09:00 should be visible again
            expect(screen.getByRole('button', { name: 'Скрыть время 09:00' })).toBeDefined();
        });

        it('hidden state resets on drawer close', async () => {
            const onOpenChange = vi.fn();
            mockFetchSuccess(SERVICE_RESULT);

            const { rerender } = render(
                <FreeWindowsDrawer open onOpenChange={onOpenChange} isPro services={SERVICES} />,
            );

            // Generate
            fireEvent.click(screen.getByRole('button', { name: 'Показать' }));
            await act(async () => {
                await vi.waitFor(() => {});
            });

            // Hide an item
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));

            // Close drawer
            rerender(
                <FreeWindowsDrawer open={false} onOpenChange={onOpenChange} isPro services={SERVICES} />,
            );

            // Reopen
            rerender(
                <FreeWindowsDrawer open onOpenChange={onOpenChange} isPro services={SERVICES} />,
            );

            // No results shown (reset)
            expect(screen.queryByRole('button', { name: 'Скрыть время 09:00' })).toBeNull();
        });
    });

    describe('all-hidden is different from empty backend', () => {
        it('empty backend shows different message than all-hidden', async () => {
            // Empty backend
            await renderWithGenerate(EMPTY_RESULT);
            expect(screen.getByText('На выбранный период свободного времени нет')).toBeDefined();
        });

        it('all-hidden shows specific message', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:15' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 09:30' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 10:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 11:00' }));
            fireEvent.click(screen.getByRole('button', { name: 'Скрыть время 15:30' }));

            expect(screen.getByText('Вы скрыли всё свободное время')).toBeDefined();
            expect(screen.queryByText('На выбранный период свободного времени нет')).toBeNull();
        });
    });

    describe('accessibility', () => {
        it('chips are buttons with type="button"', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть время 09:00' });
            expect(chip.tagName).toBe('BUTTON');
            expect(chip.getAttribute('type')).toBe('button');
        });

        it('day toggle is a button', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const dayBtn = screen.getByRole('button', { name: 'Скрыть день 28 сентября' });
            expect(dayBtn.tagName).toBe('BUTTON');
            expect(dayBtn.getAttribute('type')).toBe('button');
        });

        it('chips have aria-pressed', async () => {
            await renderWithGenerate(SERVICE_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть время 09:00' });
            expect(chip.hasAttribute('aria-pressed')).toBe(true);
        });

        it('range chips have aria-label', async () => {
            await renderWithGenerate(ALL_RESULT);

            const chip = screen.getByRole('button', { name: 'Скрыть диапазон 10:00–13:00' });
            expect(chip.hasAttribute('aria-label')).toBe(true);
        });
    });
});
