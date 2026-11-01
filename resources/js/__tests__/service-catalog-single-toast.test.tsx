import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import React from 'react';

type Flash = {
    success?: string;
    error?: string;
    message?: string;
    id?: string | null;
};

const toastMock = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    toast: vi.fn(),
}));

const flashStore = vi.hoisted(() => {
    let flash: Flash = { success: null, error: null, message: null, id: null };
    const listeners = new Set<() => void>();

    return {
        getSnapshot: () => flash,
        subscribe: (listener: () => void) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },
        setFlash: (next: Flash) => {
            flash = {
                success: next.success ?? null,
                error: next.error ?? null,
                message: next.message ?? null,
                id: next.id ?? null,
            };
            listeners.forEach((listener) => listener());
        },
        reset: () => {
            flash = { success: null, error: null, message: null, id: null };
        },
    };
});

const { routerPut, routerPost, routerDelete } = vi.hoisted(() => ({
    routerPut: vi.fn(),
    routerPost: vi.fn(),
    routerDelete: vi.fn(),
}));

vi.mock('sonner', () => ({
    toast: Object.assign(vi.fn(), {
        success: toastMock.success,
        error: toastMock.error,
        info: toastMock.info,
    }),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactLib = await import('react');

    return {
        Head: () => null,
        usePage: () => {
            const flash = ReactLib.useSyncExternalStore(flashStore.subscribe, flashStore.getSnapshot);

            return {
                props: {
                    catalog: [
                        {
                            id: 'c1',
                            title: 'Стрижка',
                            category: null,
                            base_price: 1000,
                            base_duration: 60,
                            is_active: true,
                        },
                    ],
                    auth: { user: { name: 'Мастер' } },
                    flash,
                },
            };
        },
        router: {
            get: vi.fn(),
            post: routerPost,
            put: routerPut,
            patch: vi.fn(),
            delete: routerDelete,
            visit: vi.fn(),
        },
    };
});

// AdminLayout mounts <Toaster /> → useFlashToast in production
vi.mock('@/layouts/AdminLayout', () => ({
    default: ({
        children,
        headerActions,
    }: {
        children: React.ReactNode;
        headerActions?: React.ReactNode;
    }) =>
        React.createElement(
            'div',
            { 'data-testid': 'admin-shell' },
            headerActions,
            children,
            React.createElement(FlashBridge),
        ),
}));

import { useFlashToast, resetFlashToastHandledState } from '@/hooks/use-flash-toast';

function FlashBridge() {
    useFlashToast();

    return null;
}

function setFlash(next: Flash) {
    act(() => {
        flashStore.setFlash(next);
    });
}

function completeInertiaVisit(options: Record<string, unknown> | undefined, flash: Flash) {
    (options?.onSuccess as (() => void) | undefined)?.();
    setFlash(flash);
}

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

async function openCreateAndSubmit() {
    const { default: ServiceCatalogPage } = await import('@/pages/admin/service-catalog');
    render(React.createElement(ServiceCatalogPage));

    fireEvent.click(screen.getByRole('button', { name: /Добавить услугу/u }));
    fireEvent.change(screen.getByPlaceholderText('Маникюр + покрытие'), { target: { value: 'Маникюр' } });
    fireEvent.change(screen.getByPlaceholderText('1500'), { target: { value: '1500' } });
    fireEvent.change(screen.getByPlaceholderText('60'), { target: { value: '45' } });

    const submit = screen.getByRole('button', { name: /^Добавить$/u });
    const form = submit.closest('form');

    if (form) {
        fireEvent.submit(form);
    } else {
        fireEvent.click(submit);
    }

    await waitFor(() => expect(routerPost).toHaveBeenCalled());
}

describe('service-catalog — operation → exactly one success toast', () => {
    beforeEach(async () => {
        vi.clearAllMocks();
        flashStore.reset();
        resetFlashToastHandledState();
    });

    it('create: one success toast from server flash, no local toast', async () => {
        await openCreateAndSubmit();

        completeInertiaVisit(routerPost.mock.calls[0][2] as Record<string, unknown>, {
            success: 'Услуга «Маникюр» добавлена в каталог.',
            id: 'evt-create-1',
        });

        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('Услуга «Маникюр» добавлена в каталог.');
        expect(toastMock.error).not.toHaveBeenCalled();
    });

    it('create twice with identical text: one toast per operation', async () => {
        await openCreateAndSubmit();
        completeInertiaVisit(routerPost.mock.calls[0][2] as Record<string, unknown>, {
            success: 'Услуга добавлена',
            id: 'evt-same-1',
        });
        expect(toastMock.success).toHaveBeenCalledTimes(1);

        // Second visit: clear router spies only — keep toast call history and
        // handled-flash state so only a new flash.id can re-notify.
        routerPost.mockClear();
        flashStore.reset();
        document.body.innerHTML = '';

        await openCreateAndSubmit();
        completeInertiaVisit(routerPost.mock.calls[0][2] as Record<string, unknown>, {
            success: 'Услуга добавлена',
            id: 'evt-same-2',
        });

        expect(toastMock.success).toHaveBeenCalledTimes(2);
        expect(toastMock.success).toHaveBeenNthCalledWith(1, 'Услуга добавлена');
        expect(toastMock.success).toHaveBeenNthCalledWith(2, 'Услуга добавлена');
    });

    it('update: one success toast from server flash', async () => {
        const { default: ServiceCatalogPage } = await import('@/pages/admin/service-catalog');
        render(React.createElement(ServiceCatalogPage));

        fireEvent.click(screen.getByRole('button', { name: /Действия с услугой/u }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Редактировать/u }));
        fireEvent.click(screen.getByRole('button', { name: /Сохранить/u }));

        await waitFor(() => expect(routerPut).toHaveBeenCalled());
        completeInertiaVisit(routerPut.mock.calls[0][2] as Record<string, unknown>, {
            success: 'Услуга «Стрижка» обновлена.',
            id: 'evt-update-1',
        });

        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('Услуга «Стрижка» обновлена.');
    });

    it('delete: one success toast from server flash', async () => {
        const { default: ServiceCatalogPage } = await import('@/pages/admin/service-catalog');
        render(React.createElement(ServiceCatalogPage));

        fireEvent.click(screen.getByRole('button', { name: /Действия с услугой/u }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Удалить/u }));
        fireEvent.click(screen.getByRole('button', { name: /^Удалить$/u }));

        await waitFor(() => expect(routerDelete).toHaveBeenCalled());
        completeInertiaVisit(routerDelete.mock.calls[0][1] as Record<string, unknown>, {
            success: 'Услуга «Стрижка» удалена из каталога.',
            id: 'evt-delete-1',
        });

        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.error).not.toHaveBeenCalled();
    });

    it('delete onError shows local error toast without success toast', async () => {
        const { default: ServiceCatalogPage } = await import('@/pages/admin/service-catalog');
        render(React.createElement(ServiceCatalogPage));

        fireEvent.click(screen.getByRole('button', { name: /Действия с услугой/u }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Удалить/u }));
        fireEvent.click(screen.getByRole('button', { name: /^Удалить$/u }));

        await waitFor(() => expect(routerDelete).toHaveBeenCalled());
        const options = routerDelete.mock.calls[0][1] as Record<string, unknown>;
        options.onError?.({});

        expect(toastMock.error).toHaveBeenCalledWith('Не удалось удалить услугу');
        expect(toastMock.success).not.toHaveBeenCalled();
    });

    it('rerender with same flash id does not re-toast', async () => {
        await openCreateAndSubmit();
        completeInertiaVisit(routerPost.mock.calls[0][2] as Record<string, unknown>, {
            success: 'Услуга добавлена',
            id: 'evt-rerender',
        });
        expect(toastMock.success).toHaveBeenCalledTimes(1);

        setFlash({ success: 'Услуга добавлена', id: 'evt-rerender' });

        expect(toastMock.success).toHaveBeenCalledTimes(1);
    });
});
