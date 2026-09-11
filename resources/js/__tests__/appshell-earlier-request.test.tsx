import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AppShell } from '@/shared/AppShell';

const mockGetAppointments = vi.fn();

vi.mock('@/shared/api/ApiContext', () => ({
    useApi: () => ({
        getAppointments: mockGetAppointments,
        cancelAppointment: vi.fn(),
        saveEarlierRequest: vi.fn(),
        cancelEarlierRequest: vi.fn(),
    }),
}));

vi.mock('@/shared/platform/PlatformContext', () => ({
    usePlatform: () => ({
        appName: 'test',
        backButton: { show: vi.fn(), hide: vi.fn(), onClick: vi.fn(), offClick: vi.fn() },
        haptic: { impact: vi.fn(), notify: vi.fn() },
        openLink: vi.fn(),
    }),
}));

function makeAppointment(overrides: Record<string, unknown> = {}) {
    return {
        id: 'apt-1',
        service: 'Стрижка',
        price: 1000,
        status: 'booked',
        start_at: '2025-07-01 10:00',
        start_at_human: '1 июля 10:00',
        master: { name: 'Мастер', address: null, phone: null, master_slug: null },
        can_cancel: true,
        autofill_available: true,
        earlier_request: null,
        ...overrides,
    };
}

describe('AppShell — canCreateEarlierRequest', () => {
    it('shows "Хочу раньше" when canCreateEarlierRequest is true', async () => {
        mockGetAppointments.mockResolvedValue([makeAppointment()]);
        render(<AppShell canCreateEarlierRequest />);

        expect(await screen.findByText('Хочу раньше')).toBeInTheDocument();
    });

    it('hides "Хочу раньше" when canCreateEarlierRequest is false', async () => {
        mockGetAppointments.mockResolvedValue([makeAppointment()]);
        render(<AppShell canCreateEarlierRequest={false} />);

        await screen.findByText('Стрижка');
        expect(screen.queryByText('Хочу раньше')).not.toBeInTheDocument();
    });

    it('shows "Хочу раньше" by default (prop omitted)', async () => {
        mockGetAppointments.mockResolvedValue([makeAppointment()]);
        render(<AppShell />);

        expect(await screen.findByText('Хочу раньше')).toBeInTheDocument();
    });

    it('preserves existing earlier_request display when canCreateEarlierRequest is false', async () => {
        mockGetAppointments.mockResolvedValue([
            makeAppointment({
                earlier_request: {
                    id: 'er-1',
                    date_from: '2025-06-20',
                    date_to: '2025-06-25',
                    time_from: '09:00',
                    time_to: '18:00',
                    status: 'active',
                },
                autofill_available: true,
            }),
        ]);
        render(<AppShell canCreateEarlierRequest={false} />);

        expect(await screen.findByText('Ищем время раньше')).toBeInTheDocument();
        expect(screen.getByText('Больше не искать')).toBeInTheDocument();
        expect(screen.getByText('Изменить')).toBeInTheDocument();
    });

    it('preserves stop-search action when canCreateEarlierRequest is false', async () => {
        mockGetAppointments.mockResolvedValue([
            makeAppointment({
                earlier_request: {
                    id: 'er-1',
                    date_from: '2025-06-20',
                    date_to: '2025-06-25',
                    time_from: '09:00',
                    time_to: '18:00',
                    status: 'active',
                },
                autofill_available: false,
            }),
        ]);
        render(<AppShell canCreateEarlierRequest={false} />);

        expect(await screen.findByText('Поиск приостановлен')).toBeInTheDocument();
        expect(screen.getByText('Больше не искать')).toBeInTheDocument();
    });
});
