import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

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

describe('FreeWindowsDrawer', () => {
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

        it('result rows use flex-col for mobile stacking', () => {
            expect(source).toContain('flex-col');
        });

        it('result rows use sm:justify-between for desktop', () => {
            expect(source).toContain('sm:justify-between');
        });

        it('times use sm:text-right for desktop alignment', () => {
            expect(source).toContain('text-left sm:text-right');
        });

        it('DialogContent has overflow-x-hidden', () => {
            expect(source).toContain('overflow-x-hidden');
        });

        it('DialogContent has responsive padding', () => {
            expect(source).toContain('p-4 sm:p-6');
        });

        it('does not have bare flex gap-2 without responsive semantics for actions', () => {
            const actionsSection = source.slice(
                source.indexOf('result.days.length > 0 && ('),
            );
            expect(actionsSection).not.toMatch(/className="flex gap-2"/);
        });
    });

    describe('render smoke', () => {
        it('renders period controls', () => {
            render(
                <FreeWindowsDrawer
                    open
                    onOpenChange={vi.fn()}
                    isPro
                    services={SERVICES}
                />,
            );

            expect(screen.getByText('Сегодня')).toBeDefined();
            expect(screen.getByText('Завтра')).toBeDefined();
            expect(screen.getByText('3 дня')).toBeDefined();
            expect(screen.getByText('7 дней')).toBeDefined();
            expect(screen.getByText('Свой период')).toBeDefined();
        });

        it('renders Показать button', () => {
            render(
                <FreeWindowsDrawer
                    open
                    onOpenChange={vi.fn()}
                    isPro
                    services={SERVICES}
                />,
            );

            expect(screen.getByText('Показать')).toBeDefined();
        });

        it('renders service selector', () => {
            render(
                <FreeWindowsDrawer
                    open
                    onOpenChange={vi.fn()}
                    isPro
                    services={SERVICES}
                />,
            );

            expect(screen.getByText('Услуга')).toBeDefined();
        });

        it('does not render copy actions before results', () => {
            render(
                <FreeWindowsDrawer
                    open
                    onOpenChange={vi.fn()}
                    isPro
                    services={SERVICES}
                />,
            );

            expect(screen.queryByText('Скопировать текст')).toBeNull();
            expect(screen.queryByText('Скопировать ссылку')).toBeNull();
        });
    });
});
