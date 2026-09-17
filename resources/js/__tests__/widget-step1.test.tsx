import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import React from 'react';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => React.createElement('title', null, title),
    Link: ({ children, ...p }: any) => React.createElement('a', p, children),
    router: { get: vi.fn() },
    usePage: () => ({
        props: {
            master: { name: 'Тест Мастер', specialty: 'Парикмахер', address: 'ул. Пушкина, 10', avatar_url: null, master_slug: 'test-master' },
            services: [
                { id: 'svc-1', title: 'Стрижка', duration_minutes: 60, price: 1500 },
                { id: 'svc-2', title: 'Маникюр', duration_minutes: 45, price: 800 },
                {
                    id: 'svc-3',
                    title: 'Комплексный маникюр с укреплением ногтевой пластины и покрытием гель-лаком',
                    duration_minutes: 90,
                    price: 2500,
                },
            ],
            selectedDate: '',
            selectedServiceId: null,
            maxBotName: null,
        },
    }),
}));

vi.mock('@/lib/utils', () => ({
    getInitials: () => 'ТМ',
}));

let Widget: typeof import('@/pages/booking/widget').default;

beforeAll(async () => {
    const mod = await import('@/pages/booking/widget');
    Widget = mod.default;
});

describe('Widget Step 1 — service selection redesign', () => {
    beforeEach(() => {
        cleanup();
    });

    it('renders logo-mark.svg', () => {
        render(React.createElement(Widget));
        const img = screen.getByAltText('Вовремя');
        expect(img).toHaveAttribute('src', '/images/logo-mark.svg');
    });

    it('renders master name', () => {
        render(React.createElement(Widget));
        expect(screen.getByText('Тест Мастер')).toBeInTheDocument();
    });

    it('renders master address', () => {
        render(React.createElement(Widget));
        expect(screen.getAllByText(/ул\. Пушкина, 10/).length).toBeGreaterThan(0);
    });

    it('does not render specialty on step 1', () => {
        render(React.createElement(Widget));
        expect(screen.queryByText('Парикмахер')).not.toBeInTheDocument();
    });

    it('renders service card numbers 01, 02, 03', () => {
        render(React.createElement(Widget));
        expect(screen.getByText('01')).toBeInTheDocument();
        expect(screen.getByText('02')).toBeInTheDocument();
        expect(screen.getByText('03')).toBeInTheDocument();
    });

    it('renders long service title in full', () => {
        render(React.createElement(Widget));
        const longTitle = 'Комплексный маникюр с укреплением ногтевой пластины и покрытием гель-лаком';
        expect(screen.getByText(longTitle)).toBeInTheDocument();
    });

    it('renders price for long-title service', () => {
        render(React.createElement(Widget));
        expect(screen.getByText(/2[\s\u00a0]500\s₽/)).toBeInTheDocument();
    });

    it('selecting a service changes aria-pressed to true', () => {
        render(React.createElement(Widget));
        const striжкаBtn = screen.getByText('Стрижка').closest('button')!;
        expect(striжкаBtn).toHaveAttribute('aria-pressed', 'false');
        fireEvent.click(striжкаBtn);
        expect(striжкаBtn).toHaveAttribute('aria-pressed', 'true');
    });

    it('CTA contains "Продолжить" and "к выбору даты"', () => {
        render(React.createElement(Widget));
        expect(screen.getByText('Продолжить')).toBeInTheDocument();
        expect(screen.getByText('к выбору даты')).toBeInTheDocument();
    });

    it('step 1 does not render "Безопасная запись через ИРСИ"', () => {
        render(React.createElement(Widget));
        expect(screen.queryByText(/Безопасная запись через ИРСИ/)).not.toBeInTheDocument();
    });
});
