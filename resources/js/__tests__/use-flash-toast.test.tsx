import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act, render } from '@testing-library/react';
import React, { StrictMode } from 'react';

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
    let flash: Flash = { success: undefined, error: undefined, message: undefined, id: null };
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
            flash = { ...next };
            listeners.forEach((listener) => listener());
        },
        reset: () => {
            flash = { success: undefined, error: undefined, message: undefined, id: null };
        },
    };
});

vi.mock('sonner', () => ({
    toast: Object.assign(toastMock.toast, {
        success: toastMock.success,
        error: toastMock.error,
        info: toastMock.info,
    }),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactLib = await import('react');

    return {
        usePage: () => {
            const flash = ReactLib.useSyncExternalStore(flashStore.subscribe, flashStore.getSnapshot);

            return { props: { flash } };
        },
    };
});

import { useFlashToast, resetFlashToastHandledState } from '@/hooks/use-flash-toast';

function setFlash(next: Flash) {
    act(() => {
        flashStore.setFlash(next);
    });
}

describe('useFlashToast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        flashStore.reset();
        resetFlashToastHandledState();
    });

    it('shows success toast once for a flash event', () => {
        flashStore.setFlash({ success: 'Услуга добавлена', id: 'evt-1' });
        renderHook(() => useFlashToast());

        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('Услуга добавлена');
    });

    it('does not repeat toast on rerender with the same flash id', () => {
        flashStore.setFlash({ success: 'Сохранено', id: 'evt-1' });
        const { rerender } = renderHook(() => useFlashToast());

        expect(toastMock.success).toHaveBeenCalledTimes(1);

        rerender();
        setFlash({ success: 'Сохранено', id: 'evt-1' });
        rerender();

        expect(toastMock.success).toHaveBeenCalledTimes(1);
    });

    it('does not repeat toast when partial reload updates props without a new flash id', () => {
        flashStore.setFlash({ success: 'Сохранено', id: 'evt-1' });
        const { rerender } = renderHook(() => useFlashToast());

        expect(toastMock.success).toHaveBeenCalledTimes(1);

        setFlash({ success: 'Сохранено', id: 'evt-1' });
        rerender();

        expect(toastMock.success).toHaveBeenCalledTimes(1);
    });

    it('shows toast again for sequential events with the same text but different ids', () => {
        flashStore.setFlash({ success: 'Настройки записи сохранены', id: 'evt-1' });
        const { rerender } = renderHook(() => useFlashToast());

        expect(toastMock.success).toHaveBeenCalledTimes(1);

        setFlash({ success: 'Настройки записи сохранены', id: 'evt-2' });
        rerender();

        expect(toastMock.success).toHaveBeenCalledTimes(2);
        expect(toastMock.success).toHaveBeenNthCalledWith(1, 'Настройки записи сохранены');
        expect(toastMock.success).toHaveBeenNthCalledWith(2, 'Настройки записи сохранены');
    });

    it('shows error toast without success toast', () => {
        flashStore.setFlash({ error: 'Ошибка сохранения', id: 'evt-err' });
        renderHook(() => useFlashToast());

        expect(toastMock.error).toHaveBeenCalledTimes(1);
        expect(toastMock.success).not.toHaveBeenCalled();
    });

    it('does not toast when flash content is cleared', () => {
        flashStore.setFlash({ success: 'Было', id: 'evt-1' });
        const { rerender } = renderHook(() => useFlashToast());

        expect(toastMock.success).toHaveBeenCalledTimes(1);

        setFlash({ success: undefined, id: null });
        rerender();

        expect(toastMock.success).toHaveBeenCalledTimes(1);
    });

    it('shows toast for plain message flash', () => {
        flashStore.setFlash({ message: 'Информация', id: 'evt-msg' });
        renderHook(() => useFlashToast());

        expect(toastMock.toast).toHaveBeenCalledTimes(1);
        expect(toastMock.toast).toHaveBeenCalledWith('Информация');
    });

    it('does not double-show under StrictMode with the same flash id', () => {
        flashStore.setFlash({ success: 'Услуга добавлена', id: 'evt-strict' });

        render(
            React.createElement(
                StrictMode,
                null,
                React.createElement(() => {
                    useFlashToast();

                    return null;
                }),
            ),
        );

        expect(toastMock.success).toHaveBeenCalledTimes(1);
    });

    it('does not re-show after unmount → mount with the same flash id', () => {
        flashStore.setFlash({ success: 'Услуга добавлена', id: 'evt-remount' });

        const first = renderHook(() => useFlashToast());
        expect(toastMock.success).toHaveBeenCalledTimes(1);
        first.unmount();

        renderHook(() => useFlashToast());
        expect(toastMock.success).toHaveBeenCalledTimes(1);
    });

    it('shows a new event with the same text after remount when flash.id changes', () => {
        flashStore.setFlash({ success: 'Сохранено', id: 'evt-a' });
        const first = renderHook(() => useFlashToast());
        expect(toastMock.success).toHaveBeenCalledTimes(1);
        first.unmount();

        setFlash({ success: 'Сохранено', id: 'evt-b' });
        renderHook(() => useFlashToast());

        expect(toastMock.success).toHaveBeenCalledTimes(2);
    });
});
