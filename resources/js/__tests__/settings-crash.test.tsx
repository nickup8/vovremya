import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/react';
import React from 'react';

const mockUsePage = vi.fn();
const mockUseForm = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    useForm: (...args: unknown[]) => mockUseForm(...args),
    usePage: () => mockUsePage(),
}));

vi.mock('@/components/ui/button', () => ({ Button: ({ children, ...p }: any) => React.createElement('button', p, children) }));
vi.mock('@/components/ui/input', () => ({ Input: (p: any) => React.createElement('input', p) }));
vi.mock('@/components/admin/Sidebar', () => ({ default: () => null }));
vi.mock('lucide-react', () => new Proxy({}, { get: () => () => null }));
vi.mock('react-easy-crop', () => ({ default: () => null }));

function makeProps(overrides: Record<string, unknown> = {}) {
    return {
        props: {
            profile: { name: '', phone: null, master_slug: null, specialty: null, address: null, avatar_url: null, telegram_id: null, soft_deposit: false, deposit_timeout: 15, deposit_percent: 30, slot_interval: 30, telegram_notifications: false, max_notifications: false },
            services: [],
            workingHours: [],
            blockedTimes: [],
            auth: { user: { name: 'Test' } },
            ...overrides,
        },
    };
}

describe.skip('admin/settings.tsx — resilience to broken data', () => {
    beforeEach(() => {
        mockUsePage.mockReturnValue(makeProps());
        mockUseForm.mockReturnValue({
            data: {}, setData: vi.fn(), post: vi.fn(), put: vi.fn(),
            processing: false, errors: {}, hasErrors: false, reset: vi.fn(), clearErrors: vi.fn(),
        });
    });

    it('should NOT crash when profile is an empty object', async () => {
        mockUsePage.mockReturnValue(makeProps({ profile: {} }));
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        expect(() => render(React.createElement(SettingsPage))).not.toThrow();
    });

    it('should NOT crash when profile is null', async () => {
        mockUsePage.mockReturnValue(makeProps({ profile: null }));
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        expect(() => render(React.createElement(SettingsPage))).not.toThrow();
    });

    it('should NOT crash when services is null', async () => {
        mockUsePage.mockReturnValue(makeProps({ services: null }));
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        expect(() => render(React.createElement(SettingsPage))).not.toThrow();
    });

    it('should NOT crash when auth is missing', async () => {
        mockUsePage.mockReturnValue(makeProps({ auth: undefined }));
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        expect(() => render(React.createElement(SettingsPage))).not.toThrow();
    });

    it('should render form inputs with safe values for empty profile', async () => {
        mockUsePage.mockReturnValue(makeProps({ profile: {} }));
        const { default: SettingsPage } = await import('@/pages/admin/settings');
        render(React.createElement(SettingsPage));

        const inputs = document.querySelectorAll('input');
        inputs.forEach((input) => {
            const value = input.getAttribute('value');
            if (value !== null) {
                expect(value).not.toContain('undefined');
            }
        });
    });
});
