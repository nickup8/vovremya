import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest';
import {
    render,
    screen,
    fireEvent,
    waitFor,
    within,
} from '@testing-library/react';
import React from 'react';

const mockUsePage = vi.fn();
const routerPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockUsePage(),
    Link: ({
        children,
        href,
        ...rest
    }: { children: React.ReactNode; href: string } & Record<
        string,
        unknown
    >) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { post: (...args: unknown[]) => routerPost(...args), get: vi.fn() },
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({
        resolvedAppearance: 'light',
        appearance: 'light',
        updateAppearance: vi.fn(),
    }),
}));

import Sidebar from '@/components/admin/Sidebar';

beforeAll(() => {
    // jsdom has no layout; make visibility checks (getClientRects) pass
    // so focus-restore behaviour is observable.
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([
        { width: 100, height: 40 } as unknown as DOMRect,
    ]);
});

beforeEach(() => {
    vi.clearAllMocks();
    mockUsePage.mockReturnValue({
        url: '/admin/calendar',
        props: {
            auth: {
                user: {
                    name: 'Анна',
                    tariff_name: 'Free',
                    avatar_url: null,
                    can_manage_team: false,
                    can_manage_billing: false,
                },
            },
            appVersion: 'dev',
        },
    });
});

function renderSidebar(
    props: Partial<React.ComponentProps<typeof Sidebar>> = {},
) {
    return render(
        <Sidebar
            collapsed={false}
            onToggleCollapse={vi.fn()}
            mobileOpen={false}
            onMobileClose={vi.fn()}
            {...props}
        />,
    );
}

function getProfileTriggers() {
    // Several triggers share the label (mobile row + desktop avatar/ellipsis);
    // any of them opens the same shared dropdown.
    return screen.getAllByRole('button', { name: 'Меню профиля' });
}

function openProfileMenu() {
    const triggers = getProfileTriggers();
    fireEvent.click(triggers[triggers.length - 1]!);
}

function openLogoutDialog() {
    openProfileMenu();
    // When the mobile sidebar is open, both desktop and mobile copies of the
    // dropdown exist; any «Выйти» entry opens the same shared dialog.
    const items = screen.getAllByRole('menuitem', { name: 'Выйти' });
    fireEvent.click(items[items.length - 1]!);
}

describe('Master logout confirmation dialog', () => {
    it('opening the confirmation sends no POST and shows title with description', () => {
        renderSidebar();
        openLogoutDialog();

        expect(routerPost).not.toHaveBeenCalled();
        expect(
            screen.getByRole('heading', { name: 'Выйти из кабинета?' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Вы сможете войти снова через MAX или ВК.'),
        ).toBeInTheDocument();
        // profile menu is closed
        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('renders exactly one dialog instance even when the mobile sidebar is open', () => {
        renderSidebar({ mobileOpen: true });
        openLogoutDialog();

        expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1);
    });

    it('puts initial focus on «Остаться»', async () => {
        renderSidebar();
        openLogoutDialog();

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: 'Остаться' }),
            ).toHaveFocus();
        });
    });

    it('«Остаться» closes the dialog without POST and restores focus to the profile trigger', async () => {
        renderSidebar();
        const trigger = getProfileTriggers()[getProfileTriggers().length - 1]!;
        openLogoutDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Остаться' }));

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(routerPost).not.toHaveBeenCalled();
        await waitFor(() => {
            expect(trigger).toHaveFocus();
        });
        // focus is not on a remounted «Выйти» item
        expect(document.activeElement?.textContent).not.toBe('Выйти');
    });

    it('Escape closes the dialog without POST', () => {
        renderSidebar();
        openLogoutDialog();

        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('clicking the dimmed backdrop closes the dialog without POST', async () => {
        renderSidebar();
        openLogoutDialog();

        const overlay = document.querySelector('[data-slot="dialog-overlay"]');
        expect(overlay).not.toBeNull();
        // Radix attaches its outside-pointerdown listener on a macrotask
        // after mount and defers the dismiss until the follow-up click.
        await new Promise((resolve) => setTimeout(resolve, 10));
        fireEvent.pointerDown(overlay!);
        fireEvent.click(overlay!);

        await waitFor(() => {
            expect(screen.queryByRole('dialog')).toBeNull();
        });
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('confirming sends exactly one POST /logout with empty payload', () => {
        renderSidebar();
        openLogoutDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Выйти' }));

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost).toHaveBeenCalledWith(
            '/logout',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });

    it('a rapid double-click on confirm still sends exactly one POST', () => {
        renderSidebar();
        openLogoutDialog();

        const confirm = screen.getByRole('button', { name: 'Выйти' });
        fireEvent.click(confirm);
        fireEvent.click(confirm);
        fireEvent.click(confirm);

        expect(routerPost).toHaveBeenCalledTimes(1);
    });

    it('while the request is pending: «Выходим…», disabled buttons, dialog cannot be closed', async () => {
        renderSidebar();
        openLogoutDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Выйти' }));

        const dialog = screen.getByRole('dialog');
        const confirm = within(dialog).getByRole('button', {
            name: 'Выходим…',
        });
        expect(confirm).toBeDisabled();
        expect(
            within(dialog).getByRole('button', { name: 'Остаться' }),
        ).toBeDisabled();

        // close attempts while pending are ignored
        fireEvent.keyDown(document, { key: 'Escape' });
        const overlay = document.querySelector('[data-slot="dialog-overlay"]');
        fireEvent.pointerDown(overlay!);
        fireEvent.click(overlay!);
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        // still exactly one POST
        expect(routerPost).toHaveBeenCalledTimes(1);
    });

    it('on mobile the dialog stays open after the sidebar closes', () => {
        const onMobileClose = vi.fn();
        renderSidebar({ mobileOpen: true, onMobileClose });

        openLogoutDialog();

        expect(onMobileClose).toHaveBeenCalled();
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('on mobile cancel does not return focus into the closed sidebar', async () => {
        let mobileOpen = true;
        const onMobileClose = vi.fn(() => {
            mobileOpen = false;
        });

        const { rerender } = renderSidebar({ mobileOpen, onMobileClose });

        openLogoutDialog();
        rerender(
            <Sidebar
                collapsed={false}
                onToggleCollapse={vi.fn()}
                mobileOpen={mobileOpen}
                onMobileClose={onMobileClose}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Остаться' }));
        rerender(
            <Sidebar
                collapsed={false}
                onToggleCollapse={vi.fn()}
                mobileOpen={mobileOpen}
                onMobileClose={onMobileClose}
            />,
        );

        expect(screen.queryByRole('dialog')).toBeNull();
        await waitFor(() => {
            expect(document.activeElement).not.toBe(null);
        });
        // The mobile trigger unmounted with the sidebar — focus must not land on it.
        const mobileTriggers = screen.queryAllByRole('button', {
            name: 'Меню профиля',
        });

        for (const el of mobileTriggers) {
            expect(el).not.toHaveFocus();
        }
    });

    it('confirm button is orange and has no data-loss scare copy', () => {
        renderSidebar();
        openLogoutDialog();

        const confirm = screen.getByRole('button', { name: 'Выйти' });
        expect(confirm.className).toContain('bg-[var(--color-orange)]');
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).queryByText(/данны/i)).toBeNull();
        expect(within(dialog).queryByText(/невозможно/i)).toBeNull();
        expect(within(dialog).queryByText(/безвозвратн/i)).toBeNull();
    });
});
