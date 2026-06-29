import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => ({
        props: {},
    }),
}));

vi.mock('@/layouts/PublicLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('lucide-react', () => ({
    Clock: () => <span data-testid="icon-clock" />,
    CheckCircle2: () => <span data-testid="icon-check" />,
    AlertCircle: () => <span data-testid="icon-alert" />,
    Phone: () => <span data-testid="icon-phone" />,
    MapPin: () => <span data-testid="icon-mappin" />,
}));

describe('booking/status.tsx — resilience to broken data', () => {
    it('should NOT crash when appointment is null', async () => {
        const Status = (await import('@/pages/booking/status')).default;

        expect(() => {
            render(<Status appointment={null as any} />);
        }).not.toThrow();
    });

    it('should NOT crash when appointment is undefined', async () => {
        const Status = (await import('@/pages/booking/status')).default;

        expect(() => {
            render(<Status appointment={undefined as any} />);
        }).not.toThrow();
    });

    it('should NOT crash when nested objects are missing', async () => {
        const Status = (await import('@/pages/booking/status')).default;

        const brokenAppointment = {
            id: 1,
            status: 'confirmed',
            start_time: null,
            created_at: null,
            service: null,
            master: null,
        };

        expect(() => {
            render(<Status appointment={brokenAppointment as any} />);
        }).not.toThrow();
    });

    it('should render some visible content even with null appointment', async () => {
        const Status = (await import('@/pages/booking/status')).default;

        render(<Status appointment={null as any} />);

        const body = document.body.textContent || '';
        expect(body.length).toBeGreaterThan(0);
    });
});
