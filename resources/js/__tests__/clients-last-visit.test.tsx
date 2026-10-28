import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import ClientsPage from '@/pages/admin/clients';
import type { Client } from '@/types/app';

const mockUsePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}));

vi.mock('@/layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function makeClient(overrides: Partial<Client>): Client {
    return {
        id: 'c1',
        user_id: 'm1',
        name: 'Анна Смирнова',
        phone: '+79001110001',
        telegram_id: null,
        max_id: null,
        avatar_url: null,
        is_blocked: false,
        notes: null,
        auth_token: null,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        total_bookings: 1,
        completed_bookings: 1,
        ltv: 1000,
        last_visit: null,
        ...overrides,
    };
}

function renderWithClient(client: Client) {
    mockUsePage.mockReturnValue({
        props: {
            auth: { user: { id: 'm1', name: 'Мастер', tariff_name: 'Базовый' } },
            clients: {
                data: [client],
                current_page: 1,
                last_page: 1,
                per_page: 12,
                total: 1,
                from: 1,
                to: 1,
            },
        },
    });

    return render(<ClientsPage />);
}

function openClientDrawer() {
    const card = screen.getByText('Анна Смирнова').closest('article');
    expect(card).not.toBeNull();
    fireEvent.click(card as HTMLElement);
}

describe('clients page — последний визит', () => {
    beforeEach(() => {
        mockUsePage.mockReset();
    });

    it('карточка и подробности показывают одну и ту же дату визита', () => {
        // Отдано сервером уже в поясе мастера: 2026-06-01 22:30 UTC → 2026-06-02 01:30 Europe/Moscow.
        renderWithClient(makeClient({ last_visit: '2026-06-02 01:30:00' }));

        // Граница суток: день не сдвигается обратно через UTC — это 2 июня, а не 1.
        expect(screen.getByText('2 июн 2026')).toBeInTheDocument();

        openClientDrawer();

        // Дата в карточке и в подробностях идентичны.
        expect(screen.getAllByText('2 июн 2026')).toHaveLength(2);
    });

    it('при null и в карточке, и в подробностях — «Ещё не было»', () => {
        renderWithClient(makeClient({ last_visit: null }));

        expect(screen.getByText('Ещё не было')).toBeInTheDocument();

        openClientDrawer();

        expect(screen.getAllByText('Ещё не было')).toHaveLength(2);
    });
});
