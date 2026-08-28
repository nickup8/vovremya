import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { get: vi.fn(), post: vi.fn() },
}));
vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'light' }),
    initializeTheme: vi.fn(),
}));

import { Toaster } from '@/components/ui/sonner';
import {
    Drawer,
    DrawerTrigger,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
    DrawerDescription,
    DrawerBody,
    DrawerFooter,
    DrawerClose,
} from '@/components/ui/drawer';
import {
    AlertDialog,
    AlertDialogTrigger,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogAction,
    AlertDialogCancel,
} from '@/components/ui/alert-dialog';

// ── Global Toaster ──────────────────────────────────────

describe('Global Toaster', () => {
    it('renders a single Toaster component', () => {
        const { container } = render(<Toaster />);
        // Sonner renders its toaster element — verify the component mounted
        expect(container.firstChild).not.toBeNull();
    });
});

// ── Layout Toaster removal ──────────────────────────────

describe('Layouts do not render their own Toaster', () => {
    it('AdminLayout source does not import Toaster', async () => {
        const source = await import('@/layouts/AdminLayout?raw');
        // Vite ?raw import gives the file content as default string
        const content = typeof source === 'string' ? source : (source as { default: string }).default;
        expect(content).not.toContain('<Toaster');
        expect(content).not.toMatch(/import.*Toaster.*from/);
    });

    it('ClientLayout source does not import Toaster', async () => {
        const source = await import('@/layouts/ClientLayout?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;
        expect(content).not.toContain('<Toaster');
        expect(content).not.toMatch(/import.*Toaster.*from/);
    });

    it('PublicLayout source does not import Toaster', async () => {
        const source = await import('@/layouts/PublicLayout?raw');
        const content = typeof source === 'string' ? source : (source as { default: string }).default;
        expect(content).not.toContain('<Toaster');
        expect(content).not.toMatch(/import.*Toaster.*from/);
    });
});

// ── Flash toast bridge ──────────────────────────────────

describe('Flash toast bridge', () => {
    it('useFlashToast hook exists and is importable', async () => {
        const mod = await import('@/hooks/use-flash-toast');
        expect(typeof mod.useFlashToast).toBe('function');
    });
});

// ── Drawer ──────────────────────────────────────────────

describe('Drawer', () => {
    it('opens and closes via trigger', async () => {
        render(
            <Drawer>
                <DrawerTrigger>Open Drawer</DrawerTrigger>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>Drawer Title</DrawerTitle>
                        <DrawerDescription>Drawer description</DrawerDescription>
                    </DrawerHeader>
                    <DrawerBody>Body content</DrawerBody>
                    <DrawerFooter>
                        <DrawerClose>Close</DrawerClose>
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>,
        );

        const trigger = screen.getByText('Open Drawer');
        fireEvent.click(trigger);

        await waitFor(() => {
            expect(screen.getByText('Drawer Title')).toBeInTheDocument();
            expect(screen.getByText('Body content')).toBeInTheDocument();
        });
    });

    it('closes on Escape key', async () => {
        render(
            <Drawer>
                <DrawerTrigger>Open</DrawerTrigger>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>Escape Test</DrawerTitle>
                    </DrawerHeader>
                    <DrawerBody>Content</DrawerBody>
                </DrawerContent>
            </Drawer>,
        );

        fireEvent.click(screen.getByText('Open'));

        await waitFor(() => {
            expect(screen.getByText('Escape Test')).toBeInTheDocument();
        });

        fireEvent.keyDown(document.activeElement!, { key: 'Escape' });

        await waitFor(() => {
            expect(screen.queryByText('Escape Test')).not.toBeInTheDocument();
        });
    });

    it('has accessible dialog semantics', async () => {
        render(
            <Drawer>
                <DrawerTrigger>Open</DrawerTrigger>
                <DrawerContent>
                    <DrawerHeader>
                        <DrawerTitle>Accessible Title</DrawerTitle>
                        <DrawerDescription>Accessible description</DrawerDescription>
                    </DrawerHeader>
                    <DrawerBody>Content</DrawerBody>
                </DrawerContent>
            </Drawer>,
        );

        fireEvent.click(screen.getByText('Open'));

        await waitFor(() => {
            // Radix Dialog provides role="dialog"
            const dialog = document.querySelector('[role="dialog"]');
            expect(dialog).not.toBeNull();
            expect(dialog).toHaveAttribute('aria-describedby');
        });
    });
});

// ── AlertDialog ─────────────────────────────────────────

describe('AlertDialog', () => {
    it('requires explicit action or cancel to dismiss', async () => {
        const onAction = vi.fn();

        render(
            <AlertDialog>
                <AlertDialogTrigger>Delete</AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                        <AlertDialogDescription>This cannot be undone.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={onAction}>Confirm</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>,
        );

        fireEvent.click(screen.getByText('Delete'));

        await waitFor(() => {
            expect(screen.getByText('Are you sure?')).toBeInTheDocument();
            expect(screen.getByText('Cancel')).toBeInTheDocument();
            expect(screen.getByText('Confirm')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Confirm'));
        expect(onAction).toHaveBeenCalled();
    });

    it('supports destructive action styling', async () => {
        render(
            <AlertDialog>
                <AlertDialogTrigger>Remove</AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirm delete</AlertDialogTitle>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive text-white hover:bg-destructive/90">
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>,
        );

        fireEvent.click(screen.getByText('Remove'));

        await waitFor(() => {
            const action = screen.getByText('Delete');
            expect(action.className).toContain('bg-destructive');
        });
    });
});
