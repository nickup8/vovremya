import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import React from 'react';
import Receipts from '@/pages/SuperAdmin/Receipts';

const mockUsePage = vi.fn();
const { routerGet, routerPut, routerPost } = vi.hoisted(() => ({
    routerGet: vi.fn(),
    routerPut: vi.fn(),
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => mockUsePage(),
    router: {
        get: routerGet,
        put: routerPut,
        post: routerPost,
        patch: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
}));

const VALID_URL = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/200glz8o7h/print';

function makeReceipt(overrides: Record<string, unknown> = {}, attemptOverrides: Record<string, unknown> = {}) {
    return {
        id: 'r-1',
        status: 'awaiting_manual',
        receipt_url: null,
        attached_at: null,
        attached_by_user: null,
        refund_review_required: false,
        refund_reviewed_at: null,
        created_at: '2026-10-24T10:00:00Z',
        attempt: {
            id: 'a-1',
            amount: 1470,
            currency: 'RUB',
            confirmed_at: '2026-10-20T12:00:00Z',
            provider: 'tbank',
            payment_env: 'live',
            origin: 'payment',
            period_start: '2026-10-20T00:00:00Z',
            period_end: '2026-11-20T00:00:00Z',
            tariff_name: 'Профи',
            workspace_id: 'ws-1',
            owner_name: 'Анна Иванова',
            owner_email: 'anna@example.com',
            ...attemptOverrides,
        },
        ...overrides,
    };
}

function makeProps(overrides: Record<string, unknown> = {}) {
    return {
        receipts: {
            data: [makeReceipt()],
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 1,
        },
        tab: 'awaiting',
        canUpdate: true,
        flash: {},
        platformAdmin: { isRoot: true, permissions: ['receipts.view', 'receipts.update'] },
        ...overrides,
    };
}

/** Minimal 360px viewport harness — asserts the layout does not overflow. */
function renderAtWidth(node: React.ReactElement, width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
    window.dispatchEvent(new Event('resize'));

    return render(node);
}

describe('SuperAdmin/Receipts', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue({ props: makeProps() });
        routerGet.mockReset();
        routerPut.mockReset();
        routerPost.mockReset();
    });

    it('desktop: shows customer, amount, period, confirmed_at and status', () => {
        renderAtWidth(<Receipts />, 1280);

        expect(screen.getByRole('heading', { name: 'Чеки' })).toBeInTheDocument();
        const card = screen.getByTestId('receipt-card');
        expect(within(card).getByText('Анна Иванова')).toBeInTheDocument();
        expect(within(card).getByText(/1\s?470\s?₽/u)).toBeInTheDocument();
        expect(within(card).getByText(/Подтверждено в ИРСИ:.*20 октября 2026/u)).toBeInTheDocument();
        expect(within(card).getByText(/Ожидает оформления/u)).toBeInTheDocument();
    });

    it('mobile 360px: renders the card and edit control without crash', () => {
        renderAtWidth(<Receipts />, 360);

        expect(screen.getByTestId('receipt-card')).toBeInTheDocument();
        expect(screen.getByTestId('receipt-edit-btn')).toBeInTheDocument();
        expect(screen.getByText('Анна Иванова')).toBeInTheDocument();
    });

    it('switches tabs via router.get', () => {
        renderAtWidth(<Receipts />, 1280);

        fireEvent.click(screen.getByText('Прикреплённые'));

        expect(routerGet).toHaveBeenCalledWith('/admin-root/receipts', { tab: 'attached' }, { preserveState: true });
    });

    it('opens the URL dialog and submits the link', () => {
        renderAtWidth(<Receipts />, 1280);

        fireEvent.click(screen.getByTestId('receipt-edit-btn'));
        expect(screen.getByTestId('receipt-url-dialog')).toBeInTheDocument();

        fireEvent.change(screen.getByTestId('receipt-url-input'), { target: { value: VALID_URL } });
        fireEvent.click(screen.getByTestId('receipt-url-save'));

        expect(routerPut).toHaveBeenCalledWith(
            '/admin-root/receipts/r-1/attach',
            { receipt_url: VALID_URL },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('hides update controls without receipts.update permission', () => {
        mockUsePage.mockReturnValue({ props: makeProps({ canUpdate: false }) });
        renderAtWidth(<Receipts />, 1280);

        expect(screen.queryByTestId('receipt-edit-btn')).not.toBeInTheDocument();
    });

    it('shows refund review action on the refund tab', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({
                tab: 'refund_review',
                receipts: {
                    data: [makeReceipt({ refund_review_required: true, status: 'attached', receipt_url: VALID_URL })],
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 1,
                },
            }),
        });
        renderAtWidth(<Receipts />, 1280);

        expect(screen.getByText(/требует ручной проверки/iu)).toBeInTheDocument();
        fireEvent.click(screen.getByTestId('receipt-reviewed-btn'));
        expect(routerPost).toHaveBeenCalledWith(
            '/admin-root/receipts/r-1/refund-reviewed',
            {},
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('shows attached receipt link for open action', () => {
        mockUsePage.mockReturnValue({
            props: makeProps({
                tab: 'attached',
                receipts: {
                    data: [makeReceipt({ status: 'attached', receipt_url: VALID_URL, attached_at: '2026-10-21T09:00:00Z' })],
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 1,
                },
            }),
        });
        renderAtWidth(<Receipts />, 1280);

        const link = screen.getByRole('link', { name: /Открыть чек/u });
        expect(link).toHaveAttribute('href', VALID_URL);
        expect(link).toHaveAttribute('target', '_blank');
    });
});
