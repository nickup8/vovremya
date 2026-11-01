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

const { profilePut, bookingPut, useFormCallCount } = vi.hoisted(() => ({
    profilePut: vi.fn(),
    bookingPut: vi.fn(),
    useFormCallCount: { n: 0 },
}));

const profileFormState = {
    data: {
        name: 'Мастер',
        phone: '+79001234567',
        specialty: '',
        address: '',
        master_slug: '',
        telegram_id: '',
        max_id: '',
        telegram_notifications: false,
        max_notifications: false,
    },
    setData: vi.fn(),
    put: profilePut,
    post: vi.fn(),
    processing: false,
    errors: {},
    hasErrors: false,
    reset: vi.fn(),
    clearErrors: vi.fn(),
    transform: vi.fn(),
};

const bookingFormState = {
    data: {
        cancellation_deadline_hours: '24',
        autofill_enabled: false,
        slot_interval: 30,
    },
    setData: vi.fn(),
    put: bookingPut,
    post: vi.fn(),
    processing: false,
    errors: {},
    hasErrors: false,
    reset: vi.fn(),
    clearErrors: vi.fn(),
    transform: vi.fn(),
};

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
        Link: ({ children }: { children?: ReactLib.ReactNode }) =>
            ReactLib.createElement('a', { href: '#' }, children),
        usePage: () => {
            const flash = ReactLib.useSyncExternalStore(flashStore.subscribe, flashStore.getSnapshot);

            return {
                props: {
                    flash,
                    profile: {
                        id: 'u1',
                        name: 'Мастер',
                        phone: '+79001234567',
                        master_slug: null,
                        specialty: null,
                        address: null,
                        avatar_url: null,
                        telegram_id: null,
                        soft_deposit: false,
                        deposit_timeout: 15,
                        deposit_percent: 30,
                        slot_interval: 30,
                        telegram_notifications: false,
                        max_notifications: false,
                        vk_notifications: false,
                        reminder_hours_before_final: 24,
                        cancellation_deadline_hours: 24,
                        autofill_enabled: false,
                        timezone: 'Europe/Moscow',
                        timezone_confirmed: true,
                    },
                    services: [],
                    workingHours: [],
                    blockedTimes: [],
                    auth: { user: { name: 'Мастер' } },
                },
            };
        },
        useForm: (initial?: Record<string, unknown>) => {
            // profileForm has name/master_slug; bookingFlowForm has slot_interval
            if (initial && ('name' in initial || 'master_slug' in initial)) {
                return profileFormState;
            }

            return bookingFormState;
        },
        router: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            reload: vi.fn(),
            visit: vi.fn(),
        },
    };
});

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
            { 'data-testid': 'settings-shell' },
            headerActions,
            children,
            React.createElement(FlashBridge),
        ),
}));

vi.mock('@/components/admin/Sidebar', () => ({ default: () => null }));
vi.mock('@/components/admin/TimezoneConfirmBanner', () => ({ default: () => null }));
vi.mock('react-easy-crop', () => ({ default: () => null }));
vi.mock('@/echo-config', () => ({
    echo: () => ({
        private: () => ({
            listen: () => ({ stopListening: vi.fn() }),
        }),
        leave: vi.fn(),
    }),
}));
vi.mock('lucide-react', async () => {
    const ReactLib = await import('react');
    const StubIcon = () => ReactLib.createElement('svg', { 'data-testid': 'stub-icon' });

    return {
        __esModule: true,
        MessageCircle: StubIcon,
        Pencil: StubIcon,
        Copy: StubIcon,
        Check: StubIcon,
        CheckIcon: StubIcon,
        Lock: StubIcon,
        ChevronDown: StubIcon,
        ChevronDownIcon: StubIcon,
        ChevronUpIcon: StubIcon,
        ChevronLeft: StubIcon,
        ChevronLeftIcon: StubIcon,
        ChevronRight: StubIcon,
        ChevronRightIcon: StubIcon,
        X: StubIcon,
        XIcon: StubIcon,
        Search: StubIcon,
        SearchIcon: StubIcon,
        Loader2: StubIcon,
        Loader2Icon: StubIcon,
        Plus: StubIcon,
        Trash2: StubIcon,
        Globe: StubIcon,
        CalendarDays: StubIcon,
        Share2: StubIcon,
        MapPin: StubIcon,
        Pause: StubIcon,
        Play: StubIcon,
        Repeat: StubIcon,
    };
});
vi.mock('@/components/ui/select', async () => {
    const ReactLib = await import('react');

    const Select = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('div', null, children);
    const SelectTrigger = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('button', { type: 'button' }, children);
    const SelectValue = () => ReactLib.createElement('span', null, '—');
    const SelectContent = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('div', null, children);
    const SelectItem = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('div', null, children);

    return { Select, SelectTrigger, SelectValue, SelectContent, SelectItem };
});
vi.mock('@/components/ui/tabs', async () => {
    const ReactLib = await import('react');

    const Tabs = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('div', null, children);
    const TabsList = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('div', null, children);
    const TabsTrigger = ({ children, onClick, ...rest }: Record<string, unknown>) =>
        ReactLib.createElement(
            'button',
            { type: 'button', onClick, ...rest },
            children as ReactLib.ReactNode,
        );
    const TabsContent = ({ children }: { children?: ReactLib.ReactNode }) =>
        ReactLib.createElement('div', null, children);

    return { Tabs, TabsList, TabsTrigger, TabsContent };
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

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

describe('settings.tsx real submit + shared flash hook', () => {
    beforeEach(async () => {
        vi.clearAllMocks();
        flashStore.reset();
        resetFlashToastHandledState();
        useFormCallCount.n = 0;
    });

    it('profile save: one success toast via flash; local onSuccess does not toast', async () => {
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        render(React.createElement(SettingsPage));

        const saveBtn = screen.getByRole('button', { name: /Сохранить профиль/u });
        const form = saveBtn.closest('form');
        expect(form).toBeTruthy();

        if (form) {
            fireEvent.submit(form);
        } else {
            fireEvent.click(saveBtn);
        }

        await waitFor(() => expect(profilePut).toHaveBeenCalled());
        const options = profilePut.mock.calls[0]![1] as Record<string, unknown>;

        // Local onSuccess must not toast
        (options.onSuccess as (() => void) | undefined)?.();
        expect(toastMock.success).not.toHaveBeenCalled();

        // Server flash (SettingsController:159)
        setFlash({ success: 'Настройки успешно сохранены', id: 'evt-settings-profile' });
        expect(toastMock.success).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('Настройки успешно сохранены');
        expect(toastMock.error).not.toHaveBeenCalled();
    });

    it('booking form real submit: put from settings.tsx has no success toast on validation error', async () => {
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        render(React.createElement(SettingsPage));

        // Booking form is always rendered (TabsContent mock keeps children);
        // submit is initiated by settings.tsx onSubmit → bookingFlowForm.put
        const saveBooking = screen.getByRole('button', { name: /Сохранить настройки/u });
        const bookingForm = saveBooking.closest('form');
        expect(bookingForm).toBeTruthy();

        fireEvent.submit(bookingForm!);

        await waitFor(() => expect(bookingPut).toHaveBeenCalled());
        // Options object created by settings.tsx line 938: { preserveScroll: true }
        const options = bookingPut.mock.calls[0]![1] as Record<string, unknown> | undefined;
        expect(options).toBeTruthy();
        expect(options).not.toHaveProperty('onSuccess');

        // Validation error path: server withErrors — no flash.success, form shows field errors.
        // Local handler must not emit a success toast.
        setFlash({ success: null, error: null, id: null });

        expect(toastMock.success).not.toHaveBeenCalled();
        expect(toastMock.error).not.toHaveBeenCalled();
    });
});
