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

describe('Widget Step 1 — compact mobile flow', () => {
    beforeEach(() => {
        cleanup();
    });

    it('shows "Шаг 1 из 4"', () => {
        render(React.createElement(Widget));
        expect(screen.getByText('Шаг 1 из 4')).toBeInTheDocument();
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

    it('renders search input with placeholder "Найти услугу"', () => {
        render(React.createElement(Widget));
        expect(screen.getByPlaceholderText('Найти услугу')).toBeInTheDocument();
    });

    it('service cards have role="radio"', () => {
        render(React.createElement(Widget));
        const radios = screen.getAllByRole('radio');
        expect(radios.length).toBe(3);
    });

    it('aria-checked changes after selection', () => {
        render(React.createElement(Widget));
        const striжкаBtn = screen.getByText('Стрижка').closest('button')!;
        expect(striжкаBtn).toHaveAttribute('aria-checked', 'false');
        fireEvent.click(striжкаBtn);
        expect(striжкаBtn).toHaveAttribute('aria-checked', 'true');
    });

    it('search filters services by title', () => {
        render(React.createElement(Widget));
        const input = screen.getByPlaceholderText('Найти услугу');
        fireEvent.change(input, { target: { value: 'Маникюр' } });
        expect(screen.getByText('Маникюр')).toBeInTheDocument();
        expect(screen.queryByText('Стрижка')).not.toBeInTheDocument();
    });

    it('shows "Ничего не найдено" for empty search results', () => {
        render(React.createElement(Widget));
        const input = screen.getByPlaceholderText('Найти услугу');
        fireEvent.change(input, { target: { value: 'Несуществующая услуга' } });
        expect(screen.getByText('Ничего не найдено')).toBeInTheDocument();
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

    it('CTA contains "Далее"', () => {
        render(React.createElement(Widget));
        expect(screen.getByText('Далее')).toBeInTheDocument();
    });

    it('does not contain "к выбору даты"', () => {
        render(React.createElement(Widget));
        expect(screen.queryByText('к выбору даты')).not.toBeInTheDocument();
    });

    it('does not render "Безопасная запись через ИРСИ"', () => {
        render(React.createElement(Widget));
        expect(screen.queryByText(/Безопасная запись через ИРСИ/)).not.toBeInTheDocument();
    });

    it('does not render numeric badges 01 / 02 / 03', () => {
        render(React.createElement(Widget));
        expect(screen.queryByText('01')).not.toBeInTheDocument();
        expect(screen.queryByText('02')).not.toBeInTheDocument();
        expect(screen.queryByText('03')).not.toBeInTheDocument();
    });
});
