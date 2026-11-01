import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
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

const { useFormPut } = vi.hoisted(() => ({
    useFormPut: vi.fn(),
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
                    flash,
                    workingHours: [],
                },
            };
        },
        useForm: () => ({
            data: { working_hours: [] },
            setData: vi.fn(),
            put: useFormPut,
            post: vi.fn(),
            transform: vi.fn(),
            processing: false,
            errors: {},
            hasErrors: false,
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }),
        router: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            visit: vi.fn(),
        },
    };
});

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

describe('settings flash text + working-hours card — single toast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        flashStore.reset();
        resetFlashToastHandledState();
    });

    it('booking settings flash: one toast; error flash does not add success', () => {
        render(React.createElement(FlashBridge));

        setFlash({ success: 'Настройки записи сохранены', id: 'evt-booking-1' });
        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('Настройки записи сохранены');

        setFlash({ success: 'Настройки записи сохранены', id: 'evt-booking-1' });
        expect(toastMock.success).toHaveBeenCalledTimes(1);

        setFlash({ success: null, error: 'Ошибка валидации', id: 'evt-booking-err' });
        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.error).toHaveBeenCalledTimes(1);
    });

    it('profile settings flash (SettingsController:159): exactly one success toast', () => {
        render(React.createElement(FlashBridge));

        setFlash({ success: 'Настройки успешно сохранены', id: 'evt-profile-1' });
        expect(toastMock.success).toHaveBeenCalledTimes(1);

        setFlash({ success: 'Настройки успешно сохранены', id: 'evt-profile-2' });
        expect(toastMock.success).toHaveBeenCalledTimes(2);
    });

    it('working hours: local onSuccess does not toast; flash delivers one success toast', async () => {
        const { default: WorkingHoursCard } = await import('@/components/admin/schedule/WorkingHoursCard');

        render(React.createElement(FlashBridge));
        render(
            React.createElement(WorkingHoursCard, {
                workingHours: [
                    {
                        id: 'wh1',
                        day_of_week: 0,
                        start_time: '09:00',
                        end_time: '18:00',
                        break_start_time: '13:00',
                        break_end_time: '14:00',
                        is_working: true,
                    },
                ],
            }),
        );

        // Toggle a day switch to make the form dirty
        const switches = screen.getAllByRole('switch');
        fireEvent.click(switches[0]!);

        const save = screen.getByRole('button', { name: /Сохранить график/u });
        expect(save).toBeEnabled();
        fireEvent.click(save);

        expect(useFormPut).toHaveBeenCalled();
        const call = useFormPut.mock.calls[0]!;
        // Inertia useForm.put(url, options) — options carry onError/onSuccess
        const options = (call.length > 1 ? call[1] : call[0]) as Record<string, unknown>;
        expect(options).toBeTruthy();

        options.onSuccess?.();
        expect(toastMock.success).not.toHaveBeenCalled();

        setFlash({ success: 'График работы обновлён', id: 'evt-wh-1' });
        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('График работы обновлён');
    });

    it('working hours onError: error toast without success toast', async () => {
        const { default: WorkingHoursCard } = await import('@/components/admin/schedule/WorkingHoursCard');

        render(React.createElement(FlashBridge));
        render(React.createElement(WorkingHoursCard, { workingHours: [] }));

        const switches = screen.getAllByRole('switch');
        fireEvent.click(switches[0]!);

        const save = screen.getByRole('button', { name: /Сохранить график/u });
        fireEvent.click(save);

        const call = useFormPut.mock.calls.at(-1)!;
        const options = (call.length > 1 ? call[1] : call[0]) as Record<string, unknown>;
        options.onError?.({ start_time: 'Некорректное время' });

        expect(toastMock.error).toHaveBeenCalledWith('Некорректное время');
        expect(toastMock.success).not.toHaveBeenCalled();
    });
});
