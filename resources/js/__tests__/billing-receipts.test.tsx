import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import React from 'react';
import BillingPage from '@/pages/admin/billing';

const mockUsePage = vi.fn();
const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: { get: routerGet, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@/layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isAxiosError: () => false,
    },
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}));

const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/200glz8o7h/print';

function makeReceipt(id: string, url: string) {
    return {
        id,
        status_label: 'Доступен в кабинете',
        receipt_url: url,
        amount: 1470,
        currency: 'RUB',
        confirmed_at: '2026-10-20T12:00:00Z',
        period_start: '2026-09-20T00:00:00Z',
        period_end: '2026-10-20T00:00:00Z',
        tariff_name: 'Профи',
    };
}

function makeProps(receipts: unknown, currentOverrides: Record<string, unknown> = {}) {
    return {
        props: {
            plans: [
                {
                    id: 1,
                    code: 'pro',
                    name: 'Профи',
                    price_monthly: 490,
                    max_appointments_per_month: null,
                    features: ['unlimited_appointments'],
                    prices: [1, 3, 6, 12].map((m) => ({
                        period_months: m,
                        base: 490 * m,
                        discount_percent: 0,
                        final: 490 * m,
                    })),
                },
            ],
            current: {
                tariff: 'pro',
                tariff_name: 'Профи',
                is_paid: true,
                expires_at: '2026-11-20T00:00:00Z',
                days_left: 27,
                ...currentOverrides,
            },
            auth: { user: { name: 'Test' } },
            receipts,
        },
    };
}

function renderAtWidth(node: React.ReactElement, width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
    window.dispatchEvent(new Event('resize'));

    return render(node);
}

describe('admin/billing.tsx — cabinet receipts', () => {
    beforeEach(() => {
        routerGet.mockReset();
    });

    it('desktop: shows amount, period, confirmation date, status and open link', () => {
        mockUsePage.mockReturnValue(makeProps({
            data: [makeReceipt('r-1', URL_A)],
            current_page: 1,
            last_page: 1,
            per_page: 5,
            total: 1,
        }));

        renderAtWidth(<BillingPage />, 1280);

        const section = screen.getByTestId('cabinet-receipts');
        expect(section).toBeInTheDocument();
        expect(within(section).getByText(/1\s?470\s?₽/u)).toBeInTheDocument();
        expect(within(section).getByText('Доступен в кабинете')).toBeInTheDocument();
        expect(within(section).getByText(/Подтверждено в ИРСИ/u)).toBeInTheDocument();

        const link = within(section).getByRole('link', { name: 'Открыть чек' });
        expect(link).toHaveAttribute('href', URL_A);
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'));
    });

    it('mobile 360px: receipts section renders with the open link', () => {
        mockUsePage.mockReturnValue(makeProps({
            data: [makeReceipt('r-1', URL_A)],
            current_page: 1,
            last_page: 1,
            per_page: 5,
            total: 1,
        }));

        renderAtWidth(<BillingPage />, 360);

        expect(screen.getByTestId('cabinet-receipt-item')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Открыть чек' })).toBeInTheDocument();
    });

    it('hides the section when there are no receipts', () => {
        mockUsePage.mockReturnValue(makeProps({
            data: [],
            current_page: 1,
            last_page: 1,
            per_page: 5,
            total: 0,
        }));

        renderAtWidth(<BillingPage />, 1280);

        expect(screen.queryByTestId('cabinet-receipts')).not.toBeInTheDocument();
    });

    it('paginates with receipts_page param', () => {
        mockUsePage.mockReturnValue(makeProps({
            data: [makeReceipt('r-1', URL_A)],
            current_page: 1,
            last_page: 2,
            per_page: 5,
            total: 7,
        }));

        renderAtWidth(<BillingPage />, 1280);

        fireEvent.click(screen.getByText('Вперёд'));

        expect(routerGet).toHaveBeenCalledWith(
            '/admin/billing',
            { receipts_page: 2 },
            { preserveState: true },
        );
    });
});
