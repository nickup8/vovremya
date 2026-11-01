import { describe, it, expect, vi, beforeEach, beforeAll } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import React from 'react';

const toastMock = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
}));

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

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({
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
            flash: { success: null, error: null, message: null, id: null },
        },
    }),
    router: {
        get: vi.fn(),
        post: routerPost,
        put: routerPut,
        patch: vi.fn(),
        delete: routerDelete,
        visit: vi.fn(),
    },
}));

vi.mock('@/layouts/AdminLayout', () => ({
    default: ({
        children,
        headerActions,
    }: {
        children: React.ReactNode;
        headerActions?: React.ReactNode;
    }) => React.createElement('div', null, headerActions, children),
}));

// Drawer/sheet primitives pull ResizeObserver which jsdom lacks
beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

describe('service-catalog — single toast source for Inertia ops', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('create success does not call local toast.success (relies on server flash)', async () => {
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
        const options = routerPost.mock.calls[0][2];

        options.onSuccess?.();
        expect(toastMock.success).not.toHaveBeenCalled();
    });

    it('delete onError still shows local error toast', async () => {
        const { default: ServiceCatalogPage } = await import('@/pages/admin/service-catalog');
        render(React.createElement(ServiceCatalogPage));

        fireEvent.click(screen.getByRole('button', { name: /Действия с услугой/u }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Удалить/u }));
        fireEvent.click(screen.getByRole('button', { name: /^Удалить$/u }));

        await waitFor(() => expect(routerDelete).toHaveBeenCalled());
        const options = routerDelete.mock.calls[0][1];
        options.onError?.({});
        expect(toastMock.error).toHaveBeenCalledWith('Не удалось удалить услугу');
    });
});
