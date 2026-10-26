import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import React from 'react';
import AdminLayout from '@/layouts/AdminLayout';

const mockUsePage = vi.fn();
const routerPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockUsePage(),
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: (...args: unknown[]) => routerPost(...args), get: vi.fn() },
}));

vi.mock('@/components/admin/Sidebar', () => ({
    default: () => null,
}));

vi.mock('@/components/ui/sonner', () => ({
    Toaster: () => null,
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'light', updateAppearance: vi.fn() }),
}));

const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/200glz8o7h/print';

const receiptNotification = {
    id: 'n-receipt',
    kind: 'system',
    title: 'Чек об оплате подписки',
    body: 'Чек готов. Откройте его по ссылке ниже',
    action_url: URL_A,
    action_label: 'Открыть чек',
    read_at: null,
    created_at: new Date().toISOString(),
};

const plainNotification = {
    id: 'n-plain',
    kind: 'system',
    title: 'Обычное уведомление',
    body: 'Просто текст без действия',
    action_url: null,
    action_label: null,
    read_at: '2026-11-01T10:00:00Z',
    created_at: new Date().toISOString(),
};

function mockNotifications(items: unknown[]) {
    mockUsePage.mockReturnValue({
        props: {
            notifications: { unread_count: items.length, items },
        },
    });
}

function setViewport(width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
}

function openNotificationsAndSelect(title: string) {
    fireEvent.click(screen.getByRole('button', { name: 'Уведомления', hidden: false }));

    const popover = screen.getByRole('region', { name: 'Уведомления' });
    fireEvent.click(within(popover).getByText(title));

    return popover;
}

beforeAll(() => {
    // jsdom has no matchMedia; AdminLayout queries it for the breakpoint.
    if (!window.matchMedia) {
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: (query: string) => ({
                matches: false,
                media: query,
                onchange: null,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                addListener: vi.fn(),
                removeListener: vi.fn(),
                dispatchEvent: vi.fn(),
            }),
        });
    }
});

describe('AdminLayout — notification action link', () => {
    beforeEach(() => {
        routerPost.mockReset();
        mockNotifications([receiptNotification, plainNotification]);
        setViewport(1280);
        window.history.replaceState(null, '', '/admin/calendar');
    });

    it('desktop: opened notification shows «Открыть чек» as an external link', async () => {
        render(<AdminLayout title="Календарь">{null}</AdminLayout>);

        fireEvent.click(screen.getByRole('button', { name: 'Уведомления', hidden: false }));
        // Let the outside-click document listener register (setTimeout 0) so
        // the list/detail clicks below run against the real conditions.
        await new Promise((resolve) => setTimeout(resolve, 0));

        const popover = screen.getByRole('region', { name: 'Уведомления' });
        fireEvent.click(within(popover).getByText('Чек об оплате подписки'));

        expect(popover).toBeInTheDocument();
        const link = within(popover).getByRole('link', { name: 'Открыть чек' });
        expect(link).toHaveAttribute('href', URL_A);
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'));
        expect(link).toHaveAttribute('rel', expect.stringContaining('noreferrer'));
        // Visible keyboard focus, not an Inertia transition.
        expect(link.className).toContain('focus-visible:outline-2');
    });

    it('desktop: notification without action renders no link', async () => {
        render(<AdminLayout title="Календарь">{null}</AdminLayout>);

        fireEvent.click(screen.getByRole('button', { name: 'Уведомления', hidden: false }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        const popover = screen.getByRole('region', { name: 'Уведомления' });
        fireEvent.click(within(popover).getByText('Обычное уведомление'));

        expect(within(popover).queryByRole('link')).not.toBeInTheDocument();
        expect(within(popover).getByText('Просто текст без действия')).toBeInTheDocument();
    });

    it('marks the notification read through router.post when opened', () => {
        render(<AdminLayout title="Календарь">{null}</AdminLayout>);

        openNotificationsAndSelect('Чек об оплате подписки');

        expect(routerPost).toHaveBeenCalledWith(
            '/admin/notifications/n-receipt/read',
            {},
            expect.anything(),
        );
    });

    it('mobile drawer: tap on a list item keeps the drawer open and shows the external link', async () => {
        setViewport(375);
        render(<AdminLayout title="Календарь">{null}</AdminLayout>);

        fireEvent.click(screen.getByRole('button', { name: 'Уведомления', hidden: false }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        // Mobile drawer is a fixed overlay, not the desktop section.
        const drawer = document.querySelector('.fixed.inset-y-0.right-0');
        expect(drawer).not.toBeNull();

        fireEvent.click(within(drawer as HTMLElement).getByText('Чек об оплате подписки'));

        // The tap must not bubble to the document outside-click handler —
        // otherwise the drawer closes and the detail is unreachable.
        expect(drawer).toBeInTheDocument();

        const link = within(drawer as HTMLElement).getByRole('link', { name: 'Открыть чек' });
        expect(link).toHaveAttribute('href', URL_A);
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'));
        expect(link.className).toContain('focus-visible:outline-2');
    });
});
